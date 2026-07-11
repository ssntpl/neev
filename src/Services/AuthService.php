<?php

namespace Ssntpl\Neev\Services;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Ssntpl\Neev\Events\LoggedIn;
use Ssntpl\Neev\Events\PasswordChanged;
use Illuminate\Support\Facades\Hash;
use Ssntpl\Neev\Mail\VerifyUserEmail;
use Ssntpl\Neev\Models\LoginAttempt;
use Ssntpl\Neev\Models\OTP;
use Ssntpl\Neev\Models\User;

class AuthService
{
    public function login(Request $request, GeoIP $geoIP, $user, $method, $mfa = null, $attempt = null, bool $viaRequestAuth = false)
    {
        if (!$user?->active) {
            throw ValidationException::withMessages([
                'email' => 'Your account is deactivated, please contact your admin to activate your account.',
            ]);
        }

        if ($viaRequestAuth) {
            $request->authenticate();
        } else {
            Auth::login($user, false);
        }

        $request->session()->regenerate();

        event(new LoggedIn($user));

        $attempt = $this->recordLoginAttempt($request, $geoIP, $user, $method, $mfa, $attempt);
        session(['attempt_id' => $attempt->id ?? null]);
    }

    public function recordLoginAttempt(Request $request, GeoIP $geoIP, $user, $method, $mfa = null, $attempt = null): ?LoginAttempt
    {
        try {
            if ($attempt) {
                if (!$attempt->is_success) {
                    $attempt->is_success = true;
                    $attempt->save();
                }
                return $attempt;
            }

            $clientDetails = LoginAttempt::getClientDetails($request);

            return $user->loginAttempts()->create([
                'method'  => $method,
                'location' => $geoIP->getLocation($request->ip()),
                'multi_factor_method' => $mfa,
                'platform' => $clientDetails['platform'] ?? '',
                'browser'  => $clientDetails['browser'] ?? '',
                'device'   => $clientDetails['device'] ?? '',
                'ip_address' => $request->ip(),
                'is_success' => true,
            ]);
        } catch (Exception $e) {
            Log::error($e);
            return null;
        }
    }

    /**
     * Create an API login token for the user, record the login attempt, and fire the LoggedIn event.
     *
     * @return string|null The plain-text token, or null on failure.
     */
    public function createApiToken(Request $request, GeoIP $geoIP, $user, string $method, int $expiryMinutes, ?LoginAttempt $attempt = null): ?string
    {
        if (!$user->active) {
            throw ValidationException::withMessages([
                'email' => 'Your account is deactivated, please contact your admin to activate your account.',
            ]);
        }

        try {
            $attempt = $this->recordLoginAttempt($request, $geoIP, $user, $method, null, $attempt);

            $token = $user->createLoginToken($expiryMinutes);
            $accessToken = $token->accessToken;
            $accessToken->attempt_id = $attempt->id;
            $accessToken->save();

            event(new LoggedIn($user));

            return $token->plainTextToken;
        } catch (Exception $e) {
            Log::error($e);
            return null;
        }
    }

    /**
     * Send email verification link to the user's current email.
     */
    public function sendEmailVerification(User $user): void
    {
        $expiryMinutes = config('neev.url_expiry_time', 60);

        if (config('neev.ui') === 'blade') {
            $url = URL::temporarySignedRoute(
                'verification.verify',
                now()->addMinutes($expiryMinutes),
                ['id' => $user->id, 'hash' => hash('sha256', $user->email)]
            );
        } else {
            // Headless: the Blade page routes are not registered. Link to
            // the app's frontend, carrying the signature for the API
            // verification endpoint (route 'mail.verify').
            $signedUrl = URL::temporarySignedRoute(
                'mail.verify',
                now()->addMinutes($expiryMinutes),
                ['id' => $user->id, 'hash' => hash('sha256', $user->email)]
            );
            $url = config('app.url') . '/verify-email?' . parse_url($signedUrl, PHP_URL_QUERY);
        }

        // Both proofs travel in one email; the app-owned template decides
        // which to show. The code lets the user complete verification on
        // the device that is waiting (cross-device signup, TVs, SafeLinks-
        // mangled links); either proof invalidates the other on success.
        $otp = $this->createEmailVerificationOtp($user);

        Mail::to($user->email)->send(new VerifyUserEmail($url, $user->name, 'Verify Email', $expiryMinutes, $otp));
    }

