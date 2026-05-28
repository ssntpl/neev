<?php

namespace Ssntpl\Neev\Http\Controllers\Auth;

use Exception;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Ssntpl\Neev\Events\LoggedOutEvent;
use Ssntpl\Neev\Http\Controllers\Controller;
use Ssntpl\Neev\Mail\LoginUsingLink;
use Ssntpl\Neev\Mail\VerifyUserEmail;
use Ssntpl\Neev\Models\AccessToken;
use Ssntpl\Neev\Models\Domain;
use Ssntpl\Neev\Models\LoginAttempt;
use Ssntpl\Neev\Models\MultiFactorAuth;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\TeamInvitation;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\AuthService;
use Ssntpl\Neev\Services\GeoIP;
use Ssntpl\Neev\Services\JwtSecret;

class UserAuthApiController extends Controller
{
    public function register(Request $request, GeoIP $geoIP)
    {
        $validationRules = [
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'email', 'max:255', User::uniqueEmailRule()],
            'password' => config('neev.password'),
        ];

        if (config('neev.support_username')) {
            $validationRules['username'] = config('neev.username');
        }

        try {
            $request->validate($validationRules);
            DB::beginTransaction();

            $userData = [
                'name' => $request->name,
                'email' => $request->email,
                'password' => $request->password,
                'password_changed_at' => now(),
            ];

            if (config('neev.support_username')) {
                $userData['username'] = $request->username;
            }

            $user = User::model()->forceCreate($userData);
            $user = User::model()->find($user->id);

            if (config('neev.team')) {
                if ($request->invitation_id) {
                    $invitation = TeamInvitation::find($request->invitation_id);
                    if (!$invitation || !hash_equals(sha1($invitation->email), $request->hash)) {
                        DB::rollBack();
                        return response()->json([
                            'message' => 'Invalid or expired invitation link.',
                        ], 400);
                    }

                    $user->markEmailAsVerified();

                    $team = $invitation->team;
                    $team->users()->attach($user, ['joined' => true]);
                    if ($invitation->role) {
                        $user->assignRole($invitation->role, $team);
                    }
                    $invitation->delete();
                } else {
                    $shouldCreateTeam = !$this->isDomainVerified($request->email);

                    if ($shouldCreateTeam) {
                        $team = Team::model()->forceCreate([
                            'name' => explode(' ', $user->name, 2)[0] . "'s Team",
                            'user_id' => $user->id,
                            'is_public' => false,
                            'activated_at' => now(),
                        ]);
                        $team->addMember($user);
                    }
                }
            }
            DB::commit();

            if (!$user->hasVerifiedEmail()) {
                app(AuthService::class)->sendEmailVerification($user);
            }

            $expiryMinutes = config('neev.login_token_expiry_minutes', 1440);
            $token = app(AuthService::class)->createApiToken($request, $geoIP, $user, LoginAttempt::Password, $expiryMinutes);
            if (!$token) {
                return response()->json([
                    'message' => 'Something went wrong.',
                ], 500);
            }
            return response()->json([
                'auth_state' => 'authenticated',
                'token' => $token,
                'expires_in' => $expiryMinutes,
                'mfa_options' => null,
                'email_verified' => $user->hasVerifiedEmail(),
            ]);
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (Exception $e) {
            DB::rollBack();
            Log::error($e);
            return response()->json([
                'message' => 'Unable to register user.',
            ], 500);
        }
    }

