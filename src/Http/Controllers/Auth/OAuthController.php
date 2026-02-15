<?php

namespace Ssntpl\Neev\Http\Controllers\Auth;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Ssntpl\Neev\Http\Controllers\Controller;
use Ssntpl\Neev\Models\Domain;
use Ssntpl\Neev\Models\Email;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\AuthService;
use Ssntpl\Neev\Services\GeoIP;

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
        if (!$oauthUser) {
            return redirect(route('login'));
        }

        $email = Email::where('email', $oauthUser->email)->first();
        if ($email) {
            $user = $email?->user;
            if (!$user || !$email?->verified_at) {
                return redirect(route('login'));
            }
        } else {
            $user = $this->register($oauthUser);
            if (!$user) {
                return redirect(route('register'));
            }
        }

        $this->auth->login($request, $geoIP, $user, $service);

        return redirect(config('neev.dashboard_url'));
    }

    public function register($oauthUser)
    {
        if (!$oauthUser) {
            return null;
        }
        DB::beginTransaction();
        $userData = ['name' => $oauthUser->name];

        if (!config('neev.support_username')) {
            $base = explode('@', $oauthUser->email)[0];
            $username = $base;
            while (User::getModel()->where('username', $username)->first()) {
                $username = $base . '_' . Str::random(4);
            }
            $userData['username'] = $username;
        }

        $user = User::model()->create($userData);

        if (!$user) {
            DB::rollBack();
            return null;
        }

        try {
            $user->emails()->create([
                'email' => $oauthUser->email,
                'is_primary' => true,
                'verified_at' => now(),
            ]);

            if (config('neev.team')) {
                $shouldCreateTeam = !config('neev.domain_federation') || !$this->isDomainVerified($oauthUser->email);

                if ($shouldCreateTeam) {
                    $team = Team::model()->forceCreate([
                        'name' => explode(' ', $user->name, 2)[0] . "'s Team",
                        'user_id' => $user->id,
                        'is_public' => false,
                    ]);
                    $team->users()->attach($user, ['joined' => true, 'role' => $team->default_role ?? '']);
                    if ($team->default_role) {
                        $user->assignRole($team->default_role, $team);
                    }
                }
            }
        } catch (Exception $e) {
            DB::rollBack();
            Log::error($e);
            return null;
        }
        DB::commit();
        return $user;
    }

    private function isDomainVerified(string $email): bool
    {
        $emailDomain = substr(strrchr($email, "@"), 1);
        $domain = Domain::where('domain', $emailDomain)->first();

        return $domain?->verified_at !== null;
    }
}
