<?php

namespace Ssntpl\Neev\Services;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Ssntpl\Neev\Events\LoggedIn;
use Ssntpl\Neev\Events\PasswordChanged;
use Ssntpl\Neev\Mail\EmailOTP;
use Ssntpl\Neev\Mail\VerifyUserEmail;
use Ssntpl\Neev\Models\LoginAttempt;
use Ssntpl\Neev\Models\OTP;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Rules\PasswordHistory;

class AuthService
{
    /**
     * @param bool $pendingMfa The login still owes a second factor, so the
     *                         attempt is left unsuccessful for the MFA step to
     *                         complete.
     */
    public function login(Request $request, GeoIP $geoIP, $user, $method, ?string $mfa = null, ?LoginAttempt $attempt = null, bool $viaRequestAuth = false, bool $pendingMfa = false)
    {
        if (!$user?->active) {
            throw ValidationException::withMessages([
                'email' => 'Your account is deactivated, please contact your admin to activate your account.',
            ]);
        }

        if ($viaRequestAuth) {
            $request->authenticate();
        } else {
            Auth::login($user, false);
        }

        $request->session()->regenerate();

        event(new LoggedIn($user));

        $attempt = $this->recordLoginAttempt($request, $geoIP, $user, $method, $mfa, $attempt, $pendingMfa);
        session(['attempt_id' => $attempt->id ?? null]);
    }

    /**
     * `multi_factor_method` records which second factor the login demands;
     * `is_success` records whether the login completed. A login parked at the
     * challenge therefore names its factor straight away and stays
     * unsuccessful until the code verifies.
     */
    public function recordLoginAttempt(Request $request, GeoIP $geoIP, $user, $method, ?string $mfa = null, ?LoginAttempt $attempt = null, bool $pendingMfa = false): ?LoginAttempt
    {
        try {
            if ($attempt) {
                if ($mfa !== null) {
                    $attempt->multi_factor_method = $mfa;
                }
                if (!$pendingMfa) {
                    $attempt->is_success = true;
                }
                if ($attempt->isDirty()) {
                    $attempt->save();
                }
                return $attempt;
            }

            $clientDetails = LoginAttempt::getClientDetails($request);

            return $user->loginAttempts()->create([
                'method'  => $method,
                'location' => $geoIP->getLocation($request->ip()),
                'multi_factor_method' => $mfa,
                'platform' => $clientDetails['platform'] ?? '',
                'browser'  => $clientDetails['browser'] ?? '',
                'device'   => $clientDetails['device'] ?? '',
                'ip_address' => $request->ip(),
                'is_success' => !$pendingMfa,
            ]);
        } catch (Exception $e) {
            Log::error($e);
            return null;
        }
    }

    /**
     * Create an API login token for the user, record the login attempt, and fire the LoggedIn event.
     *
     * @return string|null The plain-text token, or null on failure.
     */
    public function createApiToken(Request $request, GeoIP $geoIP, $user, string $method, int $expiryMinutes, ?LoginAttempt $attempt = null): ?string
    {
        if (!$user->active) {
            throw ValidationException::withMessages([
                'email' => 'Your account is deactivated, please contact your admin to activate your account.',
            ]);
        }

        try {
            $attempt = $this->recordLoginAttempt($request, $geoIP, $user, $method, null, $attempt);

            $token = $user->createLoginToken($expiryMinutes);
            $accessToken = $token->accessToken;
            $accessToken->attempt_id = $attempt->id;
            $accessToken->save();

            event(new LoggedIn($user));

            return $token->plainTextToken;
        } catch (Exception $e) {
            Log::error($e);
            return null;
        }
    }

    /**
     * Issue a fresh email MFA code and mail it. Every first factor that parks
     * a login at the challenge for the email method calls this, so the user
     * has a code to answer with whether or not a page of ours follows.
     *
     * A code that is still live is left exactly as it is unless $force says
     * otherwise. Reissuing on every entry to the challenge let a reopened page
     * keep one six-digit secret alive indefinitely, and it is also what keeps
     * the several entry points (Blade page, API, OAuth callback) from mailing
     * the same user twice for one login. $force is for a deliberate resend,
     * where the user is telling us the first mail did not arrive.
     *
     * @return bool Whether a new code was minted and mailed.
     */
    public function sendMfaEmailCode(User $user, bool $force = false): bool
    {
        $auth = $user->multiFactorAuth('email');
        if (!$auth) {
            return false;
        }

        if (!$force && $auth->expires_at && now()->lt($auth->expires_at)) {
            return false;
        }

        $length = (int) config('neev.otp_length', 6);
        $otp = random_int(10 ** ($length - 1), (10 ** $length) - 1);
        $expiryMinutes = (int) config('neev.otp_expiry_time', 15);

        $auth->issueOtp($otp, $expiryMinutes);
        Mail::to($user->email)->send(new EmailOTP($user->name, $otp, $expiryMinutes));

        return true;
    }

