<?php

namespace Ssntpl\Neev\Services;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Ssntpl\Neev\Events\LoggedInEvent;
use Ssntpl\Neev\Models\LoginAttempt;

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
}
