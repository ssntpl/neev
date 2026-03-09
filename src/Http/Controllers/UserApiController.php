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
        $request->validate([
            'email' => ['required', 'email', Email::uniqueRule()],
        ]);

        $email = Email::find($request->email_id);
        if (!$email || $email->user?->id !== $request->user()?->id) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Email was not updated.'
            ], 400);
        }

        $email->email = $request->email;
        $email->save();

        $result = self::sendMailVerification($email);

        return response()->json([
            'status' => 'Success',
            'message' => 'Email has been updated.',
            'verification_method' => $result['method'] ?? 'link'
        ]);
    }

    public function getUser(Request $request)
    {
        return response()->json([
            'status' => 'Success',
            'data' => $request->user()?->load('emails', 'teams'),
        ]);
    }

    public function getMFAMethods(Request $request)
    {
        $user = User::model()->find($request->user()?->id);

        $res = $user?->multiFactorAuths;
        return response()->json([
            'status' => 'Success',
            'data' => $res
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
            ], 400);
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
                'message' => 'Unable to update account.',
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

        $method = config('neev.email_verification_method', 'link');

        if ($method === 'otp') {
            self::sendMailOTP($email, false);
            return ['method' => 'otp'];
        }

        // Default link method
        self::sendMailLink($email);
        return ['method' => 'link'];
    }

    public static function sendMailLink(Email $email)
    {
        $user = $email->user;
        if (!$user) {
            return false;
        }

        $signedUrl = URL::temporarySignedRoute(
            'mail.verify',
            now()->addMinutes(config('neev.url_expiry_time', 60)),
            ['id' => $email->id]
        );

        $query = parse_url($signedUrl, PHP_URL_QUERY);
        $frontendUrl = config('app.url');
        $url = "{$frontendUrl}/verify-email?{$query}";
        Mail::to($email->email)->send(new VerifyUserEmail($url, $user->name, 'Verify Email', 60));
    }

    public static function sendMailOTP(Email $email, $mfa = false)
    {
        $otp = random_int(10 ** (config('neev.otp_length', 6) - 1), (10 ** config('neev.otp_length', 6)) - 1);
        $expiryMinutes = config('neev.otp_expiry_time', 15);
        $expires_at = now()->addMinutes($expiryMinutes);
        if ($mfa) {
            $auth = $email->user?->multiFactorAuth('email');
            if (!$auth) {
                return response()->json([
                    'status' => 'Failed',
                    'message' => 'Auth type not found.',
                ], 400);
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

        Mail::to($email->email)->send(new EmailOTP($email->user?->name, $otp, $expiryMinutes));
    }

    public function addEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email|max:255',
        ]);

        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'User not found.',
            ], 404);
        }
        if (Email::findByEmail($request->email)) {
            return response()->json([
                'status' => 'Success',
                'message' => 'Email already exist.',
            ]);
        }

        $email = $user->emails()->create([
            'email' => $request->email
        ]);

        $result = self::sendMailVerification($email);

        return response()->json([
            'status' => 'Success',
            'message' => 'Email has been added.',
            'data' => $email,
            'verification_method' => $result['method'] ?? 'link'
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
        if (!$email || !$email->verified_at) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Your primary email was not changed.',
            ], 400);
        }

        $pemail = $user->email;
        if ($pemail) {
            if ($pemail->id == $email->id) {
                return response()->json([
                    'status' => 'Success',
                    'message' => 'Your primary email is already set.',
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
            ], 404);
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
            ], 404);
        }
        $attempts = $user->loginAttempts()->orderBy('created_at', 'desc')->get();
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
                'message' => 'Unable to change password.',
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
            ], 404);
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
            ], 404);
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
        $token = $user?->accessTokens->find($request->token_id);
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
            ], 404);
        }
        $token = $user->accessTokens->find($request->token_id);
        if (!$token) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Token not found.',
            ], 404);
        }
        $token->delete();

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
            ], 404);
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
            ], 404);
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

    public function setPreferredMFA(Request $request)
    {
        $request->validate([
            'auth_method' => ['required'],
        ]);

        $user = User::model()->find($request->user()?->id);
        $auth = $user?->multiFactorAuth($request->auth_method);

        if (!$auth) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'MFA method not found.',
            ], 400);
        }

        // Remove preferred from all other methods
        $user->multiFactorAuths()->update(['preferred' => false]);

        // Set this method as preferred
        $auth->preferred = true;
        $auth->save();

        return response()->json([
            'status' => 'Success',
            'message' => 'Preferred MFA method updated.',
        ]);
    }
}
