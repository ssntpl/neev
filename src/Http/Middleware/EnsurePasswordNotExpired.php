<?php

namespace Ssntpl\Neev\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsurePasswordNotExpired
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user) {
            return $next($request);
        }

        if (method_exists($user, 'isPasswordExpired') && $user->isPasswordExpired()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => 'password_expired',
                    'message' => 'Your password has expired. Please change your password.',
                ], 403);
            }

            abort(403, 'Your password has expired. Please change your password.');
        }

        return $next($request);
    }
}
