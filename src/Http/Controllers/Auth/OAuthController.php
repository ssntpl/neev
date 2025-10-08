<?php

namespace Ssntpl\Neev\Http\Controllers\Auth;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Log;
use Ssntpl\Neev\Http\Controllers\Controller;
use Ssntpl\Neev\Models\Email;
use Ssntpl\Neev\Models\LoginAttempt;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\GeoIP;
use Str;

class OAuthController extends Controller
{
    public function redirect(Request $request, string $service)
    {
        $email = $request->email;

        $params = [];

        if ($email) {
            $params['login_hint'] = $email;
        }

        return Socialite::driver($service)->with($params)->redirect();
    }
   
    public function callback(Request $request, string $service, GeoIP $geoIP)
    {
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

        Auth::login($user);
        $request->session()->regenerate();
        
        try {
            $clientDetails = LoginAttempt::getClientDetails($request);
            $location = $geoIP?->getLocation($request->ip());
            $attempt = $user->loginAttempts()->create([
                'method' => $service,
                'location' => $location,
                'platform' => $clientDetails['platform'] ?? '',
                'browser' => $clientDetails['browser'] ?? '',
                'device' => $clientDetails['device'] ?? '',
                'ip_address' => $request->ip(),
                'is_suspicious' => LoginAttempt::isSuspicious($user, $clientDetails, $request->ip(), $location),
                'is_success' => true,
            ]);
        } catch (Exception $e) {
            Log::error($e->getMessage());
        }

        if ($attempt?->is_suspicious) {
            if (count($user->multiFactorAuths) > 0) {
                session(['email' => $user->email->email, 'attempt_id' => $attempt?->id]);
                return redirect(route('otp.mfa.create', $user->preferedMultiAuth?->method ?? $user->multiFactorAuths()->first()?->method));
            } else {
                return UserAuthController::suspiciousAction($request, $user->email, $attempt);
            }
        }
        
        return redirect(config('neev.dashboard_url'));
    }

    public function register($oauthUser)
    {
        if (!config('neev.support_username')) {
            $email = explode('@', $oauthUser->email)[0];
            $username = $email;
            while (User::getModel()->where('username', $username)->first()) {
                $username = $email.'_'.Str::random(4);
            }
            
            $user = User::model()->create([
                'name' => $oauthUser->name,
                'username' => $username,
            ]);
        } else {
            $user = User::model()->create([
                'name' => $oauthUser->name,
            ]);
        }

        try {
            $user->emails()->create([
                'email' => $oauthUser->email,
                'is_primary' => true,
                'verified_at' => now(),
            ]);

            if (config('neev.team')) {
                if (config('neev.domain_federation')) {
                    $emailDomain = substr(strrchr($oauthUser->email, "@"), 1);
                    $team = Team::model()->where('federated_domain', $emailDomain)->first();
                    if (!$team?->domain_verified_at) {
                        $team = Team::model()->forceCreate([
                            'name' => explode(' ', $user->name, 2)[0]."'s Team",
                            'user_id' => $user->id,
                            'is_public' => false,
                        ]);
                    }
                } else {
                    $team = Team::model()->forceCreate([
                        'name' => explode(' ', $user->name, 2)[0]."'s Team",
                        'user_id' => $user->id,
                        'is_public' => false,
                    ]);
                }
                $team->users()->attach($user, ['joined' => true, 'role' => $team->default_role ?? '']);
                if ($team->default_role) {
                    $user->assignRole($team->default_role ?? '', $team);
                }
            }
        } catch (Exception $e) {
            $user->delete();
            Log::error($e->getMessage());
            return null;
        }

        return $user;
    }
}
