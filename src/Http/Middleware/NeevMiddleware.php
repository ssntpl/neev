<?php

namespace Ssntpl\Neev\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\ContextManager;
use Symfony\Component\HttpFoundation\Response;

class NeevMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
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

        $attemptID = session('attempt_id');
        $attempt = $user->loginAttempts()->where('id', $attemptID)->first();
        if ($attempt && count($user->multiFactorAuths ?? []) > 0) {
            if (!$attempt->multi_factor_method) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'MFA verification required.',
                        'mfa_method' => $user->preferredMultiFactorAuth?->method ?? $user->multiFactorAuths()->first()?->method,
                    ], 403);
                }
                return redirect(route('otp.mfa.create', $user->preferredMultiFactorAuth?->method ?? $user->multiFactorAuths()->first()?->method));
            }
        } elseif (!$attempt && count($user->multiFactorAuths ?? []) > 0) {
            return $this->unauthenticated($request, 'Unauthenticated.');
        }

        $emailBypassPaths = ['email/verify*', 'email/send', 'logout', 'email/change', 'email/update'];
        if (config('neev.email_verified') && !$user->email?->verified_at && !$request->is($emailBypassPaths)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Email not verified.'], 403);
            }
            return redirect(route('verification.notice'));
        }

        return $next($request);
    }

    protected function unauthenticated(Request $request, string $message, int $status = 401): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], $status);
        }

        if ($status === 403) {
            return redirect(route('login'))->withErrors(['message' => $message]);
        }

        return redirect(route('login'));
    }
}
