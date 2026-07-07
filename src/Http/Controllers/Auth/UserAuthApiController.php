<?php

namespace Ssntpl\Neev\Http\Controllers\Auth;

use Exception;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Validation\ValidationException;
use Ssntpl\Neev\Events\LoggedOut;
use Ssntpl\Neev\Exceptions\InvalidInvitationException;
use Ssntpl\Neev\Http\Controllers\Controller;
use Ssntpl\Neev\Mail\LoginUsingLink;
use Ssntpl\Neev\Services\MagicLink\MagicLinkManager;
use Ssntpl\Neev\Support\MagicLink\MagicLinkResult;
use Ssntpl\Neev\Mail\VerifyUserEmail;
use Ssntpl\Neev\Models\AccessToken;
use Ssntpl\Neev\Models\LoginAttempt;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\AuthService;
use Ssntpl\Neev\Services\GeoIP;
use Ssntpl\Neev\Services\JwtSecret;
use Ssntpl\Neev\Services\RegistrationService;
use Ssntpl\Neev\Services\SpaCookieResponder;

class UserAuthApiController extends Controller
{
    public function register(Request $request, GeoIP $geoIP)
    {
        try {
            $request->validate(app(RegistrationService::class)->rules());

            $user = app(RegistrationService::class)->register(
                $request->only(['name', 'email', 'password', 'username']),
                $request->invitation_id,
                $request->hash,
            );

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
            return app(SpaCookieResponder::class)->attach($request, response()->json([
                'auth_state' => 'authenticated',
                'token' => $token,
                'expires_in' => $expiryMinutes,
                'mfa_options' => null,
                'email_verified' => $user->hasVerifiedEmail(),
            ]), $expiryMinutes);
        } catch (ValidationException $e) {
            throw $e;
        } catch (InvalidInvitationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        } catch (Exception $e) {
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

            // SPA callers get the short-lived MFA JWT in the cookie; it is
            // replaced by the real login token after OTP verification.
            return app(SpaCookieResponder::class)->attach($request, response()->json([
                'auth_state' => 'mfa_required',
                'token' => $tempToken,
                'expires_in' => $expiryMinutes,
                'mfa_options' => $this->getMfaOptions($user),
                'email_verified' => $user->hasVerifiedEmail(),
            ]), $expiryMinutes);
        }
        $expiryMinutes = config('neev.login_token_expiry_minutes', 1440);
        $token = app(AuthService::class)->createApiToken($request, $geoIP, $user, LoginAttempt::Password, $expiryMinutes);

        return app(SpaCookieResponder::class)->attach($request, response()->json([
            'auth_state' => 'authenticated',
            'token' => $token,
            'expires_in' => $expiryMinutes,
            'mfa_options' => null,
            'email_verified' => $user->hasVerifiedEmail(),
        ]), $expiryMinutes);
    }

    private function getMfaOptions(User $user): array
    {
        return $user->activeMultiFactorAuths()->pluck('method')->values()->all();
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
        $auth = $user->multiFactorAuth('email');
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

        event(new LoggedOut($user));

        return app(SpaCookieResponder::class)->clear($request, response()->json([
            'message' => 'Logged out successfully.',
        ]));
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

        event(new LoggedOut($user));

        // logoutAll keeps the current session, so the SPA cookie stays.
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

            event(new PasswordReset($user));

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

    public function sendLoginLink(Request $request, MagicLinkManager $magicLink)
    {
        $user = User::findByEmail($request->email);
        if (!$user) {
            return response()->json([
                'message' => 'Credentials are wrong.',
            ], 401);
        }

        $channel = (string) $request->input('channel', 'web');

        $link = $magicLink->generate($user, $channel, ['request' => $request]);

        Mail::to($user->email)->send(new LoginUsingLink($link['url'], $link['expires_in']));

        return response()->json([
            'message' => 'Login link has been sent.',
        ]);
    }

    /**
     * Redeem a magic link (login + confirmation share this single route).
     *
     * When confirmation is required, a GET only validates the link (scanner-safe)
     * and returns a "confirmation_required" state; a POST is the user's explicit
     * confirmation, which consumes the link. Otherwise the link is consumed and
     * a login token is issued.
     */
    public function loginUsingLink(Request $request, GeoIP $geoIP, MagicLinkManager $magicLink)
    {
        if (config('neev.magic_link.require_confirmation', false) && $request->isMethod('get')) {
            $result = $magicLink->validate($request);
        } else {
            $result = $magicLink->consume($request);
        }

        return $this->respondToMagicLink($request, $geoIP, $result);
    }

    /**
     * Validate a magic link WITHOUT consuming it.
     *
     * Exposes channel metadata and the "pending confirmation" state so host
     * applications can build confirmation UX. Never authenticates.
     */
    public function validateLoginLink(Request $request, MagicLinkManager $magicLink)
    {
        $result = $magicLink->validate($request);

        return response()->json([
            'status' => $result->status,
            'valid' => $result->isValid(),
            'requires_confirmation' => $result->needsConfirmation(),
            'channel' => $result->channel,
            'email_verified' => $result->user?->hasVerifiedEmail(),
        ]);
    }

    /**
     * Build the JSON response for a magic-link redemption result.
     */
    protected function respondToMagicLink(Request $request, GeoIP $geoIP, MagicLinkResult $result)
    {
        if ($result->needsConfirmation()) {
            return response()->json([
                'auth_state' => 'confirmation_required',
                'channel' => $result->channel,
                'message' => 'Please confirm this login to continue.',
            ]);
        }

        if (!$result->isValid()) {
            // Preserve the historical deactivated-account behaviour (422).
            if ($result->status === MagicLinkResult::INACTIVE_USER) {
                throw ValidationException::withMessages([
                    'email' => 'Your account is deactivated, please contact your admin to activate your account.',
                ]);
            }

            return response()->json([
                'message' => 'Invalid or expired verification link.',
            ], 403);
        }

        $user = $result->user;
        $expiryMinutes = config('neev.login_token_expiry_minutes', 1440);
        $token = app(AuthService::class)->createApiToken($request, $geoIP, $user, LoginAttempt::MagicAuth, $expiryMinutes);

        return app(SpaCookieResponder::class)->attach($request, response()->json([
            'auth_state' => 'authenticated',
            'token' => $token,
            'expires_in' => $expiryMinutes,
            'mfa_options' => null,
            'email_verified' => $user->hasVerifiedEmail(),
        ]), $expiryMinutes);
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

        $token = app(AuthService::class)->createApiToken($request, $geoIP, $user, LoginAttempt::Password, $expiryMinutes, $attempt);

        // Replaces the MFA JWT cookie with the real login token for SPAs.
        return app(SpaCookieResponder::class)->attach($request, response()->json([
            'auth_state' => 'authenticated',
            'token' => $token,
            'expires_in' => $expiryMinutes,
            'mfa_options' => null,
            'email_verified' => $user->hasVerifiedEmail(),
        ]), $expiryMinutes);
    }
}
