<?php

namespace Ssntpl\Neev\Http\Controllers;

use Hash;
use Illuminate\Http\Request;
use Jenssegers\Agent\Agent;
use Illuminate\Support\Facades\DB;
use Ssntpl\Neev\Http\Controllers\Auth\UserAuthController;
use Ssntpl\Neev\Models\Email;
use Ssntpl\Neev\Models\User;

class UserController extends Controller
{
    public function profile(Request $request)
    {
        return view('neev::account.profile', ['user' => User::find($request->user()->id)]);
    }

    public function emails(Request $request)
    {
        return view('neev::account.emails', ['user' => User::find($request->user()->id)]);
    }

    public function security(Request $request)
    {
        return view('neev::account.security', ['user' => User::find($request->user()->id)]);
    }

    public function tokens(Request $request)
    {
        return view('neev::account.tokens', ['user' => User::find($request->user()->id)]);
    }

    public function teams(Request $request)
    {
        $user = User::find($request->user()->id);
        return view('neev::account.teams', ['user' => $user]);
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
            'password' => ['required', 'confirmed'],
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
}