    /**
     * Send email verification link to the user's current email.
     */
    public function sendEmailVerification(User $user): void
    {
        $expiryMinutes = config('neev.url_expiry_time', 60);

        // Where the link points is the app's decision, not this service's.
        $url = app(EmailLinks::class)->verificationUrl($user, now()->addMinutes($expiryMinutes));

        // Both proofs travel in one email; the app-owned template decides
        // which to show. The code lets the user complete verification on
        // the device that is waiting (cross-device signup, TVs, SafeLinks-
        // mangled links); either proof invalidates the other on success.
        $otp = $this->createEmailVerificationOtp($user);

        Mail::to($user->email)->send(new VerifyUserEmail($url, $user->name, 'Verify Email', $expiryMinutes, config('neev.otp_expiry_time', 15), $otp));
    }

    /**
     * Email the user a one-time code, and nothing else.
     *
     * Deliberately generic: any caller that needs the account holder to
     * prove they are reading the mailbox uses this, whether or not the
     * account has a password. sendEmailVerification() pairs the same code
     * with a signed link, which most callers have no use for — a link that
     * signs the reader in does not belong beside "confirm deleting your
     * account".
     *
     * The user holds one code at a time, so issuing here replaces any code
     * outstanding for any purpose, including a verification in flight.
     */
    /**
     * Validation rules for the proof a sensitive action asks of this account.
     *
     * An account holding a password proves itself with it. One without — every
     * OAuth and SSO registration — proves itself with a code from
     * `{prefix}/confirmation/otp`, because `Hash::check()` against a null hash
     * can never succeed and demanding a password of those accounts locked them
     * out of their own settings.
     *
     * @return array<string, array<int, string>>
     */
    public function confirmationRules(User $user): array
    {
        return $user->password !== null
            ? ['password' => ['required']]
            : ['otp' => ['required']];
    }

    /**
     * Whether this request carries that proof. The code is single-use: it is
     * spent on any correct guess, whichever action read it.
     */
    public function confirmIdentity(User $user, Request $request): bool
    {
        return $user->password !== null
            ? Hash::check((string) $request->input('password'), $user->password)
            : $this->verifyEmailOtp($user, (string) $request->input('otp'));
    }

    /** Which proof was asked for, so a caller can word its own refusal. */
    public function confirmationField(User $user): string
    {
        return $user->password !== null ? 'password' : 'otp';
    }

    public function sendConfirmationOtp(User $user): void
    {
        $otp = $this->createEmailVerificationOtp($user);
        $expiryMinutes = (int) config('neev.otp_expiry_time', 15);

        Mail::to($user->email)->send(new EmailOTP($user->name, $otp, $expiryMinutes));
    }

    /**
     * Issue (or replace) the user's email-verification code. Stored
     * hashed; resending resets the attempt counter.
     */
    protected function createEmailVerificationOtp(User $user): string
    {
        $length = (int) config('neev.otp_length', 6);
        $otp = (string) random_int(10 ** ($length - 1), (10 ** $length) - 1);

        OTP::updateOrCreate(
            ['owner_id' => $user->id, 'owner_type' => $user->getMorphClass()],
            [
                'otp' => $otp,
                'attempts' => 0,
                'expires_at' => now()->addMinutes(config('neev.otp_expiry_time', 15)),
            ],
        );

        return $otp;
    }

