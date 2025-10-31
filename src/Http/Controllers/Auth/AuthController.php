<?php

namespace Ssntpl\Neev\Http\Controllers\Auth;

use Carbon\Carbon;
use Exception;
use Hash;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Mail;
use Ssntpl\Neev\Http\Controllers\Controller;
use Ssntpl\Neev\Http\Controllers\UserApiController;
use Ssntpl\Neev\Mail\LoginUsingLink;
use Ssntpl\Neev\Models\AccessToken;
use Ssntpl\Neev\Models\Domain;
use Ssntpl\Neev\Models\Email;
use Ssntpl\Neev\Models\LoginAttempt;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\TeamInvitation;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\GeoIP;
use URL;
use Log;

class AuthController extends Controller
{
    public function register(Request $request, GeoIP $geoIP)
    {
        $validationRules = [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:'.Email::class,
            'password' => config('neev.password'),
        ];
        
        if (config('neev.support_username')) {
            $validationRules['username'] = config('neev.username');
        }
        
        try {
            $request->validate($validationRules);
            
            $user = User::model()->create([
                'name' => $request->name,
                'username' => $request->username,
            ]);

            $user = User::model()->find($user->id);
            
            $email = $user->emails()->create([
                'email' => $request->email,
                'is_primary' => true
            ]);
            
            $user->passwords()->create([
                'password' => Hash::make($request->password),
            ]);

            if (config('neev.team')) {
                try {
                    if ($request->invitation_id) {
                        $invitation = TeamInvitation::find($request->invitation_id);
                        if (!$invitation || sha1($invitation?->email) !== $request->hash) {
                            $user->delete();
                            return response()->json([
                                'status' => 'Failed',
                                'message' => 'Invalid or expired invitation link.',
                            ], 500);
                        }

                        $email->verified_at = now();
                        $email->save();

                        $team = $invitation->team;
                        $team->users()->attach($user, ['role' => $invitation->role ?? '', 'joined' => true]);
                        if ($invitation?->role) {
                            $user->assignRole($invitation->role, $team);
                        }
                        $invitation->delete();
                    } else {
                        if (config('neev.domain_federation')) {
                            $emailDomain = substr(strrchr($request->email, "@"), 1);
                            $domain = Domain::where('domain', $emailDomain)->first();
                            if (!$domain?->verified_at) {
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
                    Log::error($e);
                    return response()->json([
                        'status' => 'Failed',
                        'message' => 'Unable to create team',
                    ], 500);
                }
            }

            if (!$email->verified_at) {
                UserApiController::sendMailVerification($email);
            }

            $token = $this->getToken($request, $geoIP, $user, LoginAttempt::Password);;
            if (!$token) {
                return response()->json([
                    'status' => 'Failed',
                    'message' => 'Something went wrong.',
                ], 500);
            }
            return response()->json([
                'status' => 'Success',
                'token' => $token,
                'email_verified' => $user->hasVerifiedEmail(),
            ]);
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'status' => 'Failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function login(Request $request, GeoIP $geoIP)
    {
        if (config('neev.support_username') && !preg_match('/^[\w.%+\-]+@[\w.\-]+\.[A-Za-z]{2,}$/', $request->email)) {
            $user = User::model()->where('username', $request->email)->first();
            if ($user) {
                $request->merge(['username' => $user->username]);
                $request->merge(['email' => $user->email?->email]);
            }
        }

        $email = Email::where('email', $request->email)->first();
        if (!$email) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Credentials are wrong.',
            ], 401);
        }
        
        $user = $email->user;
        $mfaMethod = $user?->preferedMultiAuth?->method ?? $user?->multiFactorAuths()->first()?->method;
        if (!$user || !Hash::check($request->password, (string)$user->password->password)) {
            if (config('neev.record_failed_login_attempts')) {
                $clientDetails = LoginAttempt::getClientDetails($request);
                $attempt = $user->loginAttempts()->create([
                    'method' => LoginAttempt::Password,
                    'location' => $geoIP?->getLocation($request->ip()),
                    'multi_factor_method' => $mfaMethod ?? null,
                    'platform' => $clientDetails['platform'] ?? '',
                    'browser' => $clientDetails['browser'] ?? '',
                    'device' => $clientDetails['device'] ?? '',
                    'ip_address' => $request->ip(),
                    'is_success' => false,
                ]);
            }
            return response()->json([
                'status' => 'Failed',
                'message' => 'Credentials are wrong.',
            ], 401);
        }

        $token = $this->getToken(request: $request, geoIP: $geoIP, user: $user, method: LoginAttempt::Password, mfa: $mfaMethod ?? null, attempt: $attempt ?? null);

        return response()->json([
            'status' => 'Success',
            'token' => $token,
            'email_verified' => $user->hasVerifiedEmail($email->email),
            'prefered_mfa' => $mfaMethod,
        ]);
    }

    public function getToken(Request $request, GeoIP $geoIP, $user, $method, $mfa = null, $attempt = null) 
    {
        if (!$user->active) {
            throw ValidationException::withMessages([
                'email' => 'Your account is deactivated, please contact your admin to activate your account.',
            ]);
        }

        try {
            if ($attempt) {
                $attempt->is_success = $mfa ? false : true;
                $attempt->save();
            } else {
                $clientDetails = LoginAttempt::getClientDetails($request);
                $attempt = $user->loginAttempts()->create([
                    'method' => $method,
                    'location' => $geoIP?->getLocation($request->ip()),
                    'multi_factor_method' => $mfa,
                    'platform' => $clientDetails['platform'] ?? '',
                    'browser' => $clientDetails['browser'] ?? '',
                    'device' => $clientDetails['device'] ?? '',
                    'ip_address' => $request->ip(),
                    'is_success' => $mfa ? false : true,
                ]);
            }

            $token = $user->createLoginToken($mfa ? 60 : 1440);
            $accessToken = $token->accessToken;
            if ($mfa) {
                if ($mfa == 'email') {
                    UserApiController::sendMailOTP($user->email, true);
                }
                $accessToken->token_type = $mfa ? AccessToken::mfa_token : null;
            }
            $accessToken->attempt_id = $attempt->id;
            $accessToken->save();

            return $token->plainTextToken;
        } catch (Exception $e) {
            Log::error($e);
            return null;
        }
    }

    public function sendMailVerificationLink(Request $request)
    {
        $email = Email::where('email', $request->email)->first();
        if (!$email) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Email not found.',
            ]);
        }
        if ($email->verified_at) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Email already verified.',
            ]);
        }

        UserApiController::sendMailVerification($email);
        
        return response()->json([
            'status' => 'Success',
            'message' => 'Login link has been sent.',
        ]);
    }

    public function logout(Request $request)
    {
        $accessToken = AccessToken::find($request->attributes->get('token_id'));
        if (!$accessToken || $accessToken->user->id !== $request->user()->id) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Invalid Token.',
            ]);
        }

        $accessToken->delete();
        return response()->json([
            'status' => 'Success',
            'message' => 'Logged out successfully.',
        ]);
    }

    public function logoutAll(Request $request)
    {
        $user = User::model()->find($request->user()->id);
        if (!$user) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Invalid Token.',
            ]);
        }

        $user->loginTokens()->delete();

        return response()->json([
            'status' => 'Success',
            'message' => 'Logged out successfully.',
        ]);
    }

    public function emailVerify(Request $request) {
        $email = Email::find($request->id);
        if (!$request->hasValidSignature() || !$email || $email?->user?->id != $request->user()?->id) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Invalid or expired verification link.'
            ], 403);
        }

        if ($email->verified_at) {
            return response()->json([
                'status' => 'Success',
                'message' => 'Email verification already done.'
            ]);
        }
        
        $email->verified_at = now();
        $email->save();
        
        return response()->json([
            'status' => 'Success',
            'message' => 'Email verification done.'
        ]);
    }

    public function sendEmailOTP(Request $request)
    {
        $email = Email::where('email', $request->email)->first();
        if (!$email) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Email not found.',
            ]);
        }

        UserApiController::sendMailOTP($email, $request->mfa ?? false);

        return response()->json([
            'status' => 'Success',
            'message' => 'Verification code has been sent to your email.'
        ]);
    }

    public function verifyEmailOTP(Request $request)
    {
        $email = Email::where('email', $request->email)->first();
        
        $otp = $email->otp;

        if (!$otp || $otp->expires_at < now() || $otp->otp !== $request->otp) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Code verification was falied.'
            ], 401);
        }
        
        return response()->json([
            'status' => 'Success',
            'message' => 'Verification code has been verified.'
        ]);
    }

    public function forgotPassword(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|string|email|max:255',
                'password' => config('neev.password'),
                'otp' => 'required',
            ]);

            $email = Email::where('email', $request->email)->first();
            if (!$email) {
                return response()->json([
                    'status' => 'Failed',
                    'message' => 'Email not found',
                ]);
            }

            if ($email->otp?->otp !== $request->otp) {
                return response()->json([
                    'status' => 'Failed',
                    'message' => 'Code verification was falied.',
                ]);
            }

            $user = $email->user;
            $user->passwords()->create([
                'password' => Hash::make($request->password),
            ]);

            $email->otp()->delete();

            return response()->json([
                'status' => 'Success',
                'message' => 'Password has been updated.'
            ]);
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'status' => 'Failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function sendLoginLink(Request $request)
    {
        $email = Email::where('email', $request->email)->first();
        if (!$email) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Credentials are wrong.',
            ], 401);
        }

        $signedUrl = URL::temporarySignedRoute(
            'loginUsingLink',
            Carbon::now()->addMinutes(15),
            ['id' => $email->id]
        );

        $query = parse_url($signedUrl, PHP_URL_QUERY);
        $frontendUrl = config('neev.frontend_url');
        $url = "{$frontendUrl}/login-link?{$query}";
    
        Mail::to($email->email)->send(new LoginUsingLink($url, 15));
        
        return response()->json([
            'status' => 'Success',
            'message' => 'Login link has been sent.',
        ]);
    }

    public function loginUsingLink(Request $request, GeoIP $geoIP)
    {
        if (! $request->hasValidSignature()) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Invalid or expired verification link.',
            ], 403);
        }

        $email = Email::find($request->id);
        if (!$email) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Invalid or expired verification link.',
            ], 403);
        }

        $token = $this->getToken(request: $request, geoIP: $geoIP, user: $email->user, method: LoginAttempt::MagicAuth);

        return response()->json([
            'status' => 'Success',
            'token' => $token,
            'email_verified' => $email->user->hasVerifiedEmail($email->email),
        ]);
    }

    public function verifyMFAOTP(Request $request, GeoIP $geoIP) {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'credentials are wrong.',
            ], 403);
        }

        $accessToken = AccessToken::find($request->attributes->get('token_id'));

        $attempt = $accessToken?->attempt;

        if (!$user->verifyMFAOTP($request->auth_method, $request->otp)) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Code verification was falied.'
            ], 401);
        }

        $accessToken->token_type = AccessToken::login;
        $accessToken->save();
        $attempt->is_success = true;
        $attempt->multi_factor_method = $request->auth_method;
        $attempt->save();

        return response()->json([
            'status' => 'Success',
            'token' => $request->bearerToken(),
            'email_verified' => $user->hasVerifiedEmail(),
        ]);
    }
}
