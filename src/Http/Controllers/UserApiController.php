<?php

namespace Ssntpl\Neev\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Ssntpl\Neev\Mail\EmailOTP;
use Ssntpl\Neev\Mail\VerifyUserEmail;
use Ssntpl\Neev\Models\Email;
use Ssntpl\Neev\Models\User;

class UserApiController extends Controller
{
    public function emailUpdate(Request $request)
    {
        $email = Email::find($request->email_id);
        if (!$email || $email->user?->id !== $request->user()?->id) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Email was not updated.'
            ]);
        }

        $email->email = $request->email;
        $email->save();

        self::sendMailVerification($email);

        return response()->json([
            'status' => 'Success',
            'message' => 'Email has been updated.'
        ]);
    }

    public function getUser(Request $request)
    {
        return response()->json([
            'status' => 'Success',
            'data' => $request->user()?->load('emails', 'teams'),
        ]);
    }

    public function addMultiFactorAuthentication(Request $request)
    {
        $request->validate([
            'auth_method' => ['required'],
        ]);

        $user = User::model()->find($request->user()?->id);

        $res = $user?->addMultiFactorAuth($request->auth_method);
        if (!$res) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Auth was not added.',
            ]);
        }
        return response()->json($res);
    }

    public function deleteMultiFactorAuthentication(Request $request)
    {
        $request->validate([
            'auth_method' => ['required'],
        ]);

        $user = User::model()->find($request->user()?->id);
        $auth = $user?->multiFactorAuth($request->auth_method);
        if (!$auth) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Auth was not deleted.',
            ], 403);
        }

        if ($auth->preferred && count($user->multiFactorAuths) > 1) {
            $method = $user->multiFactorAuths()->whereNot('method', $auth->method)->first();
            $method->preferred = true;
            $method->save();
        }
        $auth->delete();

        if (count($user->multiFactorAuths) <= 1) {
            $user->recoveryCodes()->delete();
        }

        return response()->json([
            'status' => 'Success',
            'message' => 'Auth has been deleted.',
        ]);
    }

    public function updateUser(Request $request)
    {
        try {
            $user = User::model()->find($request->user()?->id);
            if ($request->name) {
                $user->name = $request->name;
            }
            if ($request->username) {
                $usernameRules = config('neev.username');
                // Remove unique rule for current user
                $usernameRules = array_filter($usernameRules, function ($rule) {
                    return !str_contains($rule, 'unique:');
                });
                $usernameRules[] = 'unique:users,username,' . $request->user()->id;
                $validationRules['username'] = $usernameRules;

                $request->validate($validationRules);
                $user->username = $request->username;
            }
            $user->save();

            return response()->json([
                'status' => 'Success',
                'message' => 'Account has been updated.',
                'data' => $user
            ]);
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'status' => 'Failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function deleteUser(Request $request)
    {
        $request->validate([
            'password' => ['required'],
        ]);

        $user = User::model()->find($request->user()?->id);
        if (!Hash::check($request->password, $user->password?->password)) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Password is Wrong.',
            ], 403);
        }

        $user->delete();
        return response()->json([
            'status' => 'Success',
            'message' => 'Account has been deleted.',
        ]);
    }

    public static function sendMailVerification($email)
    {
        $user = $email?->user;
        if (!$user) {
            return false;
        }
        $signedUrl = URL::temporarySignedRoute(
            'mail.verify',
            now()->addMinutes(config('neev.url_expiry_time', 60)),
            ['id' => $email->id]
        );

        $query = parse_url($signedUrl, PHP_URL_QUERY);
        $frontendUrl = config('neev.frontend_url');
        $url = "{$frontendUrl}/verify-email?{$query}";
        Mail::to($email->email)->send(new VerifyUserEmail($url, $user->name, 'Verify Email', 60));
    }

    public static function sendMailOTP(Email $email, $mfa = false)
    {
        $otp = random_int(config('neev.otp_min', 100000), config('neev.otp_max', 999999));
        $expiryMinutes = config('neev.otp_expiry_time', 15);
        $expires_at = now()->addMinutes($expiryMinutes);
        if ($mfa) {
            $auth = $email->user?->multiFactorAuth('email');
            if (!$auth) {
                return response()->json([
                    'status' => 'Failed',
                    'message' => 'Auth type not found.',
                ]);
            }
            $auth->otp = $otp;
            $auth->expires_at = $expires_at;
            $auth->save();
        } else {
            $email->otp()->updateOrCreate([], [
                'otp' => $otp,
                'expires_at' => $expires_at,
            ]);
        }

        Mail::to($email?->email)->send(new EmailOTP($email->user?->name, $otp, $expiryMinutes));
    }

    public function addEmail(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'User not found.',
            ]);
        }
        if (Email::where('email', $request->email)->first()) {
            return response()->json([
                'status' => 'Success',
                'message' => 'Email already exist.',
            ]);
        }

        $email = $user->emails()->create([
            'email' => $request->email
        ]);

        self::sendMailVerification($email);

        return response()->json([
            'status' => 'Success',
            'message' => 'Email has been added.',
            'data' => $email
        ]);
    }

    public function deleteEmail(Request $request)
    {
        $user = User::model()->find($request->user()?->id);

        $email = $user?->emails?->where('email', $request->email)->first();
        if (!$email) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Email does not exist.',
            ], 403);
        }

        if ($email->is_primary) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Cannot delete primary email.',
            ], 403);
        }

        $email->delete();

        return response()->json([
            'status' => 'Success',
            'message' => 'Email has been deleted.',
        ]);
    }

    public function primaryEmail(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        $email = $user?->emails?->where('email', $request->email)->first();
        if (!$email || !$email?->verified_at) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Your primary email was not changed.',
            ]);
        }

        $pemail = $user->email;
        if ($pemail) {
            if ($pemail->id == $email->id) {
                return response()->json([
                    'status' => 'Success',
                    'message' => 'Your primary email was aready changed.',
                ]);
            }
            $pemail->is_primary = false;
            $pemail->save();
        }
        $email->is_primary = true;
        $email->save();

        return response()->json([
            'status' => 'Success',
            'message' => 'Your primary email has been changed.'
        ]);
    }

    public function sessions(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'User not found.',
            ]);
        }
        $sessions = $user->loginTokens()->with('attempt')->orderBy('last_used_at', 'desc')->get();

        return response()->json([
            'status' => 'Success',
            'data' => $sessions
        ]);
    }

    public function loginAttempts(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'User not found.',
            ]);
        }
        $attempts = $user?->loginAttempts()?->orderBy('created_at', 'desc')?->get();
        return response()->json([
            'status' => 'Success',
            'data' => $attempts,
        ]);
    }

    public function changePassword(Request $request)
    {
        try {
            $request->validate([
                'current_password' => ['required'],
                'password' => config('neev.password'),
            ]);

            $user = User::model()->find($request->user()->id);
            if (!Hash::check($request->current_password, $user->password->password)) {
                return response()->json([
                    'status' => 'Failed',
                    'message' => 'Current Password is Wrong.',
                ], 403);
            }

            $user->passwords()->create([
                'password' => Hash::make($request->password),
            ]);

            return response()->json([
                'status' => 'Success',
                'message' => 'Password has been successfully updated.',
            ]);
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'status' => 'Failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function getApiTokens(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'User not found.',
            ]);
        }
        $tokens = $user->apiTokens()->orderBy('created_at', 'desc')->get();

        return response()->json([
            'status' => 'Success',
            'data' => $tokens,
        ]);
    }

    public function addApiTokens(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'User not found.',
            ]);
        }
        $token = $user->createApiToken($request->name, $request->permissions, $request->expiry);

        return response()->json([
            'status' => 'Success',
            'message' => 'Token has been added.',
            'data' => $token,
        ]);
    }

    public function updateApiTokens(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        $token = $user?->accessTokens?->find($request->token_id);
        if (!$token) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Token was not updated.',
            ], 400);
        }

        if ($request->permissions) {
            $token->permissions = $request->permissions;
        }
        if ($request->name) {
            $token->name = $request->name;
        }
        if (isset($request->expiry)) {
            $token->expires_at = $request->expiry ? now()->addMinutes($request->expiry) : null;
        }

        $token->save();

        return response()->json([
            'status' => 'Success',
            'message' => 'Token has been updated.',
            'data' => $token,
        ]);
    }

    public function deleteApiTokens(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'User not found.',
            ]);
        }
        $user->accessTokens?->find($request->token_id)->delete();

        return response()->json([
            'status' => 'Success',
            'message' => 'Token has been deleted.'
        ]);
    }

    public function deleteAllApiTokens(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'User not found.',
            ]);
        }
        $user->apiTokens()->delete();

        return response()->json([
            'status' => 'Success',
            'message' => 'All tokens have been deleted.'
        ]);
    }

    public function generateRecoveryCodes(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'User not found.',
            ]);
        }
        if (count($user->multiFactorAuths) === 0) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Enable MFA first.',
            ], 400);
        }
        $codes = $user->generateRecoveryCodes();

        return response()->json([
            'status' => 'Success',
            'message' => 'New recovery codes are generated.',
            'data' => $codes,
        ]);
    }
}
