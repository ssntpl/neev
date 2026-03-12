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
use Ssntpl\Neev\Services\GeoIP;

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

        $redirectUrl = config('app.url') . '/oauth/' . $service . '/callback';

        /** @var \Laravel\Socialite\Two\AbstractProvider $driver */
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
            $redirectUrl = config('app.url') . '/oauth/' . $service . '/callback';

            /** @var \Laravel\Socialite\Two\AbstractProvider $driver */
            $driver = Socialite::driver($service);
            $oauthUser = $driver
                ->stateless()
                ->redirectUrl($redirectUrl)
                ->user();

            /** @var \Laravel\Socialite\Two\User $oauthUser */
            $email = Email::findByEmail($oauthUser->email);
            if ($email) {
                $user = $email->user;
                if (!$user || !$email->verified_at) {
                    return response()->json([
                        'message' => 'Account not found or email not verified.',
                    ], 401);
                }
            } else {
                $user = $this->register($oauthUser);
                if (!$user) {
                    return response()->json([
                        'message' => 'Unable to register user.',
                    ], 500);
                }
            }

            $authController = new UserAuthApiController();
            $expiryMinutes = config('neev.login_token_expiry_minutes', 1440);
            $token = $authController->getToken(
                request: $request,
                geoIP: $geoIP,
                user: $user,
                method: $service,
                expiryMinutes: $expiryMinutes
            );

            if (!$token) {
                return response()->json([
                    'message' => 'Something went wrong.',
                ], 500);
            }

            return response()->json([
                'auth_state' => 'authenticated',
                'token' => $token,
                'expires_in' => $expiryMinutes,
                'email_verified' => $user->hasVerifiedEmail(),
            ]);
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'message' => 'OAuth authentication failed.',
            ], 500);
        }
    }

    private function register($oauthUser)
    {
        DB::beginTransaction();
        $userData = ['name' => $oauthUser->name];

        if (config('neev.support_username')) {
            $base = explode('@', $oauthUser->email)[0];
            $username = $base;
            while (User::getModel()->where('username', $username)->first()) {
                $username = $base . '_' . Str::random(4);
            }
            $userData['username'] = $username;
        }

        $user = User::model()->create($userData);

        try {
            $user->emails()->create([
                'email' => $oauthUser->email,
                'is_primary' => true,
                'verified_at' => now(),
            ]);

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
        return $user;
    }

    private function isDomainVerified(string $email): bool
    {
        $emailDomain = substr(strrchr($email, "@"), 1);
        $domain = Domain::where('domain', $emailDomain)->first();

        return $domain?->verified_at !== null;
    }
}
