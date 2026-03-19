<?php

namespace Ssntpl\Neev\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\AuthService;

class UserApiController extends Controller
{
    public function getUser(Request $request)
    {
        return response()->json([
            'data' => $request->user()?->load('teams'),
        ]);
    }

    public function getMFAMethods(Request $request)
    {
        $user = User::model()->find($request->user()?->id);

        return response()->json([
            'data' => $user?->multiFactorAuths,
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

                $request->validate(['username' => $usernameRules]);
                $user->username = $request->username;
            }
            $user->save();

            return response()->json([
                'message' => 'Account has been updated.',
                'data' => $user
            ]);
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
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
        if (!Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Password is Wrong.',
            ], 403);
        }

        $user->delete();
        return response()->json([
            'message' => 'Account has been deleted.',
        ]);
    }

    public function sessions(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'message' => 'User not found.',
            ], 404);
        }
        $sessions = $user->loginTokens()->with('attempt')->orderBy('last_used_at', 'desc')->get();

        return response()->json([
            'data' => $sessions
        ]);
    }

    public function loginAttempts(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'message' => 'User not found.',
            ], 404);
        }
        $attempts = $user->loginAttempts()->orderBy('created_at', 'desc')->get();
        return response()->json([
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
            if (!Hash::check($request->current_password, $user->password)) {
                return response()->json([
                    'message' => 'Current Password is Wrong.',
                ], 403);
            }

            app(AuthService::class)->changePassword($user, $request->password);

            return response()->json([
                'message' => 'Password has been successfully updated.',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'message' => 'Unable to change password.',
            ], 500);
        }
    }

    public function getApiTokens(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'message' => 'User not found.',
            ], 404);
        }
        $tokens = $user->apiTokens()->orderBy('created_at', 'desc')->get();

        return response()->json([
            'data' => $tokens,
        ]);
    }

    public function addApiTokens(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'message' => 'User not found.',
            ], 404);
        }
        $token = $user->createApiToken($request->name, $request->permissions, $request->expiry);

        return response()->json([
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
            'message' => 'Token has been updated.',
            'data' => $token,
        ]);
    }

    public function deleteApiTokens(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'message' => 'User not found.',
            ], 404);
        }
        $token = $user->accessTokens->find($request->token_id);
        if (!$token) {
            return response()->json([
                'message' => 'Token not found.',
            ], 404);
        }
        $token->delete();

        return response()->json([
            'message' => 'Token has been deleted.'
        ]);
    }

    public function deleteAllApiTokens(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'message' => 'User not found.',
            ], 404);
        }
        $user->apiTokens()->delete();

        return response()->json([
            'message' => 'All tokens have been deleted.'
        ]);
    }

    public function generateRecoveryCodes(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'message' => 'User not found.',
            ], 404);
        }
        if (count($user->multiFactorAuths) === 0) {
            return response()->json([
                'message' => 'Enable MFA first.',
            ], 400);
        }
        $codes = $user->generateRecoveryCodes();

        return response()->json([
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
                'message' => 'MFA method not found.',
            ], 400);
        }

        // Remove preferred from all other methods
        $user->multiFactorAuths()->update(['preferred' => false]);

        // Set this method as preferred
        $auth->preferred = true;
        $auth->save();

        return response()->json([
            'message' => 'Preferred MFA method updated.',
        ]);
    }
}
