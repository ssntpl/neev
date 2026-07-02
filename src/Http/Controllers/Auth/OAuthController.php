<?php

namespace Ssntpl\Neev\Http\Controllers\Auth;

use Exception;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Ssntpl\Neev\Http\Controllers\Controller;
use Ssntpl\Neev\Models\Domain;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\AuthService;
use Ssntpl\Neev\Services\GeoIP;
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
            $user = $this->register($oauthUser);
            if (!$user) {
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

    public function register($oauthUser)
    {
        DB::beginTransaction();
        $userData = [
            'name' => $oauthUser->name,
            'email' => $oauthUser->email,
            'email_verified_at' => now(),
        ];

        if (config('neev.support_username')) {
            $base = explode('@', $oauthUser->email)[0];
            $username = $base;
            while (User::getModel()->where('username', $username)->first()) {
                $username = $base . '_' . Str::random(4);
            }
            $userData['username'] = $username;
        }

        $user = User::model()->forceCreate($userData);

        try {
            if (config('neev.team')) {
                $shouldCreateTeam = !$this->isDomainVerified($oauthUser->email);

                if ($shouldCreateTeam) {
                    $team = Team::model()->forceCreate([
                        'name' => explode(' ', $user->name, 2)[0] . "'s Team",
                        'user_id' => $user->id,
                        'is_public' => false,
                        'activated_at' => now(),
                    ]);
                    $team->addMember($user);
                }
            }
        } catch (Exception $e) {
            DB::rollBack();
            Log::error($e);
            return null;
        }
        DB::commit();

        event(new Registered($user));

        return $user;
    }

    private function isDomainVerified(string $email): bool
    {
        $emailDomain = substr(strrchr($email, "@"), 1);
        $domain = Domain::where('domain', $emailDomain)->first();

        return $domain?->verified_at !== null;
    }
}
