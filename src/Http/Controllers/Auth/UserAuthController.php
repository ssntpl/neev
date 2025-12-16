<?php

namespace Ssntpl\Neev\Http\Controllers\Auth;

use Ssntpl\Neev\Events\LoggedOutEvent;
use Auth;
use DB;
use Exception;
use Hash;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Mail;
use Session;
use Ssntpl\Neev\Http\Controllers\Controller;
use Ssntpl\Neev\Http\Requests\Auth\LoginRequest;
use Ssntpl\Neev\Mail\EmailOTP;
use Ssntpl\Neev\Mail\LoginUsingLink;
use Ssntpl\Neev\Mail\VerifyUserEmail;
use Ssntpl\Neev\Models\Domain;
use Ssntpl\Neev\Models\Email;
use Ssntpl\Neev\Models\LoginAttempt;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\TeamInvitation;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\AuthService;
use Ssntpl\Neev\Services\GeoIP;
use URL;
use Log;

class UserAuthController extends Controller
{
    protected AuthService $auth;

    public function __construct(AuthService $auth)
    {
        $this->auth = $auth;
    }
    /**
     * Show the register page.
    */
    public function registerCreate(Request $request)
    {
        if ($request->user()?->id) {
            return redirect(config('neev.dashboard_url'));
        }
        if (config('neev.team') && ($request->id || $request->hash)) {
            if (!$request->hasValidSignature()) {
                return back()->withErrors(['message' => 'Invalid or expired invitation link.']);
            }
            $invitation = TeamInvitation::find($request->id);
            if (!$invitation || sha1($invitation?->email) !== $request->hash) {
                return back()->withErrors(['message' => 'Invalid or expired invitation link.']);
            }
            return view('neev::auth.register', ['id' => $request->id, 'hash' => $request->hash, 'email' => $invitation?->email ?? null]);
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
        $user = User::model()->find($request->user()?->id);
        return view('neev::auth.change-email', ['email' => $user?->email?->email]);
    }

    public function emailChangeStore(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        $email = $user?->email;
        if (!$user || !$email) {
            return back()->withErrors(['message' => 'User not found.']);
        }
        $email->email = $request->email;
        $email->save();

        $expiryMinutes = config('neev.url_expiry_time', 60);
        $signedUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes($expiryMinutes),
            ['id' => $user->id, 'hash' => sha1($user->email?->email)]
        );

        Mail::to($user->email?->email)->send(new VerifyUserEmail($signedUrl, $user->name, 'Verify Email', $expiryMinutes));
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
            DB::beginTransaction();
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
            
            $user->passwords()->create([
                'password' => Hash::make($request->password),
            ]);

            if (config('neev.team')) {
                if ($request->invitation_id) {
                    $invitation = TeamInvitation::find($request->invitation_id);
                    if (!$invitation || sha1($invitation?->email) !== $request->hash) {
                        DB::rollBack();
                        return back()->withErrors(['message' => 'Invalid or expired invitation link.']);
                    }

                    $email->update(['verified_at' => now()]);

                    $team = $invitation->team;
                    $team->users()->attach($user, ['role' => $invitation->role ?? '', 'joined' => true]);
                    if ($invitation?->role) {
                        $user->assignRole($invitation->role, $team);
                    }
                    $invitation->delete();
                } else {
                    $shouldCreateTeam = !config('neev.domain_federation') || !$this->isDomainVerified($request->email);
                    
                    if ($shouldCreateTeam) {
                        $team = $this->createUserTeam($user);
                        $team->users()->attach($user, ['joined' => true, 'role' => $team->default_role ?? '']);
                        if ($team->default_role) {
                            $user->assignRole($team->default_role ?? '', $team);
                        }
                    }
                }
            }
            DB::commit();
            $this->auth->login($request, $geoIP, $user, LoginAttempt::Password);

            if (!$email->verified_at) {
                $expiryMinutes = config('neev.url_expiry_time', 60);
                $signedUrl = URL::temporarySignedRoute(
                    'verification.verify',
                    now()->addMinutes($expiryMinutes),
                    ['id' => $user->id, 'hash' => sha1($email->email)]
                );
            
                Mail::to($email->email)->send(new VerifyUserEmail($signedUrl, $user->name, 'Verify Email', $expiryMinutes));
                
                if (config('neev.email_verified')) {
                    return redirect(route('verification.notice'));
                }
            }

            return redirect(config('neev.dashboard_url'));
        } catch (Exception $e) {
            DB::rollBack();
            Log::error($e);
            return back()->withErrors(['message' => 'Unable to register user.']);
        }
    }

    /**
     * Show the login page.
    */
    public function loginCreate(Request $request)
    {
        if ($request->user()?->id) {
            return redirect(config('neev.dashboard_url'));
        }
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

        $isDomainFederated = false;
        if (config('neev.team') && config('neev.domain_federation')) {
            $emailDomain = substr(strrchr($request->email, "@"), 1);
            
            $domain = Domain::where('domain', $emailDomain)->first();
            if ($domain?->verified_at) {
                $isDomainFederated = true;
            }
        }

        $loginOptions = [];
        if (count($user->passkeys) > 0) {
            $loginOptions[] = 'passkey';
        }
        
        $viewData = [
            'email' => $request->email,
            'isDomainFederated' => $isDomainFederated,
            'redirect' => $request->redirect,
            'email_verified' => $user->hasVerifiedEmail(),
            'login_options' => $loginOptions
        ];
        
        if (config('neev.support_username') && !empty($request->username)) {
            $viewData['username'] = $request->username;
        }

        return view('neev::auth.login-password', $viewData);
    }

    public function sendLoginLink(Request $request)
    {
        $email = Email::where('email', $request->email)->first();
        if (!$email) {
            return back()->withErrors(['message' => 'Credentials are wrong.']);
        }

        $expiryMinutes = config('neev.url_expiry_time', 60);
        $signedUrl = URL::temporarySignedRoute(
            'login.link',
            now()->addMinutes($expiryMinutes),
            ['id' => $email->id]
        );
    
        Mail::to($email->email)->send(new LoginUsingLink($signedUrl, $expiryMinutes));
        
        return back()->with('status', 'Login link has been sent.');
    }

    public function loginUsingLink(Request $request, $id, GeoIP $geoIP)
    {
        if ($request->user()?->id) {
            return redirect(config('neev.dashboard_url'));
        }
        if (! $request->hasValidSignature()) {
            return redirect(route('login'))->withErrors(['message' => 'Invalid or expired login link.']);
        }

        $email = Email::find($id);
        if (!$email || config('neev.email_verified') && !$email?->verified_at) {
            return redirect(route('login'));
        }

        $this->auth->login($request, $geoIP, $email?->user, LoginAttempt::MagicAuth);

        return redirect(config('neev.dashboard_url'));
    }
    
    /**
     * Show the login store.
    */
    public function loginStore(LoginRequest $request, GeoIP $geoIP)
    {
        $email = Email::where('email', $request->email)->first();
        $user = $email?->user;
        if (!$email || !$user) {
            return back()->withErrors(['message' => 'Credentials are wrong.']);
        }
        
        if (config('neev.record_failed_login_attempts')) {
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
        $this->auth->login(request: $request, geoIP: $geoIP, user: $user, method: LoginAttempt::Password, attempt: $attempt ?? null, viaRequestAuth: true);

        if (count($user->multiFactorAuths) > 0) {
            session(['email' => $email->email]);
            return redirect(route('otp.mfa.create', $user->preferredMultiAuth?->method ?? $user->multiFactorAuths()->first()?->method));
        }

        if ($request->redirect && $request->redirect != '/') {
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
        if (request()->user()?->id) {
            return redirect(config('neev.dashboard_url'));
        }
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
        if (!$user || !$email || (config('neev.email_verified') && !$email?->verified_at)) {
            return back()->withErrors([
                'message' => __('User not registered or wrong email.'),
            ]);
        }

        $expiryMinutes = config('neev.url_expiry_time', 60);
        $signedUrl = URL::temporarySignedRoute(
            'reset.request',
            now()->addMinutes($expiryMinutes),
            ['id' => $user->id, 'hash' => sha1($email->email)]
        );
    
        Mail::to($email->email)->send(new VerifyUserEmail($signedUrl, $user->name, 'Forgot Password', $expiryMinutes));
        return back()->with('status', __('Link has been sent to your email address.'));
    }
    
    public function updatePasswordCreate(Request $request, $id, $hash)
    {
        if ($request->user()?->id) {
            return redirect(config('neev.dashboard_url'));
        }
        $user = User::model()->findOrFail($id);
    
        if (!$user || !$request->hasValidSignature()) {
            return redirect(route('password.request'))->withErrors(['message' => 'Invalid verification link.']);
        }

        foreach ($user?->emails as $email) {
            if (sha1($email->email) !== $hash || (config('neev.email_verified') && !$email?->verified_at)) {
                continue;
            }
            
            return view('neev::auth.reset-password', ['email' => $email->email]);
        }

        return redirect(route('password.request'))->withErrors(['message' => 'Invalid verification link.']);
    }
    
    public function updatePasswordStore(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email|max:255',
            'password' => config('neev.password'),
        ]);

        $email = Email::where('email', $request->email)->first();
        $user = $email?->user;
        if (!$user || !$email || (config('neev.email_verified') && !$email?->verified_at)) {
            return back()->withErrors(['message' => 'Failed to update password.']);
        }
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
        $userId = $request->user()?->id;
        $user = User::model()->find($userId);
        
        if (!$user) {
            return redirect(route('login'));
        }
        
        if ($user->email?->verified_at) {
            return redirect(config('neev.dashboard_url'));
        }
        
        return view('neev::auth.verify-email', [
            'email' => $user->email?->email
        ]);
    }

    /**
     * Show the verify email send.
    */
    public function emailVerifySend(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        $email = $request->email ? Email::where('email', $request->email)->firstOrFail() : $user?->email;
        if (!$user || !$email) {
            return back()->withErrors(['message' => 'User not found.']);
        }
        if ($email?->verified_at) {
            return back()->with('status', __('Email already verified.'));
        }
        $expiryMinutes = config('neev.url_expiry_time', 60);
        $signedUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes($expiryMinutes),
            ['id' => $user->id, 'hash' => sha1($email->email)]
        );
    
        Mail::to($email->email)->send(new VerifyUserEmail($signedUrl, $user->name, 'Verify Email', $expiryMinutes));
        return back()->with('status', __('verification-link-sent'));
    }

    public function emailVerifyStore(Request $request, $id, $hash) {
        $user = User::model()->findOrFail($id);
        $loggedInUser = User::model()->find($request->user()?->id);
        if (!$user || !$loggedInUser || $loggedInUser?->id != $user?->id) {
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
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return redirect(route('login'));
        }
        Auth::logoutCurrentDevice();

        $request->session()->invalidate();

        event(new LoggedOutEvent($user));

        return redirect(route('login'));
    }

    public function destroyAll(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return redirect(route('login'));
        }
        if (!$request->session_id) {
            if (! Hash::check($request->password, $user->password?->password)) {
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

        event(new LoggedOutEvent($user));

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
                return back()->withErrors(['message' => 'Invalid Email.']);
            }
            $expiryMinutes = config('neev.otp_expiry_time', 15);
            if ($auth->expires_at && now()->lt($auth->expires_at)) {
                $auth->expires_at = now()->addMinutes($expiryMinutes);
                $auth->save();
            } else {
                $otp = random_int(config('neev.otp_min', 100000), config('neev.otp_max', 999999));
                $auth->otp = $otp;
                $auth->expires_at = now()->addMinutes($expiryMinutes);
                $auth->save();
                Mail::to($email)->send(new EmailOTP($user->name, $otp, $expiryMinutes));
            }
        }
        
        return view('neev::auth.otp-mfa', [
            'email' => $email, 
            'method' => $method, 
            'attempt_id' => $attemptID
        ]);
    }

    public function emailOTPSend()
    {
        $email = session('email');
        $user = Email::where('email', $email)->first()?->user;
        $auth = $user?->multiFactorAuth('email');
        if (!$auth) {
            return back()->withErrors(['message' => 'Invalid Email.']);
        }
        $expiryMinutes = config('neev.otp_expiry_time', 15);
        $otp = random_int(config('neev.otp_min', 100000), config('neev.otp_max', 999999));
        $auth->otp = $otp;
        $auth->expires_at = now()->addMinutes($expiryMinutes);
        $auth->save();
        Mail::to($email)->send(new EmailOTP($user->name, $otp, $expiryMinutes));
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
            $attempt->multi_factor_method = $request->auth_method;
            $attempt->save();
        }
        if ($user->verifyMFAOTP($request->auth_method, $request->otp)) {
            if ($request->action === 'verify') {
                return back()->with('status', 'Code verified.');
            } 

            $this->auth->login($request, $geoIP, $user, LoginAttempt::Password, $request->auth_method, $attempt ?? null);
            return redirect(config('neev.dashboard_url'));
        }

        return back()->withErrors(['message' => 'Code is invalid']);
    }

    private function isDomainVerified(string $email): bool
    {
        $emailDomain = substr(strrchr($email, "@"), 1);
        $domain = Domain::where('domain', $emailDomain)->first();
        return $domain?->verified_at !== null;
    }

    private function createUserTeam(User $user): Team
    {
        return Team::model()->forceCreate([
            'name' => explode(' ', $user->name, 2)[0] . "'s Team",
            'user_id' => $user->id,
            'is_public' => false,
        ]);
    }
}