    public function login(Request $request, GeoIP $geoIP)
    {
        if (config('neev.support_username') && !preg_match('/^[\w.%+\-]+@[\w.\-]+\.[A-Za-z]{2,}$/', $request->email)) {
            $user = User::findByUsername($request->email);
            if ($user) {
                $request->merge(['username' => $user->username]);
                $request->merge(['email' => $user->email]);
            }
        }

        $user = User::findByEmail($request->email);
        if (!$user) {
            return response()->json([
                'message' => 'Credentials are wrong.',
            ], 401);
        }

        $mfaMethod = $user->preferredMultiFactorAuth->method ?? $user->activeMultiFactorAuths()->first()?->method;
        if (!Hash::check($request->password, $user->password)) {
            if (config('neev.log_failed_logins')) {
                $clientDetails = LoginAttempt::getClientDetails($request);
                $user->loginAttempts()->create([
                    'method' => LoginAttempt::Password,
                    'location' => $geoIP->getLocation($request->ip()),
                    'multi_factor_method' => $mfaMethod ?? null,
                    'platform' => $clientDetails['platform'] ?? '',
                    'browser' => $clientDetails['browser'] ?? '',
                    'device' => $clientDetails['device'] ?? '',
                    'ip_address' => $request->ip(),
                    'is_success' => false,
                ]);
            }
            return response()->json([
                'message' => 'Credentials are wrong.',
            ], 401);
        }
        if ($mfaMethod) {
            // Record login attempt (MFA pending)
            $clientDetails = LoginAttempt::getClientDetails($request);
            $attempt = $user->loginAttempts()->create([
                'method' => LoginAttempt::Password,
                'location' => $geoIP->getLocation($request->ip()),
                'multi_factor_method' => $mfaMethod,
                'platform' => $clientDetails['platform'] ?? '',
                'browser' => $clientDetails['browser'] ?? '',
                'device' => $clientDetails['device'] ?? '',
                'ip_address' => $request->ip(),
                'is_success' => false,
            ]);

            if ($mfaMethod === 'email') {
                $this->sendMfaEmailOTP($user);
            }

            $expiryMinutes = config('neev.mfa_jwt_expiry_minutes', 30);
            $expirySeconds = $expiryMinutes * 60;
            $tempToken = $this->getJwtToken($user->id, "mfa", $expirySeconds, [
                'attempt_id' => $attempt->id
            ]);

            return response()->json([
                'auth_state' => 'mfa_required',
                'token' => $tempToken,
                'expires_in' => $expiryMinutes,
                'mfa_options' => $this->getMfaOptions($user),
                'email_verified' => $user->hasVerifiedEmail(),
            ]);
        }
        $expiryMinutes = config('neev.login_token_expiry_minutes', 1440);
        $token = app(AuthService::class)->createApiToken($request, $geoIP, $user, LoginAttempt::Password, $expiryMinutes);

        return response()->json([
            'auth_state' => 'authenticated',
            'token' => $token,
            'expires_in' => $expiryMinutes,
            'mfa_options' => null,
            'email_verified' => $user->hasVerifiedEmail(),
        ]);
    }

    private function getMfaOptions(User $user): array
    {
        $user->loadMissing('activeMultiFactorAuths');
        return $user->activeMultiFactorAuths->pluck('method')->values()->all();
    }

    private function getJwtToken(int $userId, string $type, int $ttlSeconds, array $extraClaims = []): string
    {
        $now = time();
        $payload = array_merge([
            'jti' => Str::uuid()->toString(),
            'user_id' => $userId,
            'type' => $type,
            'iat' => $now,
            'exp' => $now + $ttlSeconds,
        ], $extraClaims);
        return JWT::encode($payload, JwtSecret::get(), 'HS256');
    }

    private function sendMfaEmailOTP(User $user): void
    {
        $auth = $user->multiFactorAuth('email', MultiFactorAuth::STATUS_ACTIVE);
        if (!$auth) {
            return;
        }
        $otp = random_int(10 ** (config('neev.otp_length', 6) - 1), (10 ** config('neev.otp_length', 6)) - 1);
        $expiryMinutes = config('neev.otp_expiry_time', 15);
        $auth->otp = $otp;
        $auth->expires_at = now()->addMinutes($expiryMinutes);
        $auth->save();
        Mail::to($user->email)->send(new \Ssntpl\Neev\Mail\EmailOTP($user->name, $otp, $expiryMinutes));
    }

