<?php

namespace Ssntpl\Neev\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use Ssntpl\Neev\Models\AccessToken;
use Ssntpl\Neev\Services\ContextManager;
use Symfony\Component\HttpFoundation\Response;

class NeevAPIMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->getTokenFromRequest($request);

        if (! $token || ! str_contains($token, '|')) {
            return response()->json([
                'message' => 'Missing token'
            ], 401);
        }

        [$id, $token] = explode('|', $token, 2);
        $accessToken = AccessToken::find($id);

        if (!$accessToken || !Hash::check($token, $accessToken->token) || ($accessToken->token_type == AccessToken::mfa_token && !$request->is('neev/mfa/otp/verify'))) {
            return response()->json([
                'message' => 'Invalid or expired token'
            ], 401);
        }

        // Check token expiry
        if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
            $accessToken->delete();
            return response()->json([
                'message' => 'Invalid or expired token'
            ], 401);
        }

        $user = $accessToken->user;

        // Check if user account is active
        if (!$user || !$user->active) {
            return response()->json([
                'message' => 'Your account is deactivated.'
            ], 403);
        }

        $accessToken->update(['last_used_at' => now()]);

        $emailBypassPaths = ['neev/email/send', 'neev/logout', 'neev/email/update', 'neev/email/verify', 'neev/email/otp/send', 'neev/email/otp/verify', 'neev/users'];
        if (config('neev.email_verified') && !$user->hasVerifiedEmail() && !$request->is($emailBypassPaths)) {
            return response()->json([
                'message' => 'Email not verified.'
            ], 401);
        }

        $request->setUserResolver(fn () => $user);
        $request->attributes->set('token_id', $id);

        if (app()->bound(ContextManager::class)) {
            app(ContextManager::class)->setUser($user);
        }

        return $next($request);
    }

    protected function getTokenFromRequest(Request $request): ?string
    {
        return $request->bearerToken() ?? $request->input('token') ?? $request->query('token');
    }
}
