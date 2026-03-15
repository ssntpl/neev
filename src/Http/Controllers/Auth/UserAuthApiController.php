<?php

namespace Ssntpl\Neev\Http\Controllers\Auth;

use Exception;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Ssntpl\Neev\Events\LoggedInEvent;
use Ssntpl\Neev\Events\LoggedOutEvent;
use Ssntpl\Neev\Http\Controllers\Controller;
use Ssntpl\Neev\Http\Controllers\UserApiController;
use Ssntpl\Neev\Mail\LoginUsingLink;
use Ssntpl\Neev\Models\AccessToken;
use Ssntpl\Neev\Models\Domain;
use Ssntpl\Neev\Models\Email;
use Ssntpl\Neev\Models\LoginAttempt;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\TeamInvitation;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\GeoIP;
use Ssntpl\Neev\Services\JwtSecret;

class UserAuthApiController extends Controller
{
    public function register(Request $request, GeoIP $geoIP)
    {
        $validationRules = [
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'email', 'max:255', Email::uniqueRule()],
            'password' => config('neev.password'),
        ];

        if (config('neev.support_username')) {
            $validationRules['username'] = config('neev.username');
        }

        try {
            $request->validate($validationRules);
            DB::beginTransaction();
            $userData = ['name' => $request->name];
            if (config('neev.support_username')) {
                $userData['username'] = $request->username;
            }
            $user = User::model()->create($userData);

            $user = User::model()->find($user->id);

            $email = $user->emails()->create([
                'email' => $request->email,
                'is_primary' => true
            ]);

            $user->passwords()->create([
                'password' => Hash::make($request->password),
            ]);

            if (config('neev.team')) {
                if ($request->invitation_id) {
                    $invitation = TeamInvitation::find($request->invitation_id);
                    if (!$invitation || !hash_equals(sha1($invitation->email), $request->hash)) {
                        DB::rollBack();
                        return response()->json([
                            'message' => 'Invalid or expired invitation link.',
                        ], 400);
                    }

                    $email->verified_at = now();
                    $email->save();

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
            if (!$email->verified_at) {
                $result = UserApiController::sendMailVerification($email);
                $verificationMethod = $result['method'] ?? 'link';
            } else {
                $verificationMethod = null;
            }

            $expiryMinutes = config('neev.login_token_expiry_minutes', 1440);
            $token = $this->getToken($request, $geoIP, $user, LoginAttempt::Password, $expiryMinutes);
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
                'email_verification_method' => $verificationMethod,
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
                $request->merge(['email' => $user->email?->email]);
            }
        }

        $email = Email::findByEmail($request->email);
        $user = $email?->user;
        if (!$user) {
            return response()->json([
                'message' => 'Credentials are wrong.',
            ], 401);
        }

        $mfaMethod = $user->preferredMultiFactorAuth->method ?? $user->multiFactorAuths()->first()?->method;
        if (!Hash::check($request->password, (string)$user->password?->password)) {
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
                UserApiController::sendMailOTP($user->email, true);
            }

            $expiryMinutes = config('neev.mfa_jwt_expiry_minutes', 30);
            $expirySeconds = $expiryMinutes * 60;
            $tempToken = $this->getJwtToken($user->id, "mfa", $expirySeconds, [
                'attempt_id' => $attempt?->id
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
        $token = $this->getToken(request: $request, geoIP: $geoIP, user: $user, method: LoginAttempt::Password, expiryMinutes: $expiryMinutes, attempt: null);

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
        $user->loadMissing('multiFactorAuths');
        return $user->multiFactorAuths->pluck('method')->values()->all();
    }

    private function getJwtToken(int $userId, string $type, int $ttlSeconds, array $extraClaims = []): string
    {
        $now = time();
        $payload = array_merge([
            'user_id' => $userId,
            'type' => $type,
            'iat' => $now,
            'exp' => $now + $ttlSeconds,
        ], $extraClaims);
        return JWT::encode($payload, JwtSecret::get(), 'HS256');
    }

    public function getToken(Request $request, GeoIP $geoIP, $user, $method, int $expiryMinutes, $attempt = null)
    {
        if (!$user->active) {
            throw ValidationException::withMessages([
                'email' => 'Your account is deactivated, please contact your admin to activate your account.',
            ]);
        }

        try {
            if ($attempt) {
                $attempt->is_success = true;
                $attempt->save();
            } else {
                $clientDetails = LoginAttempt::getClientDetails($request);
                $attempt = $user->loginAttempts()->create([
                    'method' => $method,
                    'location' => $geoIP->getLocation($request->ip()),
                    'multi_factor_method' => null,
                    'platform' => $clientDetails['platform'] ?? '',
                    'browser' => $clientDetails['browser'] ?? '',
                    'device' => $clientDetails['device'] ?? '',
                    'ip_address' => $request->ip(),
                    'is_success' => true,
                ]);
            }

            $token = $user->createLoginToken($expiryMinutes);
            $accessToken = $token->accessToken;
            $accessToken->attempt_id = $attempt->id;
            $accessToken->save();

            event(new LoggedInEvent($user));

            return $token->plainTextToken;
        } catch (Exception $e) {
            Log::error($e);
            return null;
        }
    }

    public function sendMailVerificationLink(Request $request)
    {
        $email = Email::findByEmail($request->email);
        if (!$email) {
            return response()->json([
                'message' => 'Email not found.',
            ], 404);
        }
        if ($email->verified_at) {
            return response()->json([
                'message' => 'Email already verified.',
            ], 400);
        }

        UserApiController::sendMailLink($email);

        return response()->json([
            'message' => 'Verification link has been sent.',
            'verification_method' => 'link'
        ]);
    }

    public function logout(Request $request)
    {
        $accessToken = AccessToken::find($request->attributes->get('token_id'));
        if (!$accessToken || $accessToken->user?->id !== $request->user()?->id) {
            return response()->json([
                'message' => 'Invalid Token.',
            ]);
        }

        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'message' => 'Invalid Token.',
            ]);
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
            ]);
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
        $email = Email::find($request->id);
        if (!$request->hasValidSignature() || !$email || $email->user?->id != $request->user()?->id) {
            return response()->json([
                'message' => 'Invalid or expired verification link.'
            ], 403);
        }

        if ($email->verified_at) {
            return response()->json([
                'message' => 'Email verification already done.'
            ]);
        }

        $email->verified_at = now();
        $email->save();

        return response()->json([
            'message' => 'Email verification done.'
        ]);
    }

    public function sendEmailOTP(Request $request)
    {
        $email = Email::findByEmail($request->email);
        if (!$email) {
            return response()->json([
                'message' => 'Email not found.',
            ], 404);
        }

        UserApiController::sendMailOTP($email, $request->mfa ?? false);

        return response()->json([
            'message' => 'Verification code has been sent to your email.',
            'verification_method' => 'otp'
        ]);
    }

    public function verifyEmailOTP(Request $request)
    {
        $email = Email::findByEmail($request->email);

        $otp = $email?->otp;

        if (!$email || !$otp || $otp->expires_at < now() || !Hash::check((string) $request->otp, $otp->otp)) {
            return response()->json([
                'message' => 'Code verification failed.'
            ], 400);
        }

        // Mark email as verified if this is for email verification
        if (!$request->mfa) {
            $email->verified_at = now();
            $email->save();
            $email->otp()->delete();
        }

        return response()->json([
            'message' => 'Verification code has been verified.',
            'verification_method' => 'otp'
        ]);
    }

    public function forgotPassword(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|string|email|max:255',
                'password' => config('neev.password'),
                'otp' => 'required',
            ]);

            $email = Email::findByEmail($request->email);
            if (!$email) {
                return response()->json([
                    'message' => 'Email not found',
                ], 404);
            }

            $otp = $email->otp;
            if (!$otp || $otp->expires_at < now()) {
                $email->otp()->delete();
                return response()->json([
                    'message' => 'Code verification failed.',
                ], 400);
            }
            if (!Hash::check((string) $request->otp, $otp->otp)) {
                return response()->json([
                    'message' => 'Code verification failed.',
                ], 400);
            }

            $user = $email->user;
            if (!$user) {
                return response()->json([
                    'message' => 'User not found',
                ], 404);
            }
            $user->passwords()->create([
                'password' => Hash::make($request->password),
            ]);

            $email->otp()->delete();

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
        $email = Email::findByEmail($request->email);
        if (!$email) {
            return response()->json([
                'message' => 'Credentials are wrong.',
            ], 401);
        }

        $expiryMinutes = config('neev.url_expiry_time', 60);
        $signedUrl = URL::temporarySignedRoute(
            'loginUsingLink',
            now()->addMinutes($expiryMinutes),
            ['id' => $email->id]
        );

        $query = parse_url($signedUrl, PHP_URL_QUERY);
        $frontendUrl = config('app.url');
        $url = "{$frontendUrl}/login-link?{$query}";

        Mail::to($email->email)->send(new LoginUsingLink($url, $expiryMinutes));

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

        $email = Email::find($request->id);
        if (!$email) {
            return response()->json([
                'message' => 'Invalid or expired verification link.',
            ], 403);
        }

        $expiryMinutes = config('neev.login_token_expiry_minutes', 1440);
        $token = $this->getToken(request: $request, geoIP: $geoIP, user: $email->user, method: LoginAttempt::MagicAuth, expiryMinutes: $expiryMinutes);

        return response()->json([
            'auth_state' => 'authenticated',
            'token' => $token,
            'expires_in' => $expiryMinutes,
            'mfa_options' => null,
            'email_verified' => $email->user?->hasVerifiedEmail() ?? false,
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
                'message' => 'Credentials are wrong.',
            ], 403);
        }

        $authMethod = $request->auth_method;
        $availableMethods = $this->getMfaOptions($user);
        if ($authMethod !== 'recovery' && !in_array($authMethod, $availableMethods, true)) {
            return response()->json([
                'message' => 'Invalid auth method.',
            ], 400);
        }

        if (!$user->verifyMFAOTP($authMethod, $request->otp)) {
            return response()->json([
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

        $token = $this->getToken(request: $request, geoIP: $geoIP, user: $user, method: LoginAttempt::Password, expiryMinutes: $expiryMinutes, attempt: $attempt);

        return response()->json([
            'auth_state' => 'authenticated',
            'token' => $token,
            'expires_in' => $expiryMinutes,
            'mfa_options' => null,
            'email_verified' => $user->hasVerifiedEmail(),
        ]);
    }
}
