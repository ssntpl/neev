<?php

namespace Ssntpl\Neev\Http\Controllers\Auth;

use Auth;
use Carbon\Carbon;
use DB;
use Exception;
use Hash;
use Illuminate\Http\Request;
use Mail;
use Session;
use Ssntpl\Neev\Http\Controllers\Controller;
use Ssntpl\Neev\Http\Requests\Auth\LoginRequest;
use Ssntpl\Neev\Mail\VerifyUserEmail;
use Ssntpl\Neev\Models\LoginHistory;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\GeoIP;
use URL;
use Log;

class UserAuthController extends Controller
{
    public function login(LoginRequest $request, GeoIP $geoIP, $user, $method) 
    {
        $request->authenticate();

        $request->session()->regenerate();

        try {
            $clientDetails = LoginHistory::getClientDetails($request);
            $user->loginHistory()->create([
                'method' => $method,
                'location' => $geoIP?->getLocation($request->ip()),
                'platform' => $clientDetails['platform'] ?? '',
                'browser' => $clientDetails['browser'] ?? '',
                'device' => $clientDetails['device'] ?? '',
                'ip_address' => $request->ip(),
            ]);
        } catch (Exception $e) {
            Log::error($e->getMessage());
        }
    }

    /**
     * Show the register page.
    */
    public function registerCreate()
    {
        return view('neev::auth.register');
    }

    /**
     * Show the register store.
    */
    public function registerStore(LoginRequest $request, GeoIP $geoIP)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed'],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        if (config('neev.team')) {
            try {
                $team = Team::forceCreate([
                    'name' => explode(' ', $user->name, 2)[0]."'s Team",
                    'user_id' => $user->id,
                    'is_public' => false,
                ]);

                $team->users()->attach($user, ['joined' => true]);
            } catch (Exception $e) {
                $user->delete();
                return back()->withErrors(['message' => 'Unable to create team']);
            }
        }

        $this->login($request, $geoIP, $user, LoginHistory::Password);

        if (config('neev.email_verified')) {
            $signedUrl = URL::temporarySignedRoute(
                'verification.verify',
                Carbon::now()->addMinutes(60),
                ['id' => $user->id, 'hash' => sha1($user->email)]
            );
        
            Mail::to($user->email)->send(new VerifyUserEmail($signedUrl, $user->name, 'Verify Email', 60));
            
            return redirect(route('verification.notice'));
        }

        

        return redirect(config('neev.dashboard_url'));
    }

    /**
     * Show the login page.
    */
    public function loginCreate()
    {
        return view('neev::auth.login');
    }

    /**
     * Show the login password page.
    */
    public function loginPassword(LoginRequest $request)
    {
        $request->checkEmail();
        return view('neev::auth.login-password', ['email' => $request->email]);
    }
    
    /**
     * Show the login store.
    */
    public function loginStore(LoginRequest $request, GeoIP $geoIP)
    {
        $user = User::where('email', $request->email)->first();
        $this->login($request, $geoIP, $user, LoginHistory::Password);

        return redirect(config('neev.dashboard_url'));
    }

    /**
     * Show the forgot password page.
    */
    public function forgotPasswordCreate()
    {
        return view('neev::auth.forgot-password');
    }

    /**
     * Show the forgot password.
    */
    public function forgotPasswordLink(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email|max:255|',
        ]);

        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return back()->withErrors([
                'message' => __('User not registered or wrong email.'),
            ]);
        }

        $signedUrl = URL::temporarySignedRoute(
            'reset.request',
            Carbon::now()->addMinutes(30),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );
    
        Mail::to($user->email)->send(new VerifyUserEmail($signedUrl, $user->name, 'Forgot Password', 30));

        return back()->with('status', __('Link has been sent to your email address.'));
    }
    
    public function updatePasswordCreate(Request $request, $id, $hash)
    {
        $user = User::findOrFail($id);
    
        if (! $request->hasValidSignature()) {
            return response()->json(['message' => 'Invalid or expired verification link.'], 403);
        }
    
        if (sha1($user->email) !== $hash) {
            return response()->json(['message' => 'Invalid verification hash.'], 403);
        }

        return view('neev::auth.reset-password', ['email' => $user->email]);
    }
    
    public function updatePasswordStore(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email|max:255',
            'password' => ['required', 'confirmed'],
        ]);

        $user = User::where('email', $request->email)->first();
        $user->password = Hash::make($request->password);
        $user->save();
        return redirect('login');
    }

    /**
     * Show the verify email page.
    */
    public function emailVerifyCreate(Request $request)
    {
        $user = $request->user();
        if ($user->email_verified_at) {
            return redirect(config('neev.dashboard_url'));
        }
        return view('neev::auth.verify-email');
    }

    /**
     * Show the verify email send.
    */
    public function emailVerifySend(Request $request)
    {
        $user = $request->user();
        if ($user->email_verified_at) {
            return back()->with('status', __('Email already verified.'));
        }
        $signedUrl = URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );
    
        Mail::to($user->email)->send(new VerifyUserEmail($signedUrl, $user->name, 'Verify Email', 60));

        return back()->with('status', __('verification-link-sent'));
    }

    public function emailVerifyStore(Request $request, $id, $hash) {
        $user = User::findOrFail($id);
    
        if (! $request->hasValidSignature()) {
            return response()->json(['message' => 'Invalid or expired verification link.'], 403);
        }
    
        if (sha1($user->email) !== $hash) {
            return response()->json(['message' => 'Invalid verification hash.'], 403);
        }
    
        if (! $user->email_verified_at) {
            $user->email_verified_at = now();
            $user->save();
        }
    
        return redirect(config('neev.dashboard_url'));
    }

    /**
     * Show the logout.
    */
    public function destroy(Request $request)
    {
        Auth::logoutCurrentDevice();

        $request->session()->invalidate();

        return redirect(config('neev.home_url'));
    }

    public function destroyAll(Request $request)
    {
        $user = $request->user();
        
        if (!$request->session_id) {
            if (! Hash::check($request->password, $user->password)) {
                return back()->withErrors([
                    'password' => __('The password is incorrect.'),
                ]);
            }
    
            Auth::logoutOtherDevices($request->password);
    
            if (config('session.driver') === 'database') {
                DB::table('sessions')
                    ->where('user_id', $user->id)
                    ->where('id', '!=', Session::getId())
                    ->delete();
            }
        } else {
            if ($request->session_id == session()->getId()) {
                return back()->withErrors([
                    'error' => __('You cannot logout your current session.'),
                ]);
            }
            DB::table('sessions')->where('id', $request->session_id )->delete();
        }

       return back()->with('logoutStatus', __('Logged out from other sessions.'));
    }
}
