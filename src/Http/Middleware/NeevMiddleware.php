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
        if (!$request->user()) {
            return redirect(route('login'));
        }

        if (!$request->user()?->active) {
            return redirect(route('login'))->withErrors(['message' => 'Your account is deactivated, please contact your admin to activate your account.']);
        }

        if (config('neev.email_verified') && !User::model()->find($request->user()?->id)?->primaryEmail?->verified_at && !$request->is('email/verify*') && !$request->is('email/send') && !$request->is('logout') && !$request->is('email/change') && !$request->is('email/update')) {
            return redirect(route('verification.notice'));
        }
        
        return $next($request);
    }
}
