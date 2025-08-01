<?php

namespace Ssntpl\Neev\Http\Controllers;

use Hash;
use Illuminate\Http\Request;
use Jenssegers\Agent\Agent;
use Illuminate\Support\Facades\DB;
use Ssntpl\Neev\Models\User;

class UserController extends Controller
{
    public function profile(Request $request)
    {
        return view('neev::account.profile', ['user' => $request->user()]);
    }

    public function security(Request $request)
    {
        return view('neev::account.security', ['user' => $request->user()]);
    }

    public function tokens(Request $request)
    {
        return view('neev::account.tokens', ['user' => $request->user()]);
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
            'user' => $request->user(),
            'sessions' => $sessions
        ]);
    }

    public function loginHistory(Request $request)
    {
        $user = $request->user();
        $history = User::find($user->id)?->loginHistory()?->orderBy('created_at', 'desc')?->get();
        return view('neev::account.login-history', [
            'user' => $request->user(),
            'history' => $history,
        ]);
    }

    public function profileUpdate(Request $request)
    {
        $user = $request->user();
        $user->name = $request->name;
        if ($user->email != $request->email) {
            $user->email = $request->email;
            $user->email_verified_at = null;
        }
        $user->save();
        return redirect('/account/profile');
    }

    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => ['required'],
            'password' => ['required', 'confirmed'],
        ]);

        $user = $request->user();
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
        $user = $request->user();
        if (!Hash::check($request->password, $user->password)) {
            return back()->withErrors([
                'message' => 'Password is Wrong.'
            ]);
        }
        $user->delete();
        return redirect(route('login'));
    }
}
