<?php

namespace Ssntpl\Neev\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Ssntpl\Neev\Contracts\IdentityProviderOwnerInterface;
use Ssntpl\Neev\Services\TenantResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures the authenticated user has logged in via SSO when the resolved
 * context (Team or Tenant) requires it.
 *
 * Run AFTER authentication middleware so that the user is already resolved.
 * If the context requires SSO and the current session was not established
 * via SSO, the user is redirected to the SSO flow.
 */
class EnsureContextSSO
{
    public function __construct(
        protected TenantResolver $tenantResolver
    ) {
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!config('neev.tenant_auth', false)) {
            return $next($request);
        }

        $context = $this->tenantResolver->resolvedContext();

        if (!$context instanceof IdentityProviderOwnerInterface) {
            return $next($request);
        }

        if (!$context->requiresSSO() || !$context->hasSSOConfigured()) {
            return $next($request);
        }

        // Only enforce for authenticated users who didn't use SSO
        $user = $request->user();
        if (!$user) {
            return $next($request);
        }

        // Check if the current session/token was authenticated via SSO
        if ($this->wasAuthenticatedViaSSO($request)) {
            return $next($request);
        }

        // User is authenticated but not via SSO — redirect to SSO
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'SSO authentication required for this organization.',
                'sso_redirect_url' => route('sso.redirect'),
            ], 403);
        }

        return redirect()->route('sso.redirect');
    }

    /**
     * Determine if the current session was established via SSO.
     */
    protected function wasAuthenticatedViaSSO(Request $request): bool
    {
        // Check session flag set during SSO callback
        if ($request->hasSession() && $request->session()->get('auth_method') === 'sso') {
            return true;
        }

        // For API requests, check the login attempt method on the token
        $tokenId = $request->attributes->get('token_id');
        if ($tokenId) {
            $accessToken = \Ssntpl\Neev\Models\AccessToken::find($tokenId);
            if ($accessToken?->attempt?->method === \Ssntpl\Neev\Models\LoginAttempt::SSO) { // @phpstan-ignore property.notFound
                return true;
            }
        }

        return false;
    }
}
