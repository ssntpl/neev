<?php

namespace Ssntpl\Neev\Http\Middleware;

use Illuminate\Support\Facades\Auth;
use Closure;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use Ssntpl\Neev\Models\AccessToken;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\ContextManager;
use Symfony\Component\HttpFoundation\Response;

class NeevAPIMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token || ! str_contains($token, '|')) {
            return response()->json([
                'message' => 'Missing token'
            ], 401);
        }

        [$id, $token] = explode('|', $token, 2);
        $accessToken = AccessToken::with('attempt')->find($id);

        if (!$accessToken || !Hash::check($token, $accessToken->token)) {
            return response()->json([
                'message' => 'Invalid or expired token'
            ], 401);
        }

        // Check token expiry
        if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
            $accessToken->delete();
            return response()->json([
                'message' => 'Invalid or expired token',
                'code' => 'token_expired',
            ], 401);
        }

        $userClass = User::getClass();
        $user = $userClass::find($accessToken->user_id);

        // Check if user account is active
        if (!$user || !$user->active) {
            return response()->json([
                'message' => 'Your account is deactivated.'
            ], 403);
        }

        $this->slideExpiry($request, $accessToken);

        $accessToken->forceFill(['last_used_at' => now()])->saveQuietly();

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('token_id', $id);

        if (app()->bound(ContextManager::class)) {
            app(ContextManager::class)->setUser($user);
        }

        return $next($request);
    }

    /**
     * Push a login token's idle window forward so an actively used
     * session is not cut off mid-work, capped by the token's absolute
     * lifetime. The new expiry is left dirty on the model for the
     * caller to persist, and published on the request so SPA cookie
     * mode can re-issue the cookie with the same deadline.
     *
     * API tokens are deliberate, long-lived credentials and never
     * slide; neither do tokens issued without an expiry.
     */
    protected function slideExpiry(Request $request, AccessToken $accessToken): void
    {
        if ($accessToken->token_type !== AccessToken::login || !$accessToken->expires_at) {
            return;
        }

        $idle = (int) config('neev.login_token_expiry_minutes', 1440);

        if ($idle <= 0) {
            return;
        }

        // Renew once the session is past the half-way point of its idle
        // window. Requests before that keep the existing deadline, which
        // keeps the write (and the Set-Cookie header) off most requests.
        if ($accessToken->expires_at->gt(now()->addMinutes(intdiv($idle, 2)))) {
            return;
        }

        $expiresAt = now()->addMinutes($idle);

        $maxLifetime = (int) config('neev.login_token_max_lifetime_minutes', 43200);

        if ($maxLifetime > 0) {
            $ceiling = ($accessToken->created_at ?? now())->copy()->addMinutes($maxLifetime);

            if ($expiresAt->gt($ceiling)) {
                $expiresAt = $ceiling;
            }
        }

        // At the absolute ceiling there is nothing left to give.
        if ($expiresAt->lte($accessToken->expires_at)) {
            return;
        }

        $accessToken->forceFill(['expires_at' => $expiresAt]);
        $request->attributes->set('neev.token_expires_at', $expiresAt);
    }
}
