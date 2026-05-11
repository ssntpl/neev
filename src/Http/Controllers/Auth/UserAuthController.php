<?php

namespace Ssntpl\Neev\Http\Controllers\Auth;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Ssntpl\Neev\Events\LoggedOutEvent;
use Ssntpl\Neev\Http\Controllers\Controller;
use Ssntpl\Neev\Http\Requests\Auth\LoginRequest;
use Ssntpl\Neev\Mail\EmailOTP;
use Ssntpl\Neev\Mail\LoginUsingLink;
use Ssntpl\Neev\Mail\VerifyUserEmail;
use Ssntpl\Neev\Models\Domain;
use Ssntpl\Neev\Models\LoginAttempt;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\TeamInvitation;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Contracts\IdentityProviderOwnerInterface;
use Ssntpl\Neev\Services\AuthService;
use Ssntpl\Neev\Services\GeoIP;
use Ssntpl\Neev\Services\TenantResolver;
use Illuminate\Support\Facades\URL;

class UserAuthController extends Controller
{
    public function __construct(
        protected AuthService $auth,
    ) {
    }
    /**
     * Show the register page.
    */
    public function registerCreate(Request $request)
    {
        if ($request->user()?->id) {
            return redirect(config('neev.home'));
        }
        if (config('neev.team') && ($request->id || $request->hash)) {
            if (!$request->hasValidSignature()) {
                return back()->withErrors(['message' => 'Invalid or expired invitation link.']);
            }
            $invitation = TeamInvitation::find($request->id);
            if (!$invitation || !hash_equals(sha1($invitation->email), $request->hash)) {
                return back()->withErrors(['message' => 'Invalid or expired invitation link.']);
            }
            return view('neev::auth.register', ['id' => $request->id, 'hash' => $request->hash, 'email' => $invitation->email]);
        }
        $input = $request->email;
        $isEmail = filter_var($input, FILTER_VALIDATE_EMAIL);

        return view('neev::auth.register', [
            'email' => $isEmail ? $input : null,
            'username' => !$isEmail ? $input : null
        ]);
    }

    /**
     * Show the register store.
    */
    public function registerStore(LoginRequest $request, GeoIP $geoIP)
    {
        $validationRules = [
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'email', 'max:255', User::uniqueEmailRule()],
            'password' => config('neev.password'),
        ];

        if (config('neev.support_username')) {
            $validationRules['username'] = config('neev.username');
        }

        $request->validate($validationRules);

