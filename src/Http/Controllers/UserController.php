<?php

namespace Ssntpl\Neev\Http\Controllers;

use Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Ssntpl\Neev\Http\Controllers\Auth\UserAuthController;
use Ssntpl\Neev\Models\Domain;
use Ssntpl\Neev\Models\Email;
use Ssntpl\Neev\Models\LoginAttempt;
use Ssntpl\Neev\Models\User;
use Ssntpl\LaravelAcl\Models\Permission;

class UserController extends Controller
{
    public function profile(Request $request)
    {
        return view('neev::account.profile', ['user' => User::model()->find($request->user()->id)]);
    }

    public function emails(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return redirect()->route('neev.login');
        }
        $emailDomain = substr(strrchr($user->email?->email, "@"), 1);

        $addEmail = true;
        if (config('neev.team') && config('neev.domain_federation')) {
            $domain = Domain::where('domain', $emailDomain)->first();
            if ($domain?->verified_at && $domain?->team?->users->contains($user)) {
                $addEmail = false;
            }
        }
        return view('neev::account.emails', ['user' => $user, 'add_email' => $addEmail]);
    }

    public function security(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return redirect()->route('neev.login');
        }
        $emailDomain = substr(strrchr($user->email?->email, "@"), 1);

        $deleteAccount = true;
        if (config('neev.team') && config('neev.domain_federation')) {
            $domain = Domain::where('domain', $emailDomain)->first();
            if ($domain?->verified_at && $domain?->team?->users->contains($user)) {
                $deleteAccount = false;
            }
        }
        return view('neev::account.security', ['user' => $user, 'delete_account' => $deleteAccount]);
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
        $emailDomain = substr(strrchr($user->email?->email, "@"), 1);

        $join_team = true;
        if (config('neev.domain_federation')) {
            $domain = Domain::where('domain', $emailDomain)->first();
            if ($domain?->verified_at) {
                $join_team = false;
            }
        }
        return view('neev::account.teams', ['user' => $user, 'join_team' => $join_team]);
    }

    public function sessions(Request $request)
    {
        $sessions = DB::table('sessions')
            ->where('user_id', auth()->id())
            ->orderBy('last_activity', 'desc')
            ->get()
            ?->map(function ($session) {
                $agent = LoginAttempt::getClientDetails(userAgent: $session->user_agent);

                return (object)[
                    'id' => $session->id,
                    'ip_address' => $session->ip_address,
                    'is_current_device' => $session->id === session()->getId(),
                    'last_active' => Carbon::createFromTimestamp($session->last_activity)?->diffForHumans(),
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
        $attempts = User::model()->find($user->id)?->loginAttempts()?->orderBy('created_at', 'desc')->get();
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
            $usernameRules = array_filter($usernameRules, function($rule) {
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

    public function addEmail(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return back()->withErrors(['message' => 'User not found.']);
        }
        if (Email::where('email', $request->email)->first()) {
            return back()->withErrors(['message' => 'Email already exist.']);
        }

        $user->emails()->create([
            'email' => $request->email
        ]);

        $auth = app(UserAuthController::class);
        $auth->emailVerifySend($request);

        return back()->with('status', 'Email has been Added.');
    }

    public function deleteEmail(Request $request)
    {
        $user = User::model()->find($request->user()?->id);

        $email = $user?->emails?->find($request->email_id);
        if (!$email) {
            return back()->withErrors(['message' => 'Email does not exist.']);
        }
        
        if ($email->is_primary) {
            return back()->withErrors(['message' => 'Cannot delete primary email.']);
        }

        $email->delete();

        return back()->with('status', 'Email has been Deleted.');
    }

    public function primaryEmail(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        $email = $user?->emails?->where('email', $request->email)->first();
        if ( !$user || !$email || !$email?->verified_at) {
            return back()->withErrors(['message' => 'Your primary email was not changed.']);
        }
        
        $pemail = $user->email;
        $pemail->is_primary = false;
        $pemail->save();
        $email->is_primary = true;
        $email->save();

        return back()->with('status', 'Your primary email has been changed.');
    }

    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => ['required'],
            'password' => config('neev.password'),
        ]);

        $user = User::model()->find($request->user()?->id);
        if (!$user || !Hash::check($request->current_password, $user->password?->password)) {
            return back()->withErrors([
                'message' => 'Current Password is Wrong.'
            ]);
        }

        $user->passwords()->create([
            'password' => Hash::make($request->password),
        ]);
        return back()->with('status', 'Password has been successfully updated.');
    }

    public function accountDelete(Request $request)
    {
        $request->validate([
            'password' => ['required'],
        ]);
        $user = User::model()->find($request->user()?->id);
        if (!$user || !Hash::check($request->password, $user->password?->password)) {
            return back()->withErrors([
                'message' => 'Password is Wrong.'
            ]);
        }
        $user->delete();
        return redirect(route('login'));
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
            $auth = $user->multiFactorAuth($request->auth_method);
            if (!$auth) {
                return back()->withErrors(['message' => 'Auth was not deleted.']);
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

            return back()->with('status', 'Auth has been deleted.');
        }
        $res = $user->addMultiFactorAuth($request->auth_method);
        if (!$res) {
            return back()->withErrors(['message' => 'Auth was not deleted.']);
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
        if (!$user || !$auth) {
            return back()->withErrors(['message' => 'preferred auth was not updated.']);
        }
        $preferred = $user->preferredMultiFactorAuth;
        $preferred->preferred = false;
        $preferred->save();
        $auth->preferred = true;
        $auth->save();
        return back()->with('status', 'preferred auth has been updated.');
    }

    public function recoveryCodes(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (count($user?->multiFactorAuths) === 0) {
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
        if (count($user?->multiFactorAuths) === 0) {
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
        $token = $user->accessTokens?->find($request->token_id);
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
