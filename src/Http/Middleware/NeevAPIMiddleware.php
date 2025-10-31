<?php

namespace Ssntpl\Neev\Http\Middleware;

use Closure;
use Hash;
use Illuminate\Http\Request;
use Ssntpl\Neev\Models\AccessToken;
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

        if (! $token) {
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

        $accessToken->update(['last_used_at' => now()]);
        $user = $accessToken->user;

        if (config('neev.email_verified') && !$user?->hasVerifiedEmail() && !$request->is('neev/email/send') && !$request->is('neev/logout') && !$request->is('neev/email/update') && !$request->is('neev/email/verify') && !$request->is('neev/email/otp/send') && !$request->is('neev/email/otp/verify') && !$request->is('neev/users')) {
            return response()->json([
                'message' => 'Email not verified.'
            ], 401);
        }

        $request->setUserResolver(fn () => $user);
        $request->attributes->set('token_id', $id);
        
        return $next($request);
    }

    protected function getTokenFromRequest(Request $request): ?string
    {
        $authHeader = $request->bearerToken();
        if ($authHeader) {
            return $authHeader;
        }

        return $request->input('token') ?? $request->query('token');
    }
}
