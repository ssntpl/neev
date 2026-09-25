<?php

namespace Ssntpl\Neev\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Ssntpl\Neev\Models\LoginAttempt;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\ContextManager;
use Ssntpl\Neev\Services\EmailLinks;
use Symfony\Component\HttpFoundation\Response;

class NeevMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $authUser = $request->user();
        if (!$authUser) {
            return $this->unauthenticated($request, 'Unauthenticated.');
        }

        // Re-query only if the app uses a custom user model
        $user = $authUser instanceof (User::getClass())
            ? $authUser
            : User::model()->find($authUser->id);

        if (!$user) {
            return $this->unauthenticated($request, 'Unauthenticated.');
        }

        if (app()->bound(ContextManager::class)) {
            app(ContextManager::class)->setUser($user);
        }

        if (!$user->active) {
            return $this->unauthenticated($request, 'Your account is deactivated, please contact your admin to activate your account.', 403);
        }

        // Eager load MFA relationships to avoid N+1 queries.
        // Only active methods count towards MFA enforcement — a pending
        // (unverified) setup must never gate the session.
        $user->loadMissing('activeMultiFactorAuths', 'preferredMultiFactorAuth');

        $attemptID = session('attempt_id');
        $attempt = $user->loginAttempts()->where('id', $attemptID)->first();
        if ($attempt && count($user->activeMultiFactorAuths ?? []) > 0) {
            // A passkey answers for itself — the ceremony runs with
            // `userVerification: 'required'` — and tenant/team SSO leaves the
            // second factor to the organization's IdP, as the API side always
            // has. Neither is ever parked at the challenge.
            $answersForItself = in_array(
                $attempt->method,
                [LoginAttempt::Passkey, LoginAttempt::SSO],
                true,
            );

            $answered = $attempt->is_success
                && ($attempt->multi_factor_method || $answersForItself);

            if (!$answered) {
                // A login that *completed* without ever naming a second
                // factor predates the enrolment: the factor was added from
                // somewhere else while this session was open. There is no
                // challenge in flight to send it to -- the challenge page
                // reads the account from `session('email')`, which only a
                // login parked at the challenge writes -- so it is simply no
                // longer authenticated, and the fresh login that follows
                // parks at the challenge properly.
                if ($attempt->is_success) {
                    return $this->unauthenticated($request, 'Unauthenticated.');
                }

                // Still unsuccessful: a login parked mid-challenge, which
                // does have its challenge in the session. Send it there.
                $method = $attempt->multi_factor_method
                    ?? $user->preferredMultiFactorAuth?->method
                    ?? $user->activeMultiFactorAuths()->first()?->method;
                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'MFA verification required.',
                        'mfa_method' => $method,
                    ], 403);
                }
                return redirect(app(EmailLinks::class)->mfaChallengeUrl($method));
            }
        } elseif (!$attempt && count($user->activeMultiFactorAuths ?? []) > 0) {
            // No attempt row to read at all, against an account that now
            // requires a second factor: nothing here vouches for the session.
            return $this->unauthenticated($request, 'Unauthenticated.');
        }

        return $next($request);
    }

    /**
     * Refuse the request and end the session it came from.
     *
     * Only ending it makes the refusal true. A session left signed in while
     * every protected page turns it away loops: the redirect lands on
     * /login, which sends an authenticated user straight back to the page
     * that refused it. Nothing here can tell why the session no longer
     * clears the account's policy -- expired, revoked, a second factor
     * enrolled from another session -- and it does not need to. From the
     * server's side there is one answer, and it is this one.
     *
     * A guest has no sign-in to end, so its session is left alone: wiping it
     * would only throw away whatever else it carries — a locale, a draft,
     * another package's flash data — for a visitor who merely hit a protected
     * page.
     */
    protected function unauthenticated(Request $request, string $message, int $status = 401): Response
    {
        if ($request->user()) {
            Auth::logoutCurrentDevice();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], $status);
        }

        // `guest()` stores the URL the user was trying to reach in the
        // session (`url.intended`) so the login flow can send them back.
        if ($status === 403) {
            return redirect()->guest(app(EmailLinks::class)->loginUrl())->withErrors(['message' => $message]);
        }

        return redirect()->guest(app(EmailLinks::class)->loginUrl());
    }
}
