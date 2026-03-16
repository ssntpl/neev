<?php

namespace Ssntpl\Neev\Services;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Ssntpl\Neev\Events\LoggedInEvent;
use Ssntpl\Neev\Mail\VerifyUserEmail;
use Ssntpl\Neev\Models\LoginAttempt;
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

        event(new LoggedInEvent($user));

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

            event(new LoggedInEvent($user));

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
        $signedUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes($expiryMinutes),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        Mail::to($user->email)->send(new VerifyUserEmail($signedUrl, $user->name, 'Verify Email', $expiryMinutes));
    }

    /**
     * Send email change verification link to a new email address.
     * The signed URL encodes the new email so it can be retrieved on verification.
     */
    public function sendEmailChangeVerification(User $user, string $newEmail): void
    {
        $expiryMinutes = config('neev.url_expiry_time', 60);
        $signedUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes($expiryMinutes),
            ['id' => $user->id, 'hash' => sha1($newEmail), 'email' => $newEmail]
        );

        Mail::to($newEmail)->send(new VerifyUserEmail($signedUrl, $user->name, 'Verify Email', $expiryMinutes));
    }

    /**
     * Validate a signed email verification URL.
     *
     * @return string|null The new email if this is an email-change verification, null for standard verification.
     */
    public function verifyEmailSignature(Request $request): ?string
    {
        return $request->query('email');
    }

    /**
     * Change the user's password and manage password history.
     *
     * @param User $user
     * @param string $newPassword Plain-text password (will be hashed by the model's cast)
     */
    public function changePassword(User $user, string $newPassword): void
    {
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
    }

    /**
     * Get the password history limit from config.
     */
    protected function getPasswordHistoryLimit(): int
    {
        $passwordRules = config('neev.password', []);
        foreach ($passwordRules as $rule) {
            if (is_object($rule) && $rule instanceof \Ssntpl\Neev\Rules\PasswordHistory) {
                $reflection = new \ReflectionClass($rule);
                $property = $reflection->getProperty('count');
                return $property->getValue($rule);
            }
        }
        return 5;
    }
}
