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
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Validation\ValidationException;
use Ssntpl\Neev\Events\LoggedOut;
use Ssntpl\Neev\Exceptions\InvalidInvitationException;
use Ssntpl\Neev\Http\Controllers\Controller;
use Ssntpl\Neev\Http\Requests\Auth\LoginRequest;
use Ssntpl\Neev\Mail\EmailOTP;
use Ssntpl\Neev\Mail\LoginUsingLink;
use Ssntpl\Neev\Mail\VerifyUserEmail;
use Ssntpl\Neev\Models\LoginAttempt;
use Ssntpl\Neev\Models\TeamInvitation;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Contracts\IdentityProviderOwnerInterface;
use Ssntpl\Neev\Services\AuthService;
use Ssntpl\Neev\Services\EmailLinks;
use Ssntpl\Neev\Services\GeoIP;
use Ssntpl\Neev\Services\RegistrationService;
use Ssntpl\Neev\Services\TenantResolver;

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
        $request->validate(app(RegistrationService::class)->rules());

        try {
            $user = app(RegistrationService::class)->register(
                $request->only(['name', 'email', 'password', 'username']),
                $request->invitation_id,
                $request->hash,
            );

            $this->auth->login($request, $geoIP, $user, LoginAttempt::Password);

            if (!$user->hasVerifiedEmail()) {
                $this->auth->sendEmailVerification($user);
                return redirect(route('verification.notice'));
            }

            return redirect($this->auth->intendedUrl($request->redirect));
        } catch (InvalidInvitationException $e) {
            return back()->withErrors(['message' => $e->getMessage()]);
        } catch (Exception $e) {
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
            return redirect($this->auth->intendedUrl($request->redirect));
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

        $loginOptions = [];
        if (count($user->passkeys) > 0) {
            $loginOptions[] = 'passkey';
        }

        $viewData = [
            'email' => $request->email,
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
        $url = app(EmailLinks::class)->magicLinkUrl($user, now()->addMinutes($expiryMinutes));

        Mail::to($user->email)->send(new LoginUsingLink($url, $expiryMinutes));

        return back()->with('status', 'Login link has been sent.');
    }

    public function loginUsingLink(Request $request, $id, GeoIP $geoIP)
    {
        if ($request->user()?->id) {
            return redirect(config('neev.home'));
        }
        if (! $request->hasValidSignature()) {
            return redirect(app(EmailLinks::class)->loginUrl())->withErrors(['message' => 'Invalid or expired login link.']);
        }

        $user = User::model()->find($id);
        if (!$user) {
            return redirect(app(EmailLinks::class)->loginUrl());
        }

        // The link was mailed to this address and came back signed, which
        // proves inbox control just as the verification mail would.
        if (!$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        $this->auth->login($request, $geoIP, $user, LoginAttempt::MagicAuth);

        return redirect($this->auth->intendedUrl($request->redirect));
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

        if (count($user->activeMultiFactorAuths) > 0) {
            session(['email' => $user->email]);

            // Carry the explicit redirect across the MFA step. Any URL the
            // auth middleware stashed in `url.intended` stays in the session
            // and is picked up once MFA succeeds.
            $redirect = $this->auth->safeRedirect($request->redirect);
            if ($redirect) {
                session(['mfa_redirect' => $redirect]);
            } else {
                session()->forget('mfa_redirect');
            }

            return redirect(route('otp.mfa.create', $user->preferredMultiFactorAuth->method ?? $user->activeMultiFactorAuths()->first()?->method));
        }

        if (!$user->hasVerifiedEmail()) {
            return redirect(route('verification.notice'));
        }

        return redirect($this->auth->intendedUrl($request->redirect));
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

        // A forgotten password is exactly the case where the user may
        // never have completed verification, so an unverified address
        // must still be able to receive a reset link.
        $user = User::findByEmail($request->email);
        if (!$user) {
            return back()->withErrors([
                'message' => __('User not registered or wrong email.'),
            ]);
        }

        $expiryMinutes = config('neev.url_expiry_time', 60);
        $url = app(EmailLinks::class)->passwordResetUrl($user, now()->addMinutes($expiryMinutes));

        Mail::to($user->email)->send(new VerifyUserEmail($url, $user->name, 'Forgot Password', $expiryMinutes));
        return back()->with('status', __('Link has been sent to your email address.'));
    }

    public function updatePasswordCreate(Request $request, $id, $hash)
    {
        // A signed-in user may still be here on purpose: the security page
        // offers this link to anyone who cannot recall their password. Only
        // send them away if the link belongs to somebody else.
        if ($request->user()?->id && (string) $request->user()->id !== (string) $id) {
            return redirect(config('neev.home'));
        }
        $user = User::model()->findOrFail($id);

        if (!$request->hasValidSignature()) {
            return redirect(route('password.request'))->withErrors(['message' => 'Invalid verification link.']);
        }

        if (!hash_equals(hash('sha256', $user->email), $hash)) {
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
        if (!$user) {
            return back()->withErrors(['message' => 'Failed to update password.']);
        }
        $this->auth->changePassword($user, $request->password);

        event(new PasswordReset($user));

        if ($request->user()?->id === $user->id) {
            return redirect(route('account.security'))->with('status', __('Password has been successfully updated.'));
        }

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
            return redirect(app(EmailLinks::class)->loginUrl());
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
    public function emailVerifyOtpStore(Request $request)
    {
        $request->validate([
            'otp' => ['required'],
        ]);

        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return back()->withErrors(['message' => 'User not found.']);
        }
        if ($user->hasVerifiedEmail()) {
            return redirect(config('neev.home'));
        }

        if (!$this->auth->verifyEmailOtp($user, (string) $request->otp)) {
            return back()->withErrors(['otp' => 'Code verification failed.']);
        }

        return redirect($this->auth->intendedUrl())->with('status', __('Email verified.'));
    }

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
        $links = app(EmailLinks::class);
        $user = User::model()->find($id);

        if (!$user
            || !$request->hasValidSignature()
            || !hash_equals(hash('sha256', $user->email), (string) $hash)) {
            return $links->verificationFailed($request);
        }

        if ($user->hasVerifiedEmail()) {
            return $links->alreadyVerified($request, $user);
        }

        $user->markEmailAsVerified();

        return $links->verified($request, $user);
    }

    public function emailChangeCreate(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return redirect(app(EmailLinks::class)->loginUrl());
        }

        return view('neev::auth.change-email', [
            'email' => $user->email,
            'has_password' => $user->password !== null,
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

        // Changing the address that owns the account always costs a password.
        // An account without one must set a password first.
        if ($user->password === null) {
            return redirect(route('account.security'))
                ->withErrors(['message' => __('Set a password on your account before changing your email address.')]);
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
        $links = app(EmailLinks::class);
        $user = User::model()->find($id);
        $newEmail = $request->email;

        if (!$request->hasValidSignature() || !$user || !$newEmail) {
            return $links->emailChangeFailed($request);
        }

        if (!$this->auth->applyEmailChange($user, $newEmail)) {
            return $links->emailInUse($request, $user);
        }

        return $links->emailChanged($request, $user);
    }

    /**
     * Show the logout.
    */
    public function destroy(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return redirect(app(EmailLinks::class)->loginUrl());
        }
        Auth::logoutCurrentDevice();

        $request->session()->invalidate();

        event(new LoggedOut($user));

        return redirect(app(EmailLinks::class)->loginUrl());
    }

    public function destroyAll(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return redirect(app(EmailLinks::class)->loginUrl());
        }
        if (!$request->session_id) {
            if ($user->password !== null && ! Hash::check($request->password, $user->password)) {
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

        event(new LoggedOut($user));

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
        // Setup verification from the account security page. It confirms a
        // method for whoever is signed in and is not a login step, so it acts
        // on the session's user and touches no login attempt.
        if ($request->action === 'verify') {
            /** @var User|null $user */
            $user = User::model()->find($request->user()?->id);
            if (!$user) {
                return back()->withErrors(['message' => 'Credentials are wrong.']);
            }

            if ($user->verifyMfaSetup($request->auth_method, (string) $request->otp)) {
                return back()->with('status', 'Method verified and enabled.');
            }
            if ($user->verifyMFAOTP($request->auth_method, $request->otp)) {
                return back()->with('status', 'Code verified.');
            }

            return back()->withErrors(['message' => 'Code is invalid']);
        }

        // The login challenge. This route sits outside the authenticated group,
        // so the account it acts on has to come from the password step that
        // opened the challenge — `loginStore()` puts it in the session. Taking
        // it from the request instead would let anyone holding a single second
        // factor sign in as its owner, no password involved.
        $email = session('email');
        $user = $email ? User::findByEmail($email) : null;

        if (!$user) {
            return back()->withErrors(['message' => 'Credentials are wrong.']);
        }

        // Only a factor this account has actually enrolled can answer for it.
        $method = $request->auth_method;
        $enrolled = $user->activeMultiFactorAuths->pluck('method')->all();
        if ($method !== 'recovery' && !in_array($method, $enrolled, true)) {
            return back()->withErrors(['message' => 'Code is invalid']);
        }

        if (!$user->verifyMFAOTP($method, $request->otp)) {
            return back()->withErrors(['message' => 'Code is invalid']);
        }

        // Stamped only now. NeevMiddleware treats a non-null
        // multi_factor_method as proof the challenge was answered, so writing
        // it before verifying let a deliberately wrong code open the gate.
        $attempt = session('attempt_id')
            ? $user->loginAttempts()->whereKey(session('attempt_id'))->first()
            : null;
        if ($attempt) {
            $attempt->is_success = true;
            $attempt->multi_factor_method = $method;
            $attempt->save();
        }

        $this->auth->login($request, $geoIP, $user, LoginAttempt::Password, $method, $attempt);

        return redirect($this->auth->intendedUrl(session()->pull('mfa_redirect')));
    }

}
