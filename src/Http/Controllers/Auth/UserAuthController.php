<?php

namespace Ssntpl\Neev\Http\Controllers\Auth;

use Auth;
use Carbon\Carbon;
use DB;
use Exception;
use Hash;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Mail;
use Session;
use Ssntpl\Neev\Http\Controllers\Controller;
use Ssntpl\Neev\Http\Controllers\Auth\PasskeyController;
use Ssntpl\Neev\Http\Requests\Auth\LoginRequest;
use Ssntpl\Neev\Mail\EmailOTP;
use Ssntpl\Neev\Mail\LoginUsingLink;
use Ssntpl\Neev\Mail\VerifyUserEmail;
use Ssntpl\Neev\Models\Email;
use Ssntpl\Neev\Models\LoginAttempt;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\TeamInvitation;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\GeoIP;
use URL;
use Log;

class UserAuthController extends Controller
{
    public function login(LoginRequest $request, GeoIP $geoIP, $user, $method, $mfa = null, $attempt = null) 
    {
        if (!$user->active) {
            throw ValidationException::withMessages([
                'email' => 'Your account is deactivated, please contact your admin to activate your account.',
            ]);
        }
        $request->authenticate();
        $request->session()->regenerate();
        try {
            if ($attempt) {
                $attempt->is_success = true;
                $attempt->save();
            } else {
                $clientDetails = LoginAttempt::getClientDetails($request);
                $user->loginAttempts()->create([
                    'method' => $method,
                    'location' => $geoIP?->getLocation($request->ip()),
                    'multi_factor_method' => $mfa,
                    'platform' => $clientDetails['platform'] ?? '',
                    'browser' => $clientDetails['browser'] ?? '',
                    'device' => $clientDetails['device'] ?? '',
                    'ip_address' => $request->ip(),
                    'is_success' => true,
                ]);
            }
        } catch (Exception $e) {
            Log::error($e->getMessage());
        }
    }

    /**
     * Show the register page.
    */
    public function registerCreate(Request $request)
    {
        if (config('neev.team') && ($request->id || $request->hash)) {
            if (!$request->hasValidSignature()) {
                return back()->withErrors(['message' => 'Invalid or expired invitation link.']);
            }
            $invitation = TeamInvitation::find($request->id);
            if (!$invitation || sha1($invitation?->email) !== $request->hash) {
                return back()->withErrors(['message' => 'Invalid or expired invitation link.']);
            }
            return view('neev::auth.register', ['id' => $request->id, 'hash' => $request->hash, 'email' => $invitation->email ?? null]);
        }
        $input = $request->email;
        $isEmail = filter_var($input, FILTER_VALIDATE_EMAIL);
        
        return view('neev::auth.register', [
            'email' => $isEmail ? $input : null,
            'username' => !$isEmail ? $input : null
        ]);
    }

    public function emailChangeCreate(Request $request)
    {
        $user = User::model()->find($request->user()->id);
        return view('neev::auth.change-email', ['email' => $user?->email?->email]);
    }

