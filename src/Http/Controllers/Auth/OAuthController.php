<?php

namespace Ssntpl\Neev\Http\Controllers\Auth;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Ssntpl\Neev\Http\Controllers\Controller;
use Ssntpl\Neev\Models\LoginAttempt;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\AuthService;
use Ssntpl\Neev\Services\EmailLinks;
use Ssntpl\Neev\Services\GeoIP;
use Ssntpl\Neev\Services\MfaJwt;
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
            return redirect(app(EmailLinks::class)->loginUrl());
        }

        $oauthUser = Socialite::driver($service)->user();

        $user = User::findByEmail($oauthUser->email);
        if ($user) {
            // The provider authenticated this address, which is proof of
            // ownership just as strong as our own verification mail.
            if (!$user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }
        } else {
            try {
                $user = app(RegistrationService::class)
                    ->registerViaOAuth($oauthUser->name ?: $oauthUser->getNickname(), $oauthUser->email);
            } catch (Exception $e) {
                Log::error($e);
                return redirect(app(EmailLinks::class)->registerUrl());
            }
        }

        // The provider is a first factor, not a way around the second. Whether
        // it asked for MFA of its own is the provider's business and invisible
        // here, so an enrolled account answers the same challenge it would
        // after a password: the attempt names the factor it is waiting on and
        // stays unsuccessful, and the challenge page needs the account in the
        // session. No login token cookie is issued until that is answered.
        $mfaMethod = $user->preferredMultiFactorAuth->method
            ?? $user->activeMultiFactorAuths()->first()?->method;

        $this->auth->login($request, $geoIP, $user, LoginAttempt::OAuthPrefix . $service, mfa: $mfaMethod, pendingMfa: (bool) $mfaMethod);

        if ($mfaMethod) {
            session(['email' => $user->email]);
            session()->forget('mfa_redirect');

            // The Blade challenge page mails a code when it opens; a headless
            // install has no page of ours, so the code has to leave from here
            // or the account has nothing to answer with. The helper leaves a
            // live code alone, so under the kit this is not a second mail.
            if ($mfaMethod === 'email') {
                $this->auth->sendMfaEmailCode($user);
            }

            // These routes are registered kit or not, so the challenge page
            // cannot be assumed to exist: EmailLinks points a headless install
            // at its own page instead of throwing on a missing route.
            $response = redirect(app(EmailLinks::class)->mfaChallengeUrl($mfaMethod));

            // Same-origin SPA monolith: the login token is withheld until the
            // second factor, but the page that answers the challenge still
            // needs something to answer it with. The cookie carries the
            // step-up JWT in the meantime — the same credential the API
            // callback hands back, good only for the OTP step — which
            // `POST {prefix}/mfa/otp/verify` trades for the real login token.
            // A headless install has no challenge page of ours, so this is the
            // only way its frontend can finish what the callback started.
            if (app(StatefulOriginResolver::class)->isStatefulHost($request)) {
                $mfaJwt = app(MfaJwt::class);

                $response->withCookie(app(SpaCookieResponder::class)->authCookie(
                    $mfaJwt->issue($user, session('attempt_id')),
                    $mfaJwt->expiryMinutes(),
                ));
            }

            return $response;
        }

        $response = redirect($this->auth->intendedUrl());

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
