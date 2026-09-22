<?php

namespace Ssntpl\Neev\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;
use Ssntpl\Neev\Models\AccessToken;
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
        if (($res['status'] ?? null) === 'Error') {
            return response()->json([
                'message' => $res['message'] ?? 'Auth was not added.',
            ], 422);
        }
        return response()->json($res);
    }

    public function verifyMfaSetup(Request $request)
    {
        $request->validate([
            'auth_method' => ['required', 'string'],
            'otp' => ['required'],
        ]);

        $user = User::model()->find($request->user()?->id);
        if (!$user?->verifyMfaSetup($request->auth_method, (string) $request->otp)) {
            return response()->json([
                'message' => 'Code verification failed.',
            ], 400);
        }

        return response()->json([
            'message' => 'Method has been verified and enabled.',
            'method' => $request->auth_method,
        ]);
    }

    public function deleteMultiFactorAuthentication(Request $request)
    {
        $request->validate([
            'auth_method' => ['required'],
        ]);

        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'message' => 'User not found.',
            ], 404);
        }

        // Taking a second factor off the account is the one change that makes
        // every future sign-in easier, so it is confirmed like the other
        // account-security actions: whoever holds a stolen token should not be
        // able to remove the factor that would have stopped them using it.
        $auth = app(AuthService::class);
        $request->validate($auth->confirmationRules($user));

        if (!$auth->confirmIdentity($user, $request)) {
            return response()->json([
                'message' => $user->password !== null
                    ? 'Password is Wrong.'
                    : 'The confirmation code is invalid or has expired.',
            ], 403);
        }

        if (!$user->removeMultiFactorAuth($request->auth_method)) {
            return response()->json([
                'message' => 'Auth was not deleted.',
            ], 403);
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

    /**
     * Email a code that confirms a sensitive action.
     *
     * The code is the one /email/send issues; that endpoint refuses an
     * address already verified, which every passwordless account is, so
     * it could not serve this.
     */
    public function sendConfirmationOtp(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'message' => 'User not found.',
            ], 404);
        }

        app(AuthService::class)->sendConfirmationOtp($user);

        return response()->json([
            'message' => 'A confirmation code has been sent to your email address.',
        ]);
    }

    public function deleteUser(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'message' => 'Password is Wrong.',
            ], 403);
        }

        // An account holding a password proves itself with it; one without —
        // OAuth and SSO registrations — with a code from
        // POST /confirmation/otp. See AuthService::confirmationRules().
        $auth = app(AuthService::class);
        $request->validate($auth->confirmationRules($user));

        if (!$auth->confirmIdentity($user, $request)) {
            return response()->json([
                'message' => $user->password !== null
                    ? 'Password is Wrong.'
                    : 'The confirmation code is invalid or has expired.',
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

    public function deleteSession(Request $request, int $id)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'message' => 'User not found.',
            ], 404);
        }

        if ($id === (int) $request->attributes->get('token_id')) {
            return response()->json([
                'message' => 'You cannot revoke your current session. Use logout instead.',
            ], 400);
        }

        $session = $user->loginTokens()->find($id);
        if (!$session) {
            return response()->json([
                'message' => 'Session not found.',
            ], 404);
        }

        $session->delete();

        return response()->json([
            'message' => 'Session has been revoked.',
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
            if (!$user) {
                return response()->json([
                    'message' => 'User not found.',
                ], 404);
            }

            if ($user->password === null) {
                return response()->json([
                    'message' => 'Your account has no password yet. Use the emailed link to set one.',
                ], 403);
            }

            if (!Hash::check($request->current_password, $user->password)) {
                return response()->json([
                    'message' => 'Current Password is Wrong.',
                ], 403);
            }

            app(AuthService::class)->changePassword($user, $request->password);

            return response()->json([
                'message' => 'Password has been successfully updated.',
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'message' => 'Unable to change password.',
            ], 500);
        }
    }

    /**
     * Refuse a grant wider than the granting credential.
     *
     * Defence in depth behind `refuseApiTokenCredential()`: today only a login
     * token reaches these routes and it holds everything, so nothing is
     * refused here. It is the invariant that keeps that true — if the routes
     * are ever opened to scoped tokens, or an application calls these
     * controllers from its own stack, a token still cannot hand on an ability
     * it does not itself have.
     *
     * @param  array<int, string>  $permissions
     */
    private function refuseWiderGrant(Request $request, array $permissions): ?JsonResponse
    {
        $credential = $request->attributes->get('neev.access_token');

        if ($credential instanceof AccessToken && !$credential->canGrant($permissions)) {
            return response()->json([
                'message' => 'A token cannot grant a permission it does not hold.',
            ], 403);
        }

        return null;
    }

    /**
     * Shape the token fields, so a malformed `permissions` is a rejection
     * rather than a TypeError from `createApiToken()`.
     */
    private function validateTokenFields(Request $request): void
    {
        $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'permissions' => ['sometimes', 'nullable', 'array'],
            'permissions.*' => ['string'],
            'expiry' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);
    }

    public function getApiTokens(Request $request)
    {
        if ($refusal = $this->refuseApiTokenCredential($request, 'An API token cannot manage API tokens.')) {
            return $refusal;
        }

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
        if ($refusal = $this->refuseApiTokenCredential($request, 'An API token cannot manage API tokens.')) {
            return $refusal;
        }
        $this->validateTokenFields($request);
        if ($refusal = $this->refuseWiderGrant($request, (array) $request->input('permissions', []))) {
            return $refusal;
        }

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
        if ($refusal = $this->refuseApiTokenCredential($request, 'An API token cannot manage API tokens.')) {
            return $refusal;
        }
        $this->validateTokenFields($request);
        if ($refusal = $this->refuseWiderGrant($request, (array) $request->input('permissions', []))) {
            return $refusal;
        }

        $user = User::model()->find($request->user()?->id);

        // Resolved among API tokens only. Through `accessTokens` this endpoint
        // also reached the caller's own login token, whose id is the `{id}|`
        // prefix of their bearer string — so an `expiry` here reset the
        // absolute ceiling `NeevAPIMiddleware::slideExpiry()` enforces, and a
        // stolen session token could be made to outlive it.
        $token = $user?->apiTokens()->find($request->token_id);
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
        if ($refusal = $this->refuseApiTokenCredential($request, 'An API token cannot manage API tokens.')) {
            return $refusal;
        }

        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'message' => 'User not found.',
            ], 404);
        }
        // API tokens only, as above. A login token is a session: revoke it
        // through `DELETE {prefix}/sessions/{id}` or by logging out.
        $token = $user->apiTokens()->find($request->token_id);
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
        if ($refusal = $this->refuseApiTokenCredential($request, 'An API token cannot manage API tokens.')) {
            return $refusal;
        }

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
        if (count($user->activeMultiFactorAuths) === 0) {
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

        if (!$auth || !$auth->isActive()) {
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
