<?php

namespace Ssntpl\Neev\Http\Controllers;

use Hash;
use Illuminate\Http\Request;
use Jenssegers\Agent\Agent;
use Illuminate\Support\Facades\DB;
use Ssntpl\Neev\Http\Controllers\Auth\UserAuthController;
use Ssntpl\Neev\Models\Email;
use Ssntpl\Neev\Models\Permission;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Rules\PasswordLogic;
use Ssntpl\Neev\Rules\PasswordValidate;

class UserController extends Controller
{
    public function profile(Request $request)
    {
        return view('neev::account.profile', ['user' => User::find($request->user()->id)]);
    }

    public function emails(Request $request)
    {
        $user = User::find($request->user()->id);
        $emailDomain = substr(strrchr($user->email, "@"), 1);

        $addEmail = true;
        $team = Team::where('federated_domain', $emailDomain)->first();
        if ($team?->domain_verified_at && $team->users->contains($user)) {
            $addEmail = false;
        }
        return view('neev::account.emails', ['user' => $user, 'add_email' => $addEmail]);
    }

    public function security(Request $request)
    {
        $user = User::find($request->user()->id);
        $emailDomain = substr(strrchr($user->email, "@"), 1);

        $deleteAccount = true;
        $team = Team::where('federated_domain', $emailDomain)->first();
        if ($team?->domain_verified_at && $team->users->contains($user)) {
            $deleteAccount = false;
        }
        return view('neev::account.security', ['user' => $user, 'delete_account' => $deleteAccount]);
    }

    public function tokens(Request $request)
    {
        return view('neev::account.tokens', ['user' => User::find($request->user()->id), 'allPermissions' => Permission::all()]);
    }

    public function teams(Request $request)
    {
        $user = User::find($request->user()->id);
        $emailDomain = substr(strrchr($user->email, "@"), 1);

        $join_team = true;
        $team = Team::where('federated_domain', $emailDomain)->first();
        if ($team?->domain_verified_at) {
            $join_team = false;
        }
        return view('neev::account.teams', ['user' => $user, 'join_team' => $join_team]);
    }

    public function sessions(Request $request)
    {
        $sessions = DB::table('sessions')
            ->where('user_id', auth()->id())
            ->orderBy('last_activity', 'desc')
            ->get()
            ->map(function ($session) {
                $agent = new Agent();
                $agent->setUserAgent($session->user_agent);

                return (object)[
                    'id' => $session->id,
                    'ip_address' => $session->ip_address,
                    'is_current_device' => $session->id === session()->getId(),
                    'last_active' => \Carbon\Carbon::createFromTimestamp($session->last_activity)->diffForHumans(),
                    'agent' => $agent,
                ];
            });

        return view('neev::account.sessions', [
            'user' => User::find($request->user()->id),
            'sessions' => $sessions
        ]);
    }

    public function loginHistory(Request $request)
    {
        $user = User::find($request->user()->id);
        $history = User::find($user->id)?->loginHistory()?->orderBy('created_at', 'desc')?->get();
        return view('neev::account.login-history', [
            'user' => $user,
            'history' => $history,
        ]);
    }

    public function profileUpdate(Request $request)
    {
        $user = User::find($request->user()->id);
        $user->name = $request->name;
        $user->save();
        return back()->with(['status' => 'Account has been updated.']);
    }

    public function addEmail(Request $request)
    {
        $user = User::find($request->user()->id);
        if (Email::where('email', $request->email)->first()) {
            return back()->withErrors(['message' => 'Email already exist.']);
        }

        $user->emails()->create([
            'email' => $request->email
        ]);

        $auth = new UserAuthController;
        $auth->emailVerifySend($request);

        return back()->with('status', 'Email has been Added.');
    }

