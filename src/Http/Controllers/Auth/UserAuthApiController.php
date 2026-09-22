<?php

namespace Ssntpl\Neev\Http\Controllers\Auth;

use Exception;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Ssntpl\Neev\Events\LoggedOut;
use Ssntpl\Neev\Exceptions\InvalidInvitationException;
use Ssntpl\Neev\Exceptions\MagicLinkBindingException;
use Ssntpl\Neev\Exceptions\MagicLinkThrottledException;
use Ssntpl\Neev\Exceptions\MagicLinkChannelException;
use Ssntpl\Neev\Http\Controllers\Controller;
use Ssntpl\Neev\Mail\LoginUsingLink;
use Ssntpl\Neev\Services\MagicLink\MagicLinkManager;
use Ssntpl\Neev\Support\MagicLink\MagicLinkResult;
use Ssntpl\Neev\Mail\VerifyUserEmail;
use Ssntpl\Neev\Models\AccessToken;
use Ssntpl\Neev\Models\LoginAttempt;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\AuthService;
use Ssntpl\Neev\Services\EmailLinks;
use Ssntpl\Neev\Services\GeoIP;
use Ssntpl\Neev\Services\MfaJwt;
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
                $request->token,
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
            return $this->mfaChallenge($request, $geoIP, $user, LoginAttempt::Password, $mfaMethod);
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

    /**
     * Park a login at the MFA step: record the attempt as pending, send the
     * emailed code if that is the method, and hand back the short-lived MFA
     * JWT that `POST {prefix}/mfa/otp/verify` accepts. Shared by every first
     * factor — password, magic link and OAuth alike — so none of them can skip
     * the second.
     */
    public function mfaChallenge(Request $request, GeoIP $geoIP, User $user, string $loginMethod, string $mfaMethod)
    {
        // The same refusal AuthService::createApiToken() gives a deactivated
        // account at the end of the flow, given here at the start — before an
        // attempt is recorded, a code is mailed, or a JWT is minted for it.
        if (!$user->active) {
            throw ValidationException::withMessages([
                'email' => 'Your account is deactivated, please contact your admin to activate your account.',
            ]);
        }

        $clientDetails = LoginAttempt::getClientDetails($request);
        $attempt = $user->loginAttempts()->create([
            'method' => $loginMethod,
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

        $mfaJwt = app(MfaJwt::class);
        $expiryMinutes = $mfaJwt->expiryMinutes();
        $tempToken = $mfaJwt->issue($user, $attempt->id);

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

    private function getMfaOptions(User $user): array
    {
        return $user->activeMultiFactorAuths()->pluck('method')->values()->all();
    }

    private function sendMfaEmailOTP(User $user, bool $force = false): void
    {
        app(AuthService::class)->sendMfaEmailCode($user, $force);
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

        app(AuthService::class)->sendEmailVerification($user);

        return response()->json([
            'message' => 'Verification link has been sent.',
        ]);
    }

    public function verifyEmailOtp(Request $request)
    {
        $request->validate([
            'otp' => ['required'],
        ]);

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

        if (!app(AuthService::class)->verifyEmailOtp($user, (string) $request->otp)) {
            return response()->json([
                'message' => 'Code verification failed.',
            ], 400);
        }

        return response()->json([
            'message' => 'Email verification done.',
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

        // Revoking every other token is a takeover tool as much as a remedy,
        // so it is confirmed — see AuthService::confirmationRules().
        $auth = app(AuthService::class);
        $request->validate($auth->confirmationRules($user));

        if (!$auth->confirmIdentity($user, $request)) {
            return response()->json([
                'message' => $user->password !== null
                    ? 'Password is incorrect.'
                    : 'The confirmation code is invalid or has expired.',
            ], 403);
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
        $links = app(EmailLinks::class);
        $user = User::model()->find($request->id);

        if (!$request->hasValidSignature()
            || !$user
            || !hash_equals(hash('sha256', $user->email), (string) $request->hash)) {
            return $links->verificationFailed($request);
        }

        if ($user->hasVerifiedEmail()) {
            return $links->alreadyVerified($request, $user);
        }

        $user->markEmailAsVerified();

        return $links->verified($request, $user);
    }

    public function forgotPassword(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|string|email|max:255',
            ]);

            // A forgotten password is exactly the case where the user may
            // never have completed verification, so an unverified address
            // must still be able to receive a reset link.
            $user = User::findByEmail($request->email);
            if (!$user) {
                return response()->json([
                    'message' => 'User not registered or wrong email.',
                ], 404);
            }

            $expiryMinutes = config('neev.url_expiry_time', 60);
            $url = app(EmailLinks::class)->passwordResetUrl($user, now()->addMinutes($expiryMinutes));
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
        $request->validate([
            'email' => ['required', 'string', 'email'],
            'channel' => ['sometimes', 'string'],
        ]);

        $user = User::findByEmail($request->email);
        if (!$user) {
            return response()->json([
                'message' => 'Credentials are wrong.',
            ], 401);
        }

        $channel = (string) $request->input('channel', 'web');

        try {
            $link = $magicLink->generate($user, $channel, ['request' => $request]);
        } catch (MagicLinkThrottledException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'retry_after' => $e->retryAfter,
            ], 429)->header('Retry-After', (string) $e->retryAfter);
        } catch (MagicLinkBindingException $e) {
            Log::warning('Magic link refused: no binding source on the request.', [
                'user_id' => $user->id,
            ]);

            return response()->json([
                'message' => 'Unable to send a login link for this request.',
            ], 422);
        } catch (MagicLinkChannelException $e) {
            Log::warning('Magic link refused: unusable channel.', [
                'channel' => $channel,
            ]);

            return response()->json([
                'message' => 'Unsupported login link channel.',
            ], 422);
        }

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
        if (config('neev.magic_link.require_confirmation', true) && ($request->isMethod('get') || $request->isMethod('head'))) {
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
        if (!$user) {
            return response()->json([
                'message' => 'Invalid or expired verification link.',
            ], 403);
        }

        // The link was mailed to this address and came back signed, which
        // proves inbox control just as the verification mail would.
        if (!$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        // A magic link is a first factor, not a way around the second: an
        // enrolled account answers the same challenge it would after a
        // password, and gets the same short-lived JWT instead of a token.
        $mfaMethod = $user->preferredMultiFactorAuth->method ?? $user->activeMultiFactorAuths()->first()?->method;
        if ($mfaMethod) {
            return $this->mfaChallenge($request, $geoIP, $user, LoginAttempt::MagicAuth, $mfaMethod);
        }

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

            // Changing the address that owns the account always costs a
            // password. An account without one must set a password first.
            if ($user->password === null) {
                return response()->json([
                    'message' => 'Set a password on your account before changing your email address.',
                ], 403);
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
            $url = app(EmailLinks::class)->emailChangeUrl($user, $request->email, now()->addMinutes($expiryMinutes));
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
        $links = app(EmailLinks::class);
        $user = User::model()->find($request->id);
        $newEmail = $request->email;

        if (!$request->hasValidSignature() || !$user || !$newEmail) {
            return $links->emailChangeFailed($request);
        }

        if (!app(AuthService::class)->applyEmailChange($user, $newEmail)) {
            return $links->emailInUse($request, $user);
        }

        return $links->emailChanged($request, $user);
    }

    public function sendMFAOTP(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'message' => 'Credentials are wrong.',
            ], 403);
        }

        // Only an enrolled, active email factor gets a code. A pending setup
        // cannot answer a challenge, so mailing for one would be noise.
        if (!in_array('email', $this->getMfaOptions($user), true)) {
            return response()->json([
                'message' => 'Invalid auth method.',
            ], 400);
        }

        // A deliberate resend: the caller is telling us the first code did not
        // arrive, so this mints a fresh one even if the old one is still live.
        // Answering "sent" without mailing anything would strand exactly the
        // client this endpoint exists for.
        $this->sendMfaEmailOTP($user, force: true);

        return response()->json([
            'message' => 'Verification code has been sent.',
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

        // Claimed in one operation, because the middleware's check and a later
        // write are two: two requests arriving together would both pass the
        // check and both mint a login token from one first factor. Released
        // below if the trade itself fails.
        $mfaJwt = app(MfaJwt::class);
        if (!$mfaJwt->claim($claims)) {
            return response()->json([
                'message' => 'Invalid or expired token',
            ], 401);
        }

        $attemptId = $claims['attempt_id'] ?? null;
        $attempt = $attemptId ? $user->loginAttempts()->find($attemptId) : null;
        if ($attempt) {
            $attempt->is_success = true;
            $attempt->multi_factor_method = $request->auth_method;
            $attempt->save();
        }

        $token = app(AuthService::class)->createApiToken($request, $geoIP, $user, $attempt->method ?? LoginAttempt::Password, $expiryMinutes, $attempt);

        // The trade did not happen, so the token is not spent: give it back
        // rather than leave the caller with a dead credential and an
        // unfinished login.
        if (!$token) {
            $mfaJwt->release($claims);

            return response()->json([
                'message' => 'Unable to complete login.',
            ], 500);
        }

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
