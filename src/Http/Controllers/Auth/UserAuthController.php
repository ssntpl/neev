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
use Ssntpl\Neev\Mail\EmailOTP;
use Ssntpl\Neev\Mail\LoginUsingLink;
use Ssntpl\Neev\Mail\VerifyUserEmail;
use Ssntpl\Neev\Models\Email;
use Ssntpl\Neev\Models\LoginHistory;
use Ssntpl\Neev\Models\MultiFactorAuth;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Rules\PasswordCheck;
use Ssntpl\Neev\Services\GeoIP;
use URL;
use Log;

class UserAuthController extends Controller
{
    public function login(LoginRequest $request, GeoIP $geoIP, $user, $method, $mfa = null, $loginHistory = true) 
    {
        if (!$user->active) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'email' => 'Your account is deactivated, please contact your admin to activate your account.',
            ]);
        }
        $request->authenticate();
        $request->session()->regenerate();
        if ($loginHistory) {
            try {
                $clientDetails = LoginHistory::getClientDetails($request);
                $user->loginHistory()->create([
                    'method' => $method,
                    'location' => $geoIP?->getLocation($request->ip()),
                    'multi_factor_method' => $mfa,
                    'platform' => $clientDetails['platform'] ?? '',
                    'browser' => $clientDetails['browser'] ?? '',
                    'device' => $clientDetails['device'] ?? '',
                    'ip_address' => $request->ip(),
                ]);
            } catch (Exception $e) {
                Log::error($e->getMessage());
            }
        }
    }

    /**
     * Show the register page.
    */
    public function registerCreate()
    {
        return view('neev::auth.register');
    }

    public function emailChangeCreate(Request $request)
    {
        $user = User::find($request->user()->id);
        return view('neev::auth.change-email', ['email' => $user->email]);
    }

    public function emailChangeStore(Request $request)
    {
        $user = User::find($request->user()->id);
        $email = $user->primaryEmail;
        $email->email = $request->email;
        $email->save();
        $user->email = $request->email;
        $user->save();

        $signedUrl = URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        Mail::to($user->email)->send(new VerifyUserEmail($signedUrl, $user->name, 'Verify Email', 60));

        return redirect(route('verification.notice'));
    }

    /**
     * Show the register store.
    */
    public function registerStore(LoginRequest $request, GeoIP $geoIP)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', new PasswordCheck],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $user->emails()->create([
            'email' => $request->email
        ]);

        if (config('neev.team')) {
            try {
                $emailDomain = substr(strrchr($request->email, "@"), 1);

                $team = Team::where('federated_domain', $emailDomain)->first();
                if (!$team?->domain_verified_at) {
                    $team = Team::forceCreate([
                        'name' => explode(' ', $user->name, 2)[0]."'s Team",
                        'user_id' => $user->id,
                        'is_public' => false,
                    ]);
                }

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

        if ($request->action === 'link') {
            $email = Email::where('email', $request->email)->first();
            if (!$email) {
                return back()->withErrors('message', 'Credentials are wrong.');
            }

            $signedUrl = URL::temporarySignedRoute(
                'login.link',
                Carbon::now()->addMinutes(15),
                ['id' => $email->id, 'hash' => sha1($email->email)]
            );
        
            Mail::to($email->email)->send(new LoginUsingLink($signedUrl, 15));
            
            return back()->with('status', 'Login link has been sent.');
        }
        $rules = [];
        $isDomainFederated = false;
        if (config('neev.domain_federation')) {
            $emailDomain = substr(strrchr($request->email, "@"), 1);
            
            $team = Team::where('federated_domain', $emailDomain)->first();
            if ($team?->domain_verified_at) {
                $isDomainFederated = true;
                $rules['passkey'] = $team->rule('passkey')->value;
                $rules['oauth'] = json_decode($team->rule('oauth')->value, true) ?? [];
            }
        }
        
        return view('neev::auth.login-password', ['email' => $request->email, 'isDomainFederated' => $isDomainFederated, 'rules' => $rules]);
    }

    public function loginUsingLink(Request $request, $id, $hash, GeoIP $geoIP)
    {
        if (! $request->hasValidSignature()) {
            return response()->json(['message' => 'Invalid or expired verification link.'], 403);
        }

        $email = Email::find($id);
        if (sha1($email->email) !== $hash || !$email->verified_at) {
            return redirect(route('login'));
        }

        PasskeyController::login($request, $geoIP, $email->user, LoginHistory::MagicAuth);

        return redirect(config('neev.dashboard_url'));
    }
    
    /**
     * Show the login store.
    */
    public function loginStore(LoginRequest $request, GeoIP $geoIP)
    {
        $email = Email::where('email', $request->email)->first();
        if (!$email) {
            return back()->withErrors('message', 'Credentials are wrong.');
        }
        
        $user = $email->user;
        $this->login(request: $request, geoIP: $geoIP, user: $user, method: LoginHistory::Password, loginHistory: count($user->multiFactorAuths) === 0);

        if (count($user->multiFactorAuths) > 0) {
            session(['email' => $email->email]);
            return redirect(route('otp.mfa.create', $user->preferedMultiAuth?->method ?? $user->multiFactorAuths()->first()?->method));
        }

        if (!$email->verified_at && config('neev.email_verified')) {
            return redirect(route('verification.notice'));
        }
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
        $email = $user?->primaryEmail;
        if (!$user || !$email || !$email->verified_at) {
            return back()->withErrors([
                'message' => __('User not registered or wrong email.'),
            ]);
        }

        $signedUrl = URL::temporarySignedRoute(
            'reset.request',
            Carbon::now()->addMinutes(30),
            ['id' => $user->id, 'hash' => sha1($email->email)]
        );
    
        Mail::to($email->email)->send(new VerifyUserEmail($signedUrl, $user->name, 'Forgot Password', 30));

        return back()->with('status', __('Link has been sent to your email address.'));
    }
    
    public function updatePasswordCreate(Request $request, $id, $hash)
    {
        $user = User::findOrFail($id);
    
        if (! $request->hasValidSignature()) {
            return response()->json(['message' => 'Invalid or expired verification link.'], 403);
        }

        foreach ($user->emails as $email) {
            if (sha1($email->email) !== $hash || !$email->verified_at) {
                continue;
            }
            
            return view('neev::auth.reset-password', ['email' => $email->email]);
        }

        return response()->json(['message' => 'Invalid verification link.'], 403);
    }
    
    public function updatePasswordStore(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email|max:255',
            'password' => ['required', 'confirmed', new PasswordCheck],
        ]);

        $email = Email::where('email', $request->email)->first();
        if (!$email || !$email->verified_at) {
            return back()->withErrors('message', 'Failed to update password.');
        }
        $user = $email->user;
        $user->password = Hash::make($request->password);
        $user->save();
        return redirect('login');
    }

    /**
     * Show the verify email page.
    */
    public function emailVerifyCreate(Request $request)
    {
        $user = User::find($request->user()->id);
        if ($user->primaryEmail->verified_at) {
            return redirect(config('neev.dashboard_url'));
        }
        return view('neev::auth.verify-email', ['email' => $user->email]);
    }

    /**
     * Show the verify email send.
    */
    public function emailVerifySend(Request $request)
    {
        $user = User::find($request->user()->id);
        $email = $request->email ? Email::where('email', $request->email)->firstOrFail() : $user->primaryEmail;
        if ($email->verified_at) {
            return back()->with('status', __('Email already verified.'));
        }
        $signedUrl = URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($email->email)]
        );
    
        Mail::to($email->email)->send(new VerifyUserEmail($signedUrl, $user->name, 'Verify Email', 60));

        return back()->with('status', __('verification-link-sent'));
    }

    public function emailVerifyStore(Request $request, $id, $hash) {
        $user = User::findOrFail($id);
    
        if (! $request->hasValidSignature()) {
            return response()->json(['message' => 'Invalid or expired verification link.'], 403);
        }
    
        foreach ($user->emails as $email) {
            if (sha1($email->email) !== $hash) {
                continue;
            }
            
            if (!$email->verified_at) {
                $email->verified_at = now();
                $email->save();
            }
            return redirect(config('neev.dashboard_url'));
        }
        
        return response()->json(['message' => 'Invalid verification hash.'], 403);
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
        $user = User::find($request->user()->id);
        
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

    public function verifyMFAOTPCreate($method)
    {
        $email = session('email');
        if ($method === MultiFactorAuth::email()) {
            $user = Email::where('email', $email)->first()?->user;
            $auth = $user?->multiFactorAuth($method);
            if (!$auth) {
                return back()->withErrors(['message' => 'Inavlid Email.']);
            }
            if ($auth->expires_at && now()->lt($auth->expires_at)) {
                $auth->expires_at = now()->addMinutes(15);
                $auth->save();
            } else {
                $otp = rand(100000, 999999);
                $auth->otp = $otp;
                $auth->expires_at = now()->addMinutes(15);
                $auth->save();
                Mail::to($email)->send(new EmailOTP($user->name, $otp, 15));
            }
        }
        return view('neev::auth.otp-mfa',  ['email' => $email, 'method' => $method]);
    }

    public function emailOTPSend()
    {
        $email = session('email');
        $user = Email::where('email', $email)->first()?->user;
        $auth = $user?->multiFactorAuth(MultiFactorAuth::email());
        if (!$auth) {
            return back()->withErrors(['message' => 'Inavlid Email.']);
        }
        $otp = rand(100000, 999999);
        $auth->otp = $otp;
        $auth->expires_at = now()->addMinutes(15);
        $auth->save();
        Mail::to($email)->send(new EmailOTP($user->name, $otp, 15));
        return back()->with('status', 'Verification link has been sent.');
    }

    public function verifyMFAOTPStore(LoginRequest $request, GeoIP $geoIP) {
        $email = Email::where('email', $request->email)->first();
        $user = $email?->user ?? User::find($request->user()?->id);

        if (!$user) {
            return back()->withErrors(['message' => 'credentials are wrong.']);
        }

        if ($user->verifyMFAOTP($request->auth_method, $request->otp)) {
            if ($request->action === 'verify') {
                return back()->with('status', 'Code verified.');
            } 

            PasskeyController::login($request, $geoIP, $user, LoginHistory::Password, MultiFactorAuth::UIName($request->auth_method));
            return redirect(config('neev.dashboard_url'));
        }

        return back()->withErrors(['message' => 'Code is invalid']);
    }
}
