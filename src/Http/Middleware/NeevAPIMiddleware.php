<?php

namespace Ssntpl\Neev\Http\Middleware;

use Auth;
use Closure;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use Ssntpl\Neev\Models\AccessToken;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Scopes\TenantScope;
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
        $accessToken = AccessToken::with('attempt')->find($id);

        if (!$accessToken || !Hash::check($token, $accessToken->token) || ($accessToken->token_type == AccessToken::mfa_token && !$request->is(['neev/mfa/otp/verify', 'neev/mfa']))) {
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

        // Bypass TenantScope when resolving the authenticated user — this is
        // the authentication layer and must find the user regardless of tenant context.
        $userClass = User::getClass();
        $user = $userClass::withoutGlobalScope(TenantScope::class)->find($accessToken->user_id);

        // Check if user account is active
        if (!$user || !$user->active) {
            return response()->json([
                'message' => 'Your account is deactivated.'
            ], 403);
        }

        $accessToken->forceFill(['last_used_at' => now()])->saveQuietly();

        $emailBypassPaths = ['neev/email/send', 'neev/users', 'neev/logout', 'neev/email/update', 'neev/email/verify', 'neev/email/otp/send', 'neev/email/otp/verify', 'neev/users'];
        if (!$user->hasVerifiedEmail() && !$request->is($emailBypassPaths)) {
            return response()->json([
                'message' => 'Email not verified.'
            ], 401);
        }

        Auth::setUser($user);
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
