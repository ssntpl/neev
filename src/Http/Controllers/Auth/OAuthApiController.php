<?php

namespace Ssntpl\Neev\Http\Controllers\Auth;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Ssntpl\Neev\Http\Controllers\Controller;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\AuthService;
use Ssntpl\Neev\Services\EmailLinks;
use Ssntpl\Neev\Services\GeoIP;
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

        $params = [];
        if ($request->email) {
            $params['login_hint'] = $request->email;
        }

        $redirectUrl = app(EmailLinks::class)->oauthCallbackUrl($service);

        /** @var AbstractProvider $driver */
        $driver = Socialite::driver($service);
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

        try {
            $redirectUrl = app(EmailLinks::class)->oauthCallbackUrl($service);

            /** @var AbstractProvider $driver */
            $driver = Socialite::driver($service);
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