        try {
            DB::beginTransaction();

            $userData = [
                'name' => $request->name,
                'email' => $request->email,
                'password' => $request->password,
                'password_changed_at' => now(),
            ];

            if (config('neev.support_username')) {
                $userData['username'] = $request->username;
            }

            $user = User::model()->forceCreate($userData);
            $user = User::model()->find($user->id);

            if (config('neev.team')) {
                if ($request->invitation_id) {
                    $invitation = TeamInvitation::find($request->invitation_id);
                    if (!$invitation || !hash_equals(sha1($invitation?->email), $request->hash)) {
                        DB::rollBack();
                        return back()->withErrors(['message' => 'Invalid or expired invitation link.']);
                    }

                    $user->markEmailAsVerified();

                    $team = $invitation->team;
                    $team->users()->attach($user, ['joined' => true]);
                    if ($invitation?->role) {
                        $user->assignRole($invitation->role, $team);
                    }
                    $invitation->delete();
                } else {
                    $shouldCreateTeam = !$this->isDomainVerified($request->email);

                    if ($shouldCreateTeam) {
                        $team = $this->createUserTeam($user, $request->email);
                        $team->addMember($user);
                    }
                }
            }
            DB::commit();
            $this->auth->login($request, $geoIP, $user, LoginAttempt::Password);

            if (!$user->hasVerifiedEmail()) {
                $this->auth->sendEmailVerification($user);
                return redirect(route('verification.notice'));
            }

            return redirect(config('neev.home'));
        } catch (Exception $e) {
            DB::rollBack();
            Log::error($e);
            return back()->withErrors(['message' => 'Unable to register user.']);
        }
    }

    /**
     * Show the login page.
     *
     * If tenant-driven auth is enabled and the current tenant requires SSO,
     * redirects to the SSO provider instead of showing the login form.
    */
    public function loginCreate(Request $request)
    {
        if ($request->user()?->id) {
            return redirect(config('neev.home'));
        }

        // Check if tenant requires SSO authentication
        $tenantResolver = app(TenantResolver::class);
        $context = $tenantResolver->resolvedContext();

        if ($context instanceof IdentityProviderOwnerInterface
            && $context->requiresSSO()
            && $context->hasSSOConfigured()) {
            return redirect()->route('sso.redirect');
        }

        return view('neev::auth.login', ['redirect' => $request->redirect]);
    }

    /**
     * Show the login password page.
    */
    public function loginPassword(LoginRequest $request)
    {
        if (config('neev.support_username') && !preg_match('/^[\w.%+\-]+@[\w.\-]+\.[A-Za-z]{2,}$/', $request->email)) {
            $user = User::findByUsername($request->email);
            if ($user) {
                $request->merge(['username' => $user->username]);
                $request->merge(['email' => $user->email]);
            }
        }

        $user = $request->checkEmail();
        if (!$user) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $isDomainFederated = false;
        if (config('neev.team')) {
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
        $user = User::findByEmail($request->email);
        if (!$user) {
            return back()->withErrors(['message' => 'Credentials are wrong.']);
        }

        $expiryMinutes = config('neev.url_expiry_time', 60);
        $signedUrl = URL::temporarySignedRoute(
            'login.link',
            now()->addMinutes($expiryMinutes),
            ['id' => $user->id]
        );

        Mail::to($user->email)->send(new LoginUsingLink($signedUrl, $expiryMinutes));

        return back()->with('status', 'Login link has been sent.');
    }

    public function loginUsingLink(Request $request, $id, GeoIP $geoIP)
    {
        if ($request->user()?->id) {
            return redirect(config('neev.home'));
        }
        if (! $request->hasValidSignature()) {
            return redirect(route('login'))->withErrors(['message' => 'Invalid or expired login link.']);
        }

        $user = User::model()->find($id);
        if (!$user || !$user->hasVerifiedEmail()) {
            return redirect(route('login'));
        }

        $this->auth->login($request, $geoIP, $user, LoginAttempt::MagicAuth);

        return redirect(config('neev.home'));
    }

    /**
     * Show the login store.
    */
    public function loginStore(LoginRequest $request, GeoIP $geoIP)
    {
        $user = User::findByEmail($request->email);
        if (!$user) {
            return back()->withErrors(['message' => 'Credentials are wrong.']);
        }

        $attempt = null;
        if (config('neev.log_failed_logins')) {
            $clientDetails = LoginAttempt::getClientDetails($request);
            $attempt = $user->loginAttempts()->create([
                'method' => LoginAttempt::Password,
                'location' => $geoIP->getLocation($request->ip()),
                'multi_factor_method' => null,
                'platform' => $clientDetails['platform'] ?? '',
                'browser' => $clientDetails['browser'] ?? '',
                'device' => $clientDetails['device'] ?? '',
                'ip_address' => $request->ip(),
                'is_success' => false,
            ]);
        }
        $this->auth->login(request: $request, geoIP: $geoIP, user: $user, method: LoginAttempt::Password, attempt: $attempt, viaRequestAuth: true);

        if (count($user->multiFactorAuths) > 0) {
            session(['email' => $user->email]);
            return redirect(route('otp.mfa.create', $user->preferredMultiFactorAuth->method ?? $user->multiFactorAuths()->first()?->method));
        }

        if ($request->redirect && $request->redirect != '/' && str_starts_with($request->redirect, '/')) {
            return redirect($request->redirect);
        }

        if (!$user->hasVerifiedEmail()) {
            return redirect(route('verification.notice'));
        }
        return redirect(config('neev.home'));
    }

    /**
     * Show the forgot password page.
    */
    public function forgotPasswordCreate()
    {
        if (request()->user()?->id) {
            return redirect(config('neev.home'));
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

        $user = User::findByEmail($request->email);
        if (!$user || !$user->hasVerifiedEmail()) {
            return back()->withErrors([
                'message' => __('User not registered or wrong email.'),
            ]);
        }

        $expiryMinutes = config('neev.url_expiry_time', 60);
        $signedUrl = URL::temporarySignedRoute(
            'reset.request',
            now()->addMinutes($expiryMinutes),
            ['id' => $user->id, 'hash' => hash('sha256', $user->email)]
        );

        Mail::to($user->email)->send(new VerifyUserEmail($signedUrl, $user->name, 'Forgot Password', $expiryMinutes));
        return back()->with('status', __('Link has been sent to your email address.'));
    }

    public function updatePasswordCreate(Request $request, $id, $hash)
    {
        if ($request->user()?->id) {
            return redirect(config('neev.home'));
        }
        $user = User::model()->findOrFail($id);

        if (!$request->hasValidSignature()) {
            return redirect(route('password.request'))->withErrors(['message' => 'Invalid verification link.']);
        }

        if (!hash_equals(hash('sha256', $user->email), $hash) || !$user->hasVerifiedEmail()) {
            return redirect(route('password.request'))->withErrors(['message' => 'Invalid verification link.']);
        }

        $resetToken = bin2hex(random_bytes(32));
        session(['password_reset_token' => hash_hmac('sha256', $resetToken, config('app.key')), 'password_reset_email' => $user->email]);
        return view('neev::auth.reset-password', ['email' => $user->email, 'reset_token' => $resetToken]);
    }

    public function updatePasswordStore(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email|max:255',
            'password' => config('neev.password'),
            'reset_token' => 'required|string',
        ]);

        // Verify the reset token matches the session
        $sessionToken = session()->pull('password_reset_token');
        $sessionEmail = session()->pull('password_reset_email');
        if (!$sessionToken || !hash_equals($sessionToken, hash_hmac('sha256', $request->reset_token, config('app.key'))) || $sessionEmail !== $request->email) {
            return redirect(route('password.request'))->withErrors(['message' => 'Invalid or expired reset link. Please request a new one.']);
        }

        $user = User::findByEmail($request->email);
        if (!$user || !$user->hasVerifiedEmail()) {
            return back()->withErrors(['message' => 'Failed to update password.']);
        }
        $this->auth->changePassword($user, $request->password);
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

        if ($user->hasVerifiedEmail()) {
            return redirect(config('neev.home'));
        }

        return view('neev::auth.verify-email', [
            'email' => $user->email
        ]);
    }

    /**
     * Show the verify email send.
    */
    public function emailVerifySend(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return back()->withErrors(['message' => 'User not found.']);
        }
        if ($user->hasVerifiedEmail()) {
            return back()->with('status', __('Email already verified.'));
        }

        $this->auth->sendEmailVerification($user);
        return back()->with('status', __('verification-link-sent'));
    }

    public function emailVerifyStore(Request $request, $id, $hash)
    {
        $user = User::model()->findOrFail($id);
        $loggedInUser = User::model()->find($request->user()?->id);
        if (!$loggedInUser || $loggedInUser->id != $user->id) {
            return redirect(route('login') . '?redirect=' . urlencode($request->fullUrl()))->withErrors(['message' => __('Please login first to verify your email.')]);
        }

        if (hash_equals(hash('sha256', $user->email), $hash) && $request->hasValidSignature()) {
            $user->markEmailAsVerified();
            return redirect(config('neev.home'));
        }

        return redirect(config('neev.home'))->withErrors(['message' => __('Invalid or expired verification link.')]);
    }

    public function emailChangeCreate(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return redirect(route('login'));
        }

        return view('neev::auth.change-email', [
            'email' => $user->email,
        ]);
    }

    public function emailChangeStore(Request $request)
    {
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255', User::uniqueEmailRule()],
            'password' => ['required'],
        ]);

        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return back()->withErrors(['message' => 'User not found.']);
        }

        if (!Hash::check($request->password, $user->password)) {
            return back()->withErrors(['password' => 'Password is incorrect.']);
        }

        if ($request->email === $user->email) {
            return back()->withErrors(['email' => 'New email must be different from current email.']);
        }

        $this->auth->sendEmailChangeVerification($user, $request->email, 'email.change.verify');
        return back()->with('status', __('A verification link has been sent to your new email address.'));
    }

    public function emailChangeVerify(Request $request, $id)
    {
        if (!$request->hasValidSignature()) {
            return redirect(route('login'))->withErrors(['message' => 'Invalid or expired verification link.']);
        }

        $user = User::model()->find($id);
        if (!$user) {
            return redirect(route('login'))->withErrors(['message' => 'Invalid or expired verification link.']);
        }

        $newEmail = $request->email;
        if (!$newEmail) {
            return redirect(route('login'))->withErrors(['message' => 'Invalid or expired verification link.']);
        }

        if (!$this->auth->applyEmailChange($user, $newEmail)) {
            return redirect(config('neev.home'))->withErrors(['message' => 'This email address is already in use.']);
        }

        return redirect(config('neev.home'))->with('status', __('Email address has been updated and verified.'));
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
            if (! Hash::check($request->password, $user->password)) {
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
            DB::table('sessions')
                ->where('id', $request->session_id)
                ->where('user_id', $user->id)
                ->delete();
        }

        event(new LoggedOutEvent($user));

        return back()->with('logoutStatus', __('Logged out from other sessions.'));
    }

    public function verifyMFAOTPCreate($method)
    {
        $email = session('email');
        $attemptID = session('attempt_id');

        if ($method === 'email') {
            $user = User::findByEmail($email);
            $auth = $user?->multiFactorAuth($method);
            if (!$auth) {
                return back()->withErrors(['message' => 'Invalid Email.']);
            }
            $expiryMinutes = config('neev.otp_expiry_time', 15);
            if ($auth->expires_at && now()->lt($auth->expires_at)) {
                $auth->expires_at = now()->addMinutes($expiryMinutes);
                $auth->save();
            } else {
                $otp = random_int(10 ** (config('neev.otp_length', 6) - 1), (10 ** config('neev.otp_length', 6)) - 1);
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
        $user = User::findByEmail($email);
        $auth = $user?->multiFactorAuth('email');
        if (!$auth) {
            return back()->withErrors(['message' => 'Invalid Email.']);
        }
        $expiryMinutes = config('neev.otp_expiry_time', 15);
        $otp = random_int(10 ** (config('neev.otp_length', 6) - 1), (10 ** config('neev.otp_length', 6)) - 1);
        $auth->otp = $otp;
        $auth->expires_at = now()->addMinutes($expiryMinutes);
        $auth->save();
        Mail::to($email)->send(new EmailOTP($user->name, $otp, $expiryMinutes));
        return back()->with('status', 'Verification code has been sent.');
    }

    public function verifyMFAOTPStore(LoginRequest $request, GeoIP $geoIP)
    {
        $user = User::findByEmail($request->email) ?? User::model()->find($request->user()?->id);

        if (!$user) {
            return back()->withErrors(['message' => 'Credentials are wrong.']);
        }
        $attempt = LoginAttempt::find($request->attempt_id);
        if ($attempt) {
            $attempt->is_success = false;
            $attempt->multi_factor_method = $request->auth_method;
            $attempt->save();
        }

        $verified = false;
        if ($request->auth_method === 'recovery') {
            $matched = $user->recoveryCodes->first(fn ($c) => $c->verify($request->otp));
            if ($matched) {
                $matched->delete();
                $verified = true;
            }
        } else {
            $auth = $user->multiFactorAuth($request->auth_method);
            $verified = (bool) $auth?->verifyOTP($request->otp);
        }

        if ($verified) {
            if ($request->action === 'verify') {
                return back()->with('status', 'Code verified.');
            }

            $this->auth->login($request, $geoIP, $user, LoginAttempt::Password, $request->auth_method, $attempt ?? null);
            return redirect(config('neev.home'));
        }

        return back()->withErrors(['message' => 'Code is invalid']);
    }

    public function verifyMFASetupOTPStore(Request $request)
    {
        $request->validate([
            'otp' => ['required'],
            'auth_method' => ['required', 'string'],
        ]);

        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return back()->withErrors(['message' => 'User not found.']);
        }

        $pending = $user->pendingMultiFactorAuth($request->auth_method);
        if (!$pending) {
            return back()->withErrors(['message' => 'No pending setup found. Please start setup again.']);
        }

        if (!$pending->verifyOTP($request->otp)) {
            return back()->withErrors(['message' => 'Code verification failed.']);
        }

        return back()->with('status', 'MFA enabled successfully.');
    }

    private function isDomainVerified(string $email): bool
    {
        $emailDomain = substr(strrchr($email, "@"), 1);
        $domain = Domain::where('domain', $emailDomain)->first();
        return $domain?->verified_at !== null;
    }

    private function createUserTeam(User $user, ?string $email = null): Team
    {
        return Team::model()->forceCreate([
            'name' => explode(' ', $user->name, 2)[0] . "'s Team",
            'user_id' => $user->id,
            'is_public' => false,
            'activated_at' => now(),
        ]);
    }
}
