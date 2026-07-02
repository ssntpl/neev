<?php

namespace Ssntpl\Neev\Http\Controllers\Auth;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Ssntpl\Neev\Http\Controllers\Controller;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\AuthService;
use Ssntpl\Neev\Services\GeoIP;
use Ssntpl\Neev\Services\RegistrationService;
use Ssntpl\Neev\Services\SpaCookieResponder;
use Ssntpl\Neev\Services\StatefulOriginResolver;

class OAuthController extends Controller
{
    public function __construct(
        protected AuthService $auth,
    ) {
    }

    public function redirect(Request $request, string $service)
    {
        if (!in_array($service, config('neev.oauth', []))) {
            abort(404);
        }

        $email = $request->email;

        $params = [];

        if ($email) {
            $params['login_hint'] = $email;
        }

        return Socialite::driver($service)->with($params)->redirect();
    }

    public function callback(Request $request, string $service, GeoIP $geoIP)
    {
        if (!in_array($service, config('neev.oauth', []))) {
            abort(404);
        }

        if (!$request->code) {
            return redirect(route('login'));
        }

        $oauthUser = Socialite::driver($service)->user();

        $user = User::findByEmail($oauthUser->email);
        if ($user) {
            if (!$user->hasVerifiedEmail()) {
                return redirect(route('login'));
            }
        } else {
            try {
                $user = app(RegistrationService::class)
                    ->registerViaOAuth($oauthUser->name, $oauthUser->email);
            } catch (Exception $e) {
                Log::error($e);
                return redirect(route('register'));
            }
        }

        $this->auth->login($request, $geoIP, $user, $service);

        $response = redirect(config('neev.home'));

        // Same-origin SPA monolith: also issue a login token in the
        // HttpOnly cookie so the SPA is authenticated for API calls when
        // the redirect lands. The attempt is reused from the session
        // login above, so no extra LoggedIn event or attempt row.
        if (app(StatefulOriginResolver::class)->isStatefulHost($request)) {
            $expiryMinutes = config('neev.login_token_expiry_minutes', 1440);
            $newToken = $user->createLoginToken($expiryMinutes);
            $newToken->accessToken->forceFill(['attempt_id' => session('attempt_id')])->save();

            $response->withCookie(
                app(SpaCookieResponder::class)->authCookie($newToken->plainTextToken, $expiryMinutes)
            );
        }

        return $response;
    }

}
