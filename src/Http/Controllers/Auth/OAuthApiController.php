<?php

namespace Ssntpl\Neev\Http\Controllers\Auth;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Ssntpl\Neev\Http\Controllers\Controller;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\AuthService;
use Ssntpl\Neev\Services\GeoIP;
use Ssntpl\Neev\Services\OAuthClients;
use Ssntpl\Neev\Services\RegistrationService;
use Ssntpl\Neev\Services\SpaCookieResponder;

class OAuthApiController extends Controller
{
    public function redirectUrl(Request $request, string $service)
    {
        if (!in_array($service, config('neev.oauth', []))) {
            return response()->json([
                'message' => 'OAuth provider not supported.',
            ], 404);
        }

        $platform = $request->input('platform');
        $clients = app(OAuthClients::class);

        // One provider, one client per platform: an Android build cannot use
        // the web client_id. Without a platform the default client is used,
        // exactly as before.
        if ($platform && !$clients->has($service, $platform)) {
            return response()->json([
                'message' => 'OAuth client not configured for this platform.',
                'platforms' => $clients->platforms($service),
            ], 404);
        }

        $params = [];
        if ($request->email) {
            $params['login_hint'] = $request->email;
        }

        $redirectUrl = $clients->redirectUrl($service, $platform);

        /** @var AbstractProvider $driver */
        $driver = $clients->driver($service, $platform);
        $url = $driver
            ->stateless()
            ->with($params)
            ->redirectUrl($redirectUrl)
            ->redirect()
            ->getTargetUrl();

        return response()->json([
            'url' => $url,
        ]);
    }

    public function callback(Request $request, string $service, GeoIP $geoIP)
    {
        if (!in_array($service, config('neev.oauth', []))) {
            return response()->json([
                'message' => 'OAuth provider not supported.',
            ], 404);
        }

        if (!$request->code) {
            return response()->json([
                'message' => 'Authorization code is required.',
            ], 400);
        }

        $platform = $request->input('platform');
        $clients = app(OAuthClients::class);

        // The code was issued to one client, so it has to be exchanged with
        // the same one — the platform must match the redirect request.
        if ($platform && !$clients->has($service, $platform)) {
            return response()->json([
                'message' => 'OAuth client not configured for this platform.',
                'platforms' => $clients->platforms($service),
            ], 404);
        }

        try {
            $redirectUrl = $clients->redirectUrl($service, $platform);

            /** @var AbstractProvider $driver */
            $driver = $clients->driver($service, $platform);
            $oauthUser = $driver
                ->stateless()
                ->redirectUrl($redirectUrl)
                ->user();

            /** @var SocialiteUser $oauthUser */
            $user = User::findByEmail($oauthUser->email);
            if ($user) {
                // The provider authenticated this address, which is proof of
                // ownership just as strong as our own verification mail.
                if (!$user->hasVerifiedEmail()) {
                    $user->markEmailAsVerified();
                }
            } else {
                $user = app(RegistrationService::class)
                    ->registerViaOAuth($oauthUser->name ?: $oauthUser->getNickname(), $oauthUser->email);
            }

            $expiryMinutes = config('neev.login_token_expiry_minutes', 1440);
            $token = app(AuthService::class)->createApiToken($request, $geoIP, $user, $service, $expiryMinutes);

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
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'message' => 'OAuth authentication failed.',
            ], 500);
        }
    }

}
