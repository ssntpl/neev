<?php
namespace Ssntpl\Neev\Services;

use Ssntpl\Neev\Events\LoggedInEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Exception;
use Ssntpl\Neev\Models\LoginAttempt;
use Ssntpl\Neev\Services\GeoIP;

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

        try {
            if ($attempt) {
                $attempt->is_success = true;
                $attempt->save();

            } else {
                $clientDetails = LoginAttempt::getClientDetails($request);

                $attempt = $user->loginAttempts()->create([
                    'method'  => $method,
                    'location' => $geoIP?->getLocation($request->ip()),
                    'multi_factor_method' => $mfa,
                    'platform' => $clientDetails['platform'] ?? '',
                    'browser'  => $clientDetails['browser'] ?? '',
                    'device'   => $clientDetails['device'] ?? '',
                    'ip_address' => $request->ip(),
                    'is_success' => true,
                ]);
            }
            session(['attempt_id' => $attempt?->id ?? null]);
        } catch (Exception $e) {
            Log::error($e);
        }
    }
}