    /**
     * Verify an email-verification code for the waiting session.
     * Wrong codes count toward OTP::MAX_ATTEMPTS, after which the code
     * is invalidated and a fresh email must be requested.
     */
    public function verifyEmailOtp(User $user, string $otp): bool
    {
        $record = OTP::query()
            ->where('owner_id', $user->id)
            ->where('owner_type', $user->getMorphClass())
            ->first();

        if (!$record || $record->expires_at->isPast()) {
            $record?->delete();
            return false;
        }

        // The guess is reserved with a conditional increment *before* the hash
        // is compared, as HasMultiAuth::verifyMFAOTP() does: requests arriving
        // together would otherwise each read a stale count, all pass the
        // check, and all be evaluated against the same code.
        $reserved = $record->newQueryWithoutScopes()
            ->whereKey($record->getKey())
            ->where('attempts', '<', OTP::MAX_ATTEMPTS)
            ->increment('attempts');

        if ($reserved === 0) {
            $record->delete();
            return false;
        }

        // Unlike the MFA row, which is only ever cleared, this row is deleted
        // — by a concurrent success or by the guess that exhausted it — so the
        // re-read may find nothing. That is a spent code, not an error.
        $record = $record->fresh();
        if (!$record) {
            return false;
        }

        if (!Hash::check($otp, $record->otp)) {
            if ($record->attempts >= OTP::MAX_ATTEMPTS) {
                $record->delete();
            }
            return false;
        }

        // Skipped for an address already verified: the same code confirms a
        // sensitive action for an account that has no password, and every
        // such account is verified. Marking again would rewrite
        // email_verified_at on each confirmation, so the column would record
        // the last action confirmed rather than when the address was proven.
        if (!$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        OTP::query()
            ->where('owner_id', $user->id)
            ->where('owner_type', $user->getMorphClass())
            ->delete();

        return true;
    }

    /**
     * Send email verification link for an email change.
     * The signed URL binds the user ID and new email so it can't be tampered with.
     *
     * @param User $user The user requesting the change
     * @param string $newEmail The new email address to verify
     * @param string $routeName The named route for verification (web vs API)
     */
    public function sendEmailChangeVerification(User $user, string $newEmail, string $routeName = 'neev.email.change.verify'): void
    {
        $expiryMinutes = config('neev.url_expiry_time', 60);

        // Where the link points is the app's decision, not this service's.
        $url = app(EmailLinks::class)->emailChangeUrl($user, $newEmail, now()->addMinutes($expiryMinutes));

        Mail::to($newEmail)->send(new VerifyUserEmail($url, $user->name, 'Verify Email Change', $expiryMinutes));
    }

    /**
     * Apply a verified email change.
     * Checks that the new email is still unique before updating.
     *
     * @return bool True if the email was updated, false if the email is already taken.
     */
    public function applyEmailChange(User $user, string $newEmail): bool
    {
        $existing = User::findByEmail($newEmail);
        if ($existing && $existing->id !== $user->id) {
            return false;
        }

        $user->email = $newEmail;
        $user->email_verified_at = now();
        $user->save();

        return true;
    }

    /**
     * Change the user's password and manage password history.
     *
     * @param User $user
     * @param string $newPassword Plain-text password (will be hashed by the model's cast)
     */
    public function changePassword(User $user, string $newPassword): void
    {
        DB::transaction(function () use ($user, $newPassword) {
            $user = User::model()->lockForUpdate()->find($user->id);

            $history = $user->password_history ?? [];

            // Prepend the current hashed password to history
            $currentHash = $user->getRawOriginal('password');
            if ($currentHash) {
                array_unshift($history, $currentHash);
            }

            // Trim to configured limit
            $limit = $this->getPasswordHistoryLimit();
            $user->password_history = array_slice($history, 0, $limit);
            $user->password = $newPassword;
            $user->password_changed_at = now();
            $user->save();
        });

        // The transaction worked on its own locked copy. Bring the caller's
        // instance up to date so whatever holds it — the auth guard, and through
        // it AuthenticateSession's stored password hash — sees the new password
        // rather than signing the caller out on their next request.
        $user->refresh();

        // The old password vouched for every session and login token this
        // account holds; it no longer can. Revoked outside the transaction so a
        // failure here leaves the password changed rather than silently rolling
        // it back — a changed password with stale sessions is recoverable, the
        // reverse looks like success and is not.
        //
        // API tokens are deliberately left alone: they are credentials the user
        // minted deliberately, not a by-product of signing in, and killing a
        // team's integrations because someone rotated their password is a
        // product decision. `revokeApiTokens()` and the PasswordChanged event
        // are there for applications that want it.
        $request = request();

        $this->revokeOtherSessions(
            $user,
            $request->hasSession() ? $request->session()->getId() : null,
        );
        $this->revokeLoginTokens($user, $request->attributes->get('token_id'));

        event(new PasswordChanged($user));
    }

    /**
     * Drop this account's session records, optionally sparing one.
     *
     * Only the database session driver stores sessions where they can be
     * enumerated. On file, redis or cookie drivers another session cannot be
     * reached at all, so this reports 0 and the application should attach
     * Laravel's `AuthenticateSession` middleware, which invalidates a session
     * whose stored password hash no longer matches — driver-agnostic, and the
     * only thing that works there.
     *
     * @return int rows removed
     */
    public function revokeOtherSessions(User $user, ?string $exceptSessionId = null): int
    {
        if (config('session.driver') !== 'database') {
            return 0;
        }

        $query = $this->sessionsTable()->where('user_id', $user->id);

        if ($exceptSessionId !== null) {
            $query->where('id', '!=', $exceptSessionId);
        }

        return $query->delete();
    }

    /**
     * Drop one of this account's sessions. A row belonging to anyone else is
     * left alone whatever id is passed.
     *
     * @return int rows removed
     */
    public function revokeSession(User $user, string $sessionId): int
    {
        if (config('session.driver') !== 'database') {
            return 0;
        }

        return $this->sessionsTable()
            ->where('user_id', $user->id)
            ->where('id', $sessionId)
            ->delete();
    }

    /**
     * The session store's table, on the connection the database driver
     * actually uses. Laravel reads `session.connection`, which need not be the
     * default connection; a query on the default one reads or deletes from a
     * table the driver never writes to.
     */
    public function sessionsTable(): QueryBuilder
    {
        return DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'));
    }

    /**
     * Drop this account's login tokens, optionally sparing the one in use.
     *
     * Login tokens are the API's equivalent of a session — minted by signing
     * in, slid forward while active — so they answer to the password the same
     * way a session does.
     *
     * @return int tokens removed
     */
    public function revokeLoginTokens(User $user, int|string|null $exceptTokenId = null): int
    {
        $query = $user->loginTokens();

        if ($exceptTokenId !== null) {
            $query->where('id', '!=', $exceptTokenId);
        }

        return $query->delete();
    }

    /**
     * Drop the API tokens this account created.
     *
     * Not called on a password change — see changePassword(). This exists so an
     * application that treats a password change as a compromise can revoke them
     * from a PasswordChanged listener, rather than reaching into the table.
     *
     * @return int tokens removed
     */
    public function revokeApiTokens(User $user): int
    {
        return $user->apiTokens()->delete();
    }

    /**
     * Resolve where to send the user once authentication is complete.
     *
     * Order of preference:
     *  1. An explicit `redirect` value carried through the login forms.
     *  2. The URL the guest was trying to reach when the auth middleware
     *     bounced them to the login page (stored by `redirect()->guest()`).
     *  3. The application home.
     *
     * The intended URL is always pulled from the session, so a stale value
     * never leaks into a later login.
     */
    public function intendedUrl(mixed $redirect = null): string
    {
        $intended = session()->pull('url.intended');

        return $this->safeRedirect($redirect)
            ?? $this->safeRedirect($intended)
            ?? config('neev.home');
    }

    /**
     * Normalise a post-login target, rejecting anything that could send the
     * user off-site or straight back into the auth flow.
     */
    public function safeRedirect(mixed $redirect): ?string
    {
        if (!is_string($redirect) || trim($redirect) === '') {
            return null;
        }

        $redirect = trim($redirect);

        // Reject protocol-relative and backslash tricks outright.
        if (str_starts_with($redirect, '//') || str_starts_with($redirect, '/\\')) {
            return null;
        }

        if (!str_starts_with($redirect, '/')) {
            // Absolute URLs are only honoured for the current host.
            $host = parse_url($redirect, PHP_URL_HOST);
            if (!$host || strcasecmp($host, request()->getHost()) !== 0) {
                return null;
            }
        }

        $path = '/' . trim((string) parse_url($redirect, PHP_URL_PATH), '/');

        if ($path === '/' || $this->isAuthPath($path)) {
            return null;
        }

        return $redirect;
    }

    /**
     * Auth pages are never a useful destination after logging in — sending a
     * user back to /login would just bounce them around.
     */
    protected function isAuthPath(string $path): bool
    {
        $routes = ['login', 'register', 'logout', 'verification.notice', 'password.request'];

        foreach ($routes as $name) {
            if (!app('router')->has($name)) {
                continue;
            }
            $routePath = '/' . trim((string) parse_url(route($name), PHP_URL_PATH), '/');
            if (strcasecmp($routePath, $path) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the password history limit from config.
     */
    protected function getPasswordHistoryLimit(): int
    {
        $passwordRules = config('neev.password', []);
        if (!is_array($passwordRules)) {
            return 5;
        }
        foreach ($passwordRules as $rule) {
            if ($rule instanceof PasswordHistory) {
                return $rule->getCount();
            }
        }
        return 5;
    }
}
