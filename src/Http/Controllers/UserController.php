<?php

namespace Ssntpl\Neev\Http\Controllers;

use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Ssntpl\Neev\Mail\VerifyUserEmail;
use Ssntpl\Neev\Models\LoginAttempt;
use Ssntpl\Neev\Models\User;
use Ssntpl\LaravelAcl\Models\Permission;
use Ssntpl\Neev\Services\AuthService;
use Ssntpl\Neev\Services\EmailLinks;
use Ssntpl\Neev\Support\TeamRoles;

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
            return redirect()->route('login');
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
            return redirect()->route('login');
        }

        // The page names this user's role on every team row, and `getRole()`
        // is a query per call, so the two lists are resolved in one query
        // each. A team with no assignment is absent, and the view falls back
        // to the membership pivot as before.
        return view('neev::account.teams', [
            'user' => $user,
            'join_team' => true,
            'teamRoleNames' => TeamRoles::forResources($user, $user->teams->concat($user->teamRequests)),
        ]);
    }

    public function sessions(Request $request)
    {
        $sessions = app(AuthService::class)->sessionsTable()
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
            return redirect()->route('login');
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
        if (!$user) {
            return back()->withErrors([
                'message' => 'User not found.'
            ]);
        }

        if ($user->password === null) {
            return back()->withErrors([
                'message' => 'Your account has no password yet. Use the emailed link to set one.'
            ]);
        }

        if (!Hash::check($request->current_password, $user->password)) {
            return back()->withErrors([
                'message' => 'Current Password is Wrong.'
            ]);
        }

        app(AuthService::class)->changePassword($user, $request->password);
        return back()->with('status', 'Password has been successfully updated.');
    }

    public function sendPasswordResetLink(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return redirect(app(EmailLinks::class)->loginUrl());
        }

        $expiryMinutes = config('neev.url_expiry_time', 60);
        $url = app(EmailLinks::class)->passwordResetUrl($user, now()->addMinutes($expiryMinutes));

        Mail::to($user->email)->send(new VerifyUserEmail($url, $user->name, 'Forgot Password', $expiryMinutes));

        return back()->with('status', __('A password reset link has been sent to your email address.'));
    }

    /**
     * Email a code that confirms a sensitive action. Only for accounts
     * that hold no password; one with a password confirms with that.
     */
    public function sendConfirmationOtp(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return back()->withErrors(['message' => 'User not found.']);
        }

        app(AuthService::class)->sendConfirmationOtp($user);

        $message = __('A confirmation code has been sent to your email address.');

        if ($request->expectsJson()) {
            return response()->json(['message' => $message]);
        }

        return back()->with('status', $message);
    }

    public function accountDelete(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return back()->withErrors([
                'message' => 'Password is Wrong.'
            ]);
        }

        // Accounts registered through OAuth have no password. Demanding one
        // left them permanently unable to delete their account, since
        // Hash::check() against a null hash can never succeed. Those
        // confirm with an emailed code instead.
        $request->validate($user->password !== null
            ? ['password' => ['required']]
            : ['otp' => ['required']]);

        $confirmed = $user->password !== null
            ? Hash::check($request->password, $user->password)
            : app(AuthService::class)->verifyEmailOtp($user, (string) $request->otp);

        if (!$confirmed) {
            return back()->withErrors([
                'message' => $user->password !== null
                    ? 'Password is Wrong.'
                    : __('The confirmation code is invalid or has expired.'),
            ]);
        }

        $user->delete();
        return redirect(app(EmailLinks::class)->loginUrl());
    }

    public function addMultiFactorAuth(Request $request)
    {
        $request->validate([
            'auth_method' => ['required'],
        ]);

        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return back()->withErrors(['message' => 'User not found.']);
        }
        if ($request->action === 'delete') {
            if (!$user->removeMultiFactorAuth($request->auth_method)) {
                return back()->withErrors(['message' => 'Auth was not deleted.']);
            }

            return back()->with('status', 'Auth has been deleted.');
        }
        $attemptID = session('attempt_id');
        $attempt = $user->loginAttempts()->where('id', $attemptID)->first();
        if ($attempt) {
            $attempt->multi_factor_method = $request->auth_method;
            $attempt->save();
        }
        $res = $user->addMultiFactorAuth($request->auth_method);
        if (!$res) {
            return back()->withErrors(['message' => 'Auth was not added.']);
        }
        if (($res['status'] ?? null) === 'Error') {
            return back()->withErrors(['message' => $res['message'] ?? 'Auth was not added.']);
        }
        return back()->with($res);
    }

    public function preferredMultiFactorAuth(Request $request)
    {
        $request->validate([
            'auth_method' => ['required'],
        ]);

        $user = User::model()->find($request->user()?->id);
        $auth = $user?->multiFactorAuth($request->auth_method);
        if (!$user || !$auth || !$auth->isActive()) {
            return back()->withErrors(['message' => 'preferred auth was not updated.']);
        }
        $preferred = $user->preferredMultiFactorAuth;
        if ($preferred) {
            $preferred->preferred = false;
            $preferred->save();
        }
        $auth->preferred = true;
        $auth->save();
        return back()->with('status', 'preferred auth has been updated.');
    }

    public function recoveryCodes(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user || count($user->activeMultiFactorAuths) === 0) {
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
        if (!$user || count($user->activeMultiFactorAuths) === 0) {
            return back()->withErrors(['message' => 'Enable MFA first.']);
        }
        $user->recoveryCodes()->delete();
        return redirect()->route('recovery.codes');
    }

    public function tokenStore(Request $request)
    {
        $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'permissions' => ['sometimes', 'nullable', 'array'],
            'permissions.*' => ['string'],
            'expiry' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

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
        $request->validate([
            'permissions' => ['sometimes', 'nullable', 'array'],
            'permissions.*' => ['string'],
        ]);

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
