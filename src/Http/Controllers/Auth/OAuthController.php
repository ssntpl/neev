<?php

namespace Ssntpl\Neev\Http\Controllers\Auth;

use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Ssntpl\Neev\Http\Controllers\Controller;
use Ssntpl\Neev\Models\Email;
use Ssntpl\Neev\Services\GeoIP;

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
        $user = $email?->user;
        if (!$user || !$email?->verified_at) {
            return redirect(route('login'));
        }

        PasskeyController::login($request, $geoIP, $user, $service);
        return redirect(config('neev.dashboard_url'));
    }
}