    public function emailChangeStore(Request $request)
    {
        $user = User::model()->find($request->user()->id);
        $email = $user->email;
        $email->email = $request->email;
        $email->save();

        $signedUrl = URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email->email)]
        );

        Mail::to($user->email->email)->send(new VerifyUserEmail($signedUrl, $user->name, 'Verify Email', 60));

        return redirect(route('verification.notice'));
    }

    /**
     * Show the register store.
    */
    public function registerStore(LoginRequest $request, GeoIP $geoIP)
    {
        $validationRules = [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:'.Email::class,
            'password' => config('neev.password'),
        ];
        
        if (config('neev.support_username')) {
            $validationRules['username'] = config('neev.username');
        }
        
        $request->validate($validationRules);

        try {
            if (config('neev.support_username')) {
                $user = User::model()->create([
                    'name' => $request->name,
                    'username' => $request->username,
                ]);
            } else {
                $user = User::model()->create([
                    'name' => $request->name,
                ]);
            } 
            $user = User::model()->find($user->id);
            
            $email = $user->emails()->create([
                'email' => $request->email,
                'is_primary' => true
            ]);
            
            $password = $user->passwords()->create([
                'password' => Hash::make($request->password),
            ]);

            if (config('neev.team')) {
                try {
                    if ($request->invitation_id) {
                        $invitation = TeamInvitation::find($request->invitation_id);
                        if (!$invitation || sha1($invitation?->email) !== $request->hash) {
                            $user->delete();
                            return back()->withErrors(['message' => 'Invalid or expired invitation link.']);
                        }

                        $email->verified_at = now();
                        $email->save();

                        $team = $invitation->team;
                        $team->users()->attach($user, ['role' => $invitation->role ?? '', 'joined' => true]);
                        if ($invitation?->role) {
                            $user->assignRole($invitation->role, $team);
                        }
                        $invitation->delete();
                    } else {
                        if (config('neev.domain_federation')) {
                            $emailDomain = substr(strrchr($request->email, "@"), 1);
                            $team = Team::model()->where('federated_domain', $emailDomain)->first();
                            if (!$team?->domain_verified_at) {
                                $team = Team::model()->forceCreate([
                                    'name' => explode(' ', $user->name, 2)[0]."'s Team",
                                    'user_id' => $user->id,
                                    'is_public' => false,
                                ]);
                            }
                        } else {
                            $team = Team::model()->forceCreate([
                                'name' => explode(' ', $user->name, 2)[0]."'s Team",
                                'user_id' => $user->id,
                                'is_public' => false,
                            ]);
                        }
                        $team->users()->attach($user, ['joined' => true, 'role' => $team->default_role ?? '']);
                        if ($team->default_role) {
                            $user->assignRole($team->default_role ?? '', $team);
                        }
                    }

                } catch (Exception $e) {
                    $user->delete();
                    Log::error($e->getMessage());
                    return back()->withErrors(['message' => 'Unable to create team']);
                }
            }
            
            $this->login($request, $geoIP, $user, LoginAttempt::Password);

            if (!$email->verified_at) {
                $signedUrl = URL::temporarySignedRoute(
                    'verification.verify',
                    Carbon::now()->addMinutes(60),
                    ['id' => $user->id, 'hash' => sha1($email->email)]
                );
            
                Mail::to($email->email)->send(new VerifyUserEmail($signedUrl, $user->name, 'Verify Email', 60));
                
                if (config('neev.email_verified')) {
                    return redirect(route('verification.notice'));
                }
            }

            return redirect(config('neev.dashboard_url'));
        } catch (Exception $e) {
            Log::error($e->getMessage());
            return back()->withErrors(['message' => 'Unable to register user.']);
        }
    }

    /**
     * Show the login page.
    */
    public function loginCreate(Request $request)
    {
        return view('neev::auth.login', ['redirect' => $request->redirect]);
    }

    /**
     * Show the login password page.
    */
    public function loginPassword(LoginRequest $request)
    {
        if (config('neev.support_username') && !preg_match('/^[\w.%+\-]+@[\w.\-]+\.[A-Za-z]{2,}$/', $request->email)) {
            $user = User::model()->where('username', $request->email)->first();
            if ($user) {
                $request->merge(['username' => $user->username]);
                $request->merge(['email' => $user->email->email]);
            }
        }
        
        $user = $request->checkEmail();
        if (!$user) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $rules = [];
        $isDomainFederated = false;
        if (config('neev.team') && config('neev.domain_federation')) {
            $emailDomain = substr(strrchr($request->email, "@"), 1);
            
            $team = Team::model()->where('federated_domain', $emailDomain)->first();
            if ($team?->domain_verified_at) {
                $isDomainFederated = true;
                $rules['passkey'] = $team->rule('passkey')->value;
                $rules['oauth'] = json_decode($team->rule('oauth')->value, true) ?? [];
            }
        }
        
        if (config('neev.support_username') && !empty($request->username)) {
            return view('neev::auth.login-password', ['email' => $request->email, 'username' => $request->username, 'isDomainFederated' => $isDomainFederated, 'rules' => $rules, 'redirect' => $request->redirect, 'email_verified' => $user->hasVerifiedEmail()]);
        }
        return view('neev::auth.login-password', ['email' => $request->email, 'isDomainFederated' => $isDomainFederated, 'rules' => $rules, 'redirect' => $request->redirect, 'email_verified' => $user->hasVerifiedEmail()]);
    }

    public function sendLoginLink(Request $request)
    {
        $email = Email::where('email', $request->email)->first();
        if (!$email) {
            return back()->withErrors('message', 'Credentials are wrong.');
        }

        $signedUrl = URL::temporarySignedRoute(
            'login.link',
            Carbon::now()->addMinutes(15),
            ['id' => $email->id]
        );
    
        Mail::to($email->email)->send(new LoginUsingLink($signedUrl, 15));
        
        return back()->with('status', 'Login link has been sent.');
    }

    public function loginUsingLink(Request $request, $id, GeoIP $geoIP)
    {
        if (! $request->hasValidSignature()) {
            return response()->json(['message' => 'Invalid or expired verification link.'], 403);
        }

        $email = Email::find($id);
        if (config('neev.email_verified') && !$email->verified_at) {
            return redirect(route('login'));
        }

        PasskeyController::login($request, $geoIP, $email->user, LoginAttempt::MagicAuth);

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
        if (config('neev.fail_attempts_in_db')) {
            $clientDetails = LoginAttempt::getClientDetails($request);
            $attempt = $user->loginAttempts()->create([
                'method' => LoginAttempt::Password,
                'location' => $geoIP?->getLocation($request->ip()),
                'multi_factor_method' => null,
                'platform' => $clientDetails['platform'] ?? '',
                'browser' => $clientDetails['browser'] ?? '',
                'device' => $clientDetails['device'] ?? '',
                'ip_address' => $request->ip(),
                'is_success' => false,
            ]);
        }
        $this->login(request: $request, geoIP: $geoIP, user: $user, method: LoginAttempt::Password, attempt: $attempt ?? null);

        if (count($user->multiFactorAuths) > 0) {
            session(['email' => $email->email, 'attempt_id' => $attempt->id]);
            return redirect(route('otp.mfa.create', $user->preferedMultiAuth?->method ?? $user->multiFactorAuths()->first()?->method));
        }

        if ($request->redirect) {
            return redirect($request->redirect);
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

        $email = Email::where('email', $request->email)->first();
        $user = $email?->user;
        if (!$user || !$email || (config('neev.email_verified') && !$email->verified_at)) {
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
        $user = User::model()->findOrFail($id);
    
        if (! $request->hasValidSignature()) {
            return response()->json(['message' => 'Invalid or expired verification link.'], 403);
        }

        foreach ($user->emails as $email) {
            if (sha1($email->email) !== $hash || (config('neev.email_verified') && !$email->verified_at)) {
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
            'password' => config('neev.password'),
        ]);

        $email = Email::where('email', $request->email)->first();
        if (!$email || (config('neev.email_verified') && !$email->verified_at)) {
            return back()->withErrors('message', 'Failed to update password.');
        }
        $user = $email->user;
        $user->passwords()->create([
            'password' => Hash::make($request->password),
        ]);
        return redirect('login');
    }

    /**
     * Show the verify email page.
    */
    public function emailVerifyCreate(Request $request)
    {
        $user = User::model()->find($request->user()->id);
        if (!$user) {
            return redirect(route('login'));
        }
        if ($user->email->verified_at) {
            return redirect(config('neev.dashboard_url'));
        }
        return view('neev::auth.verify-email', ['email' => $user->email->email]);
    }

    /**
     * Show the verify email send.
    */
    public function emailVerifySend(Request $request)
    {
        $user = User::model()->find($request->user()->id);
        $email = $request->email ? Email::where('email', $request->email)->firstOrFail() : $user->email;
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
        $user = User::model()->findOrFail($id);
        $logedinUser = User::model()->find($request->user()?->id);
        if (!$user || !$logedinUser || $logedinUser?->id != $user?->id) {
            return redirect(route('login') . '?redirect=' . urlencode($request->fullUrl()))->withErrors(['message' => __('Please login first to verify your email.')]);
        }
        
        $email = null;
        foreach ($user->emails as $item) {
            if (sha1($item->email) == $hash) {
                $email = $item;
                break;
            }
        }
        
        if ($email && $request->hasValidSignature()) {
            $email->verified_at = now();
            $email->save();
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

        return redirect(route('login'));
    }

    public function destroyAll(Request $request)
    {
        $user = User::model()->find($request->user()->id);
        
        if (!$request->session_id) {
            if (! Hash::check($request->password, $user->password->password)) {
                return back()->withErrors([
                    'password' => __('The password is incorrect.'),
                ]);
            }
    
            if (config('session.driver') === 'database') {
                DB::table('sessions')
                    ->where('user_id', $user->id)
                    ->where('id', '!=', Session::getId())
                    ->delete();
            } else {
                $request->session()->regenerate(true);
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
        $attemptID = session('attempt_id');
        if ($method === 'email') {
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
        return view('neev::auth.otp-mfa',  ['email' => $email, 'method' => $method, 'attempt_id' => $attemptID]);
    }

    public function emailOTPSend()
    {
        $email = session('email');
        $user = Email::where('email', $email)->first()?->user;
        $auth = $user?->multiFactorAuth('email');
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
        $user = $email?->user ?? User::model()->find($request->user()?->id);

        if (!$user) {
            return back()->withErrors(['message' => 'credentials are wrong.']);
        }
        $attempt = LoginAttempt::find($request->attempt_id);
        if ($attempt) {
            $attempt->is_success = false;
            $attempt->mfa = $request->auth_method;
            $attempt->save();
        }
        if ($user->verifyMFAOTP($request->auth_method, $request->otp)) {
            if ($request->action === 'verify') {
                return back()->with('status', 'Code verified.');
            } 

            PasskeyController::login($request, $geoIP, $user, LoginAttempt::Password, $request->auth_method, $attempt ?? null);
            return redirect(config('neev.dashboard_url'));
        }

        return back()->withErrors(['message' => 'Code is invalid']);
    }
}
