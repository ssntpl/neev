<?php

namespace Ssntpl\Neev\Http\Middleware;

use Auth;
use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\ContextManager;
use Ssntpl\Neev\Services\JwtSecret;
use Symfony\Component\HttpFoundation\Response;

class JwtLoginMiddleware
{
    /**
     * Authenticate JWT login tokens (e.g. MFA temp tokens).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if (!$token) {
            return response()->json([
                'message' => 'Missing token',
            ], 401);
        }

        try {
            $claims = (array) JWT::decode($token, new Key(JwtSecret::get(), 'HS256'));
        } catch (\Throwable) {
            return response()->json([
                'message' => 'Invalid or expired token',
            ], 401);
        }

        if (($claims['type'] ?? null) !== 'mfa') {
            return response()->json([
                'message' => 'Invalid or expired token',
            ], 401);
        }

        $userClass = User::getClass();
        $user = $userClass::find($claims['user_id'] ?? null);
        if (!$user || !$user->active) {
            return response()->json([
                'message' => 'Your account is deactivated.',
            ], 403);
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('jwt_claims', $claims);

        if (app()->bound(ContextManager::class)) {
            app(ContextManager::class)->setUser($user);
        }

        return $next($request);
    }

}