    /**
     * Issue (or replace) the user's email-verification code. Stored
     * hashed; resending resets the attempt counter.
     */
    protected function createEmailVerificationOtp(User $user): string
    {
        $length = (int) config('neev.otp_length', 6);
        $otp = (string) random_int(10 ** ($length - 1), (10 ** $length) - 1);

        OTP::updateOrCreate(
            ['owner_id' => $user->id, 'owner_type' => $user->getMorphClass()],
            [
                'otp' => $otp,
                'attempts' => 0,
                'expires_at' => now()->addMinutes(config('neev.otp_expiry_time', 15)),
            ],
        );

        return $otp;
    }

    /**
     * Verify an email-verification code for the waiting session.
     * Wrong codes count toward OTP::MAX_ATTEMPTS, after which the code
     * is invalidated and a fresh email must be requested.
     */
    public function verifyEmailOtp(User $user, string $otp): bool
    {
        $record = OTP::query()
            ->where('owner_id', $user->id)
            ->where('owner_type', $user->getMorphClass())
            ->first();

        if (!$record || $record->expires_at->isPast() || $record->attempts >= OTP::MAX_ATTEMPTS) {
            $record?->delete();
            return false;
        }

        if (!Hash::check($otp, $record->otp)) {
            $record->increment('attempts');
            if ($record->attempts >= OTP::MAX_ATTEMPTS) {
                $record->delete();
            }
            return false;
        }

        // markEmailAsVerified() deletes the OTP row, so the signed link
        // and the code invalidate each other through the same path.
        $user->markEmailAsVerified();

        return true;
    }

    /**
     * Send email verification link for an email change.
     * The signed URL binds the user ID and new email so it can't be tampered with.
     *
     * @param User $user The user requesting the change
     * @param string $newEmail The new email address to verify
     * @param string $routeName The named route for verification (web vs API)
     */
    public function sendEmailChangeVerification(User $user, string $newEmail, string $routeName = 'neev.email.change.verify'): void
    {
        $expiryMinutes = config('neev.url_expiry_time', 60);
        $signedUrl = URL::temporarySignedRoute(
            $routeName,
            now()->addMinutes($expiryMinutes),
            ['id' => $user->id, 'email' => $newEmail]
        );

        Mail::to($newEmail)->send(new VerifyUserEmail($signedUrl, $user->name, 'Verify Email Change', $expiryMinutes));
    }

    /**
     * Apply a verified email change.
     * Checks that the new email is still unique before updating.
     *
     * @return bool True if the email was updated, false if the email is already taken.
     */
    public function applyEmailChange(User $user, string $newEmail): bool
    {
        $existing = User::findByEmail($newEmail);
        if ($existing && $existing->id !== $user->id) {
            return false;
        }

        $user->email = $newEmail;
        $user->email_verified_at = now();
        $user->save();

        return true;
    }

    /**
     * Change the user's password and manage password history.
     *
     * @param User $user
     * @param string $newPassword Plain-text password (will be hashed by the model's cast)
     */
    public function changePassword(User $user, string $newPassword): void
    {
        DB::transaction(function () use ($user, $newPassword) {
            $user = User::model()->lockForUpdate()->find($user->id);

            $history = $user->password_history ?? [];

            // Prepend the current hashed password to history
            $currentHash = $user->getRawOriginal('password');
            if ($currentHash) {
                array_unshift($history, $currentHash);
            }

            // Trim to configured limit
            $limit = $this->getPasswordHistoryLimit();
            $user->password_history = array_slice($history, 0, $limit);
            $user->password = $newPassword;
            $user->password_changed_at = now();
            $user->save();
        });

        event(new PasswordChanged($user));
    }

    /**
     * Get the password history limit from config.
     */
    protected function getPasswordHistoryLimit(): int
    {
        $passwordRules = config('neev.password', []);
        if (!is_array($passwordRules)) {
            return 5;
        }
        foreach ($passwordRules as $rule) {
            if ($rule instanceof \Ssntpl\Neev\Rules\PasswordHistory) {
                return $rule->getCount();
            }
        }
        return 5;
    }
}
