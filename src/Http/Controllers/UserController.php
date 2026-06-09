<?php

namespace Ssntpl\Neev\Http\Controllers;

use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Ssntpl\Neev\Models\LoginAttempt;
use Ssntpl\Neev\Models\MultiFactorAuth;
use Ssntpl\Neev\Models\User;
use Ssntpl\LaravelAcl\Models\Permission;
use Ssntpl\Neev\Services\AuthService;

class UserController extends Controller
{
    public function profile(Request $request)
    {
        $user = User::model()->find($request->user()->id);
        return view('neev::account.profile', ['user' => $user]);
    }

    public function security(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return redirect()->route('neev.login');
        }
        $user->loadMissing('multiFactorAuths', 'passkeys');

        return view('neev::account.security', ['user' => $user, 'delete_account' => true]);
    }

    public function tokens(Request $request)
    {
        return view('neev::account.tokens', ['user' => User::model()->find($request->user()?->id), 'allPermissions' => Permission::all()]);
    }

    public function teams(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return redirect()->route('neev.login');
        }

        return view('neev::account.teams', ['user' => $user, 'join_team' => true]);
    }

    public function sessions(Request $request)
    {
        $sessions = DB::table('sessions')
            ->where('user_id', auth()->id())
            ->orderBy('last_activity', 'desc')
            ->get()
            ->map(function ($session) {
                $agent = LoginAttempt::getClientDetails(userAgent: $session->user_agent);

                return (object)[
                    'id' => $session->id,
                    'ip_address' => $session->ip_address,
                    'is_current_device' => $session->id === session()->getId(),
                    'last_active' => Carbon::createFromTimestamp($session->last_activity)->diffForHumans(),
                    'agent' => $agent,
                ];
            });

        return view('neev::account.sessions', [
            'user' => User::model()->find($request->user()?->id),
            'sessions' => $sessions
        ]);
    }

    public function loginAttempts(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return redirect()->route('neev.login');
        }
        $attempts = $user->loginAttempts()->orderBy('created_at', 'desc')->get();
        return view('neev::account.login-attempt', [
            'user' => $user,
            'attempts' => $attempts,
        ]);
    }

    public function profileUpdate(Request $request)
    {
        $validationRules = [
            'name' => 'required|string|max:255',
        ];

        if (config('neev.support_username')) {
            $usernameRules = config('neev.username');
            // Remove unique rule for current user
            $usernameRules = array_filter($usernameRules, function ($rule) {
                return !str_contains($rule, 'unique:');
            });
            $usernameRules[] = 'unique:users,username,' . $request->user()?->id;
            $validationRules['username'] = $usernameRules;
        }

        $request->validate($validationRules);

        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return back()->withErrors(['message' => 'User not found.']);
        }
        $user->name = $request->name;
        if (config('neev.support_username')) {
            $user->username = $request->username;
        }
        $user->save();
        return back()->with(['status' => 'Account has been updated.']);
    }

    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => ['required'],
            'password' => config('neev.password'),
        ]);

        $user = User::model()->find($request->user()?->id);
        if (!$user || !Hash::check($request->current_password, $user->password)) {
            return back()->withErrors([
                'message' => 'Current Password is Wrong.'
            ]);
        }

        app(AuthService::class)->changePassword($user, $request->password);
        return back()->with('status', 'Password has been successfully updated.');
    }

    public function accountDelete(Request $request)
    {
        $request->validate([
            'password' => ['required'],
        ]);
        $user = User::model()->find($request->user()?->id);
        if (!$user || !Hash::check($request->password, $user->password)) {
            return back()->withErrors([
                'message' => 'Password is Wrong.'
            ]);
        }
        $user->delete();
        return redirect(route('login'));
    }

    public function addMultiFactorAuth(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return back()->withErrors(['message' => 'User not found.']);
        }
        if ($request->action === 'delete') {
            $request->validate([
                'id' => ['required'],
            ]);
            $auth = $user->multiFactorAuths()->find($request->id);
            if (!$auth) {
                return back()->withErrors(['message' => 'Auth was not deleted.']);
            }

            $auth->delete();

            if ($user->activeMultiFactorAuths()->count() <= 1) {
                $user->recoveryCodes()->delete();
            }

            return back()->with('status', 'Auth has been deleted.');
        }
        $request->validate([
            'auth_method' => ['required'],
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email'],
        ]);
        $attemptID = session('attempt_id');
        $attempt = $user->loginAttempts()->where('id', $attemptID)->first();
        if ($attempt) {
            $attempt->multi_factor_method = $request->auth_method;
            $attempt->save();
        }
        $res = $user->addMultiFactorAuth($request->auth_method, $request->name, null, $request->email);
        if (!$res) {
            return back()->withErrors(['message' => 'Auth was not added.']);
        }

        // A non-account email starts pending — mail it a code to verify control.
        if (($res['method'] ?? null) === 'email' && !empty($res['id'])) {
            $auth = $user->multiFactorAuths()->find($res['id']);
            if ($auth && $auth->status === MultiFactorAuth::STATUS_PENDING) {
                $auth->sendEmailOtp($user);
            }
        }

        return back()->with($res);
    }

    public function recoveryCodes(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (count($user?->activeMultiFactorAuths) === 0) {
            return back()->withErrors(['message' => 'Enable MFA first.']);
        }

        if (count($user->recoveryCodes) === 0) {
            $codes = $user->generateRecoveryCodes();
        }

        return view('neev::account.recovery-codes', ['codes' => $codes ?? []]);
    }

    public function generateRecoveryCodes(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (count($user?->activeMultiFactorAuths) === 0) {
            return back()->withErrors(['message' => 'Enable MFA first.']);
        }
        $user->recoveryCodes()->delete();
        return redirect()->route('recovery.codes');
    }

    public function tokenStore(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return back()->withErrors(['message' => 'User not found.']);
        }
        $token = $user->createApiToken($request->name, $request->permissions, $request->expiry);
        if (!$token) {
            return back()->withErrors(['message' => 'Token was not created.']);
        }
        return back()->with('token', $token->plainTextToken);
    }

    public function tokenDelete(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return back()->withErrors(['message' => 'User not found.']);
        }
        $token = $user->accessTokens->find($request->token_id);
        if (!$token) {
            return back()->withErrors(['message' => 'Token was not deleted.']);
        }
        $token->delete();
        return back()->with('status', 'Token has been deleted.');
    }

    public function tokenDeleteAll(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return back()->withErrors(['message' => 'User not found.']);
        }
        $user->apiTokens()->delete();
        return back()->with('status', 'All tokens have been deleted.');
    }

    public function tokenUpdate(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return back()->withErrors(['message' => 'User not found.']);
        }
        $token = $user->accessTokens->find($request->token_id);
        if (!$token) {
            return back()->withErrors(['message' => 'Token was not updated.']);
        }

        if (!empty($request->permissions)) {
            $token->permissions = $request->permissions;
        }

        $token->save();
        return back()->with('status', 'Token has been updated.');
    }
}