    public function sendMailVerificationLink(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'message' => 'User not found.',
            ], 404);
        }
        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email already verified.',
            ], 400);
        }

        $expiryMinutes = config('neev.url_expiry_time', 60);
        $signedUrl = URL::temporarySignedRoute(
            'mail.verify',
            now()->addMinutes($expiryMinutes),
            ['id' => $user->id, 'hash' => hash('sha256', $user->email)]
        );

        $query = parse_url($signedUrl, PHP_URL_QUERY);
        $frontendUrl = config('app.url');
        $url = "{$frontendUrl}/verify-email?{$query}";
        Mail::to($user->email)->send(new VerifyUserEmail($url, $user->name, 'Verify Email', $expiryMinutes));

        return response()->json([
            'message' => 'Verification link has been sent.',
        ]);
    }

    public function logout(Request $request)
    {
        $accessToken = AccessToken::find($request->attributes->get('token_id'));
        if (!$accessToken || $accessToken->user?->id !== $request->user()?->id) {
            return response()->json([
                'message' => 'Invalid Token.',
            ], 401);
        }

        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'message' => 'Invalid Token.',
            ], 401);
        }

        $accessToken->delete();

        event(new LoggedOutEvent($user));

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    public function logoutAll(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'message' => 'Invalid Token.',
            ], 401);
        }

        $currentTokenId = $request->attributes->get('token_id');
        $user->loginTokens()->where('id', '!=', $currentTokenId)->delete();

        event(new LoggedOutEvent($user));

        return response()->json([
            'message' => 'Logged out from all other devices successfully.',
        ]);
    }

    public function emailVerify(Request $request)
    {
        $user = User::model()->find($request->id);
        if (!$request->hasValidSignature()
            || !$user
            || $user->id != $request->user()?->id
            || !hash_equals(hash('sha256', $user->email), (string) $request->hash)) {
            return response()->json([
                'message' => 'Invalid or expired verification link.'
            ], 403);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email verification already done.'
            ]);
        }

        $user->markEmailAsVerified();

        return response()->json([
            'message' => 'Email verification done.'
        ]);
    }

    public function forgotPassword(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|string|email|max:255',
            ]);

            $user = User::findByEmail($request->email);
            if (!$user || !$user->hasVerifiedEmail()) {
                return response()->json([
                    'message' => 'User not registered or wrong email.',
                ], 404);
            }

            $expiryMinutes = config('neev.url_expiry_time', 60);
            $signedUrl = URL::temporarySignedRoute(
                'neev.resetPassword',
                now()->addMinutes($expiryMinutes),
                ['id' => $user->id, 'hash' => hash('sha256', $user->email)]
            );

            $query = parse_url($signedUrl, PHP_URL_QUERY);
            $frontendUrl = config('app.url');
            $url = "{$frontendUrl}/reset-password?{$query}";
            Mail::to($user->email)->send(new VerifyUserEmail($url, $user->name, 'Reset Password', $expiryMinutes));

            return response()->json([
                'message' => 'Password reset link has been sent to your email.'
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'message' => 'Password reset failed.',
            ], 500);
        }
    }

    public function resetPassword(Request $request)
    {
        try {
            if (!$request->hasValidSignature()) {
                return response()->json([
                    'message' => 'Invalid or expired reset link.',
                ], 403);
            }

            $user = User::model()->find($request->id);
            if (!$user
                || !$user->hasVerifiedEmail()
                || !hash_equals(hash('sha256', $user->email), (string) $request->hash)) {
                return response()->json([
                    'message' => 'Invalid or expired reset link.',
                ], 403);
            }

            $request->validate([
                'password' => config('neev.password'),
            ]);

            app(AuthService::class)->changePassword($user, $request->password);

            return response()->json([
                'message' => 'Password has been updated.'
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'message' => 'Password reset failed.',
            ], 500);
        }
    }

    public function sendLoginLink(Request $request)
    {
        $user = User::findByEmail($request->email);
        if (!$user) {
            return response()->json([
                'message' => 'Credentials are wrong.',
            ], 401);
        }

        $expiryMinutes = config('neev.url_expiry_time', 60);
        $signedUrl = URL::temporarySignedRoute(
            'loginUsingLink',
            now()->addMinutes($expiryMinutes),
            ['id' => $user->id]
        );

        $query = parse_url($signedUrl, PHP_URL_QUERY);
        $frontendUrl = config('app.url');
        $url = "{$frontendUrl}/login-link?{$query}";

        Mail::to($user->email)->send(new LoginUsingLink($url, $expiryMinutes));

        return response()->json([
            'message' => 'Login link has been sent.',
        ]);
    }

    public function loginUsingLink(Request $request, GeoIP $geoIP)
    {
        if (! $request->hasValidSignature()) {
            return response()->json([
                'message' => 'Invalid or expired verification link.',
            ], 403);
        }

        $user = User::model()->find($request->id);
        if (!$user || !$user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Invalid or expired verification link.',
            ], 403);
        }

        $expiryMinutes = config('neev.login_token_expiry_minutes', 1440);
        $token = app(AuthService::class)->createApiToken($request, $geoIP, $user, LoginAttempt::MagicAuth, $expiryMinutes);

        return response()->json([
            'auth_state' => 'authenticated',
            'token' => $token,
            'expires_in' => $expiryMinutes,
            'mfa_options' => null,
            'email_verified' => $user->hasVerifiedEmail(),
        ]);
    }

    public function requestEmailChange(Request $request)
    {
        try {
            $request->validate([
                'email' => ['required', 'string', 'email', 'max:255', User::uniqueEmailRule()],
                'password' => ['required'],
            ]);

            $user = User::model()->find($request->user()?->id);
            if (!$user) {
                return response()->json([
                    'message' => 'User not found.',
                ], 404);
            }

            if (!Hash::check($request->password, $user->password)) {
                return response()->json([
                    'message' => 'Password is incorrect.',
                ], 403);
            }

            if ($request->email === $user->email) {
                return response()->json([
                    'message' => 'New email must be different from current email.',
                ], 400);
            }

            $expiryMinutes = config('neev.url_expiry_time', 60);
            $signedUrl = URL::temporarySignedRoute(
                'neev.email.change.verify',
                now()->addMinutes($expiryMinutes),
                ['id' => $user->id, 'email' => $request->email]
            );

            $query = parse_url($signedUrl, PHP_URL_QUERY);
            $frontendUrl = config('app.url');
            $url = "{$frontendUrl}/verify-email-change?{$query}";
            Mail::to($request->email)->send(new VerifyUserEmail($url, $user->name, 'Verify Email Change', $expiryMinutes));

            return response()->json([
                'message' => 'Verification link has been sent to your new email address.',
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'message' => 'Unable to process email change.',
            ], 500);
        }
    }

    public function verifyEmailChange(Request $request)
    {
        if (!$request->hasValidSignature()) {
            return response()->json([
                'message' => 'Invalid or expired verification link.',
            ], 403);
        }

        $user = User::model()->find($request->id);
        if (!$user) {
            return response()->json([
                'message' => 'Invalid or expired verification link.',
            ], 403);
        }

        $newEmail = $request->email;
        if (!$newEmail) {
            return response()->json([
                'message' => 'Invalid or expired verification link.',
            ], 403);
        }

        if (!app(AuthService::class)->applyEmailChange($user, $newEmail)) {
            return response()->json([
                'message' => 'This email address is already in use.',
            ], 409);
        }

        return response()->json([
            'message' => 'Email address has been updated and verified.',
        ]);
    }

    private function isDomainVerified(string $email): bool
    {
        $emailDomain = substr(strrchr($email, "@"), 1);
        $domain = Domain::where('domain', $emailDomain)->first();

        return $domain?->verified_at !== null;
    }

    public function verifyMFAOTP(Request $request, GeoIP $geoIP)
    {
        $request->validate([
            'otp' => 'required',
            'auth_method' => 'required|string',
        ]);

        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Credentials are wrong.',
            ], 403);
        }

        $authMethod = $request->auth_method;
        $availableMethods = $this->getMfaOptions($user);
        if ($authMethod !== 'recovery' && !in_array($authMethod, $availableMethods, true)) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Invalid auth method.',
            ], 400);
        }

        $verified = false;
        if ($authMethod === 'recovery') {
            $matched = $user->recoveryCodes->first(fn ($c) => $c->verify($request->otp));
            if ($matched) {
                $matched->delete();
                $verified = true;
            }
        } else {
            $auth = $user->multiFactorAuth($authMethod, MultiFactorAuth::STATUS_ACTIVE);
            $verified = (bool) $auth?->verifyOTP($request->otp);
        }

        if (!$verified) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Code verification failed.'
            ], 400);
        }

        $expiryMinutes = config('neev.login_token_expiry_minutes', 1440);
        $claims = (array) $request->attributes->get('jwt_claims', []);
        $attemptId = $claims['attempt_id'] ?? null;
        $attempt = $attemptId ? $user->loginAttempts()->find($attemptId) : null;
        if ($attempt) {
            $attempt->is_success = true;
            $attempt->multi_factor_method = $request->auth_method;
            $attempt->save();
        }

        $token = app(AuthService::class)->createApiToken($request, $geoIP, $user, LoginAttempt::Password, $expiryMinutes, $attempt);

        return response()->json([
            'auth_state' => 'authenticated',
            'token' => $token,
            'expires_in' => $expiryMinutes,
            'mfa_options' => null,
            'email_verified' => $user->hasVerifiedEmail(),
        ]);
    }

    public function verifyMfaSetupOtp(Request $request)
    {
        $request->validate([
            'otp' => 'required',
            'auth_method' => 'required|string',
        ]);

        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'User not found.',
            ], 403);
        }

        $pending = $user->pendingMultiFactorAuths->where('method', $request->auth_method)->first();
        if (!$pending) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'No pending setup found. Please start setup again.',
            ], 400);
        }

        if (!$pending->verifyOTP($request->otp)) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Code verification failed.',
            ], 400);
        }

        return response()->json([
            'status' => 'Success',
            'method' => $request->auth_method,
            'message' => 'MFA enabled successfully.',
        ]);
    }
}
