<?php

namespace Ssntpl\Neev\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Ssntpl\Neev\Models\User;
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
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return redirect(route('login'));
        }

        if (!$user->active) {
            return redirect(route('login'))->withErrors(['message' => 'Your account is deactivated, please contact your admin to activate your account.']);
        }

        $attemptID = session('attempt_id');
        $attempt = $user->loginAttempts()->where('id', $attemptID)->first();
        if ($attempt && count($user->multiFactorAuths ?? []) > 0) {
            if (!$attempt->multi_factor_method) {
                return redirect(route('otp.mfa.create', $user->preferredMultiAuth?->method ?? $user->multiFactorAuths()->first()?->method));
            }
        } elseif (!$attempt && count($user->multiFactorAuths ?? []) > 0) {
            return redirect(route('login')); 
        }

        if (config('neev.email_verified') && !$user->email?->verified_at && !$request->is('email/verify*') && !$request->is('email/send') && !$request->is('logout') && !$request->is('email/change') && !$request->is('email/update')) {
            return redirect(route('verification.notice'));
        }
        
        return $next($request);
    }
}