    public function deleteEmail(Request $request)
    {
        $user = User::find($request->user()->id);

        $email = $user->emails->find($request->email_id);
        if (!$email) {
            return back()->withErrors(['message' => 'Email does not exist.']);
        }
        
        if ($email->email == $user->email) {
            return back()->withErrors(['message' => 'Cannot delete primary email.']);
        }

        $email->delete();

        return back()->with('status', 'Email has been Deleted.');
    }

    public function primaryEmail(Request $request)
    {
        $user = User::find($request->user()->id);

        if (!$user || !$user->emails->where('email', $request->email)->first()?->verified_at) {
            return back()->withErrors(['message' => 'Your primary email was not changed.']);
        }
        
        $user->email = $request->email;
        $user->save();

        return back()->with('status', 'Your primary email has been changed.');
    }

    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => ['required'],
            'password' => ['required', 'confirmed', new PasswordValidate, new PasswordLogic],
        ]);

        $user = User::find($request->user()->id);
        if (!Hash::check($request->current_password, $user->password)) {
            return back()->withErrors([
                'message' => 'Current Password is Wrong.'
            ]);
        }
        $user->password = Hash::make($request->password);
        $user->save();
        return back()->with('status', 'Password has been successfully updated.');
    }

    public function accountDelete(Request $request)
    {
        $request->validate([
            'password' => ['required'],
        ]);
        $user = User::find($request->user()->id);
        if (!Hash::check($request->password, $user->password)) {
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

        $user = User::find($request->user()->id);
        if ($request->action === 'delete') {
            $auth = $user->multiFactorAuth($request->auth_method);
            if (!$auth) {
                return back()->withErrors(['message' => 'Auth was not deleted.']);
            }

            if ($auth->prefered && count($user->multiFactorAuths) > 1) {
                $method = $user->multiFactorAuths()->whereNot('method', $auth->method)->first();
                $method->prefered = true;
                $method->save();
            }
            $auth->delete();

            if (count($user->multiFactorAuths) <= 1) {
                $user->recoveryCodes()->delete();
            }

            return back()->with('status', 'Auth has been deleted.');
        }
        $res = $user->addMultiFactorAuth($request->auth_method);
        return $res;
    }

    public function preferedMultiFactorAuth(Request $request)
    {
        $request->validate([
            'auth_method' => ['required'],
        ]);

        $user = User::find($request->user()->id);
        $auth = $user?->multiFactorAuth($request->auth_method);
        if (!$user || !$auth) {
            return back()->withErrors(['message' => 'Prefered auth was not updated.']);
        }
        $prefered = $user->preferedMultiFactorAuth;
        $prefered->prefered = false;
        $prefered->save();
        $auth->prefered = true;
        $auth->save();
        return back()->with('status', 'Prefered auth has been updated.');
    }

    public function recoveryCodes(Request $request)
    {
        $user = User::find($request->user()->id);
        if (count($user->multiFactorAuths) === 0) {
            return back()->withErrors(['message' => 'Enable MFA first.']);
        }
        return view('neev::account.recovery-codes', ['user' => $user]);
    }

    public function generateRecoveryCodes(Request $request)
    {
        $user = User::find($request->user()->id);
        if (count($user->multiFactorAuths) === 0) {
            return back()->withErrors(['message' => 'Enable MFA first.']);
        }
        $user->generateRecoveryCodes();
        return back()->with('status', 'New recovery codes are generated.');
    }

    public function tokenStore(Request $request)
    {
        $user = User::find($request->user()->id);
        $token = $user->createApiToken($request->name, $request->permissions, $request->expiry);
        return back()->with('token', $token->plainTextToken);
    }

    public function tokenDelete(Request $request)
    {
        $user = User::find($request->user()->id);
        $user->accessTokens->find($request->token_id)->delete();
        return back()->with('status', 'Token has been deleted.');
    }

    public function tokenDeleteAll(Request $request)
    {
        $user = User::find($request->user()->id);
        $user->apiTokens()->delete();
        return back()->with('status', 'All tokens have been deleted.');
    }

    public function tokenUpdate(Request $request)
    {
        $user = User::find($request->user()->id);
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
