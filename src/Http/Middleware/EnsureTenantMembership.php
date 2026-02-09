<?php

namespace Ssntpl\Neev\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Ssntpl\Neev\Services\TenantResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to ensure the authenticated user is a member of the current tenant.
 *
 * This middleware validates that a logged-in user actually belongs to the tenant
 * they are trying to access. If not, it logs them out and redirects to login.
 *
 * This is important for multi-tenant applications where users can belong to
 * multiple tenants, preventing cross-tenant session hijacking.
 */
class EnsureTenantMembership
{
    public function __construct(
        protected TenantResolver $tenantResolver
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip if tenant isolation is not enabled
        if (!config('neev.tenant_isolation', false)) {
            return $next($request);
        }

        $user = $request->user();
        $tenant = $this->tenantResolver->current();

        // If no user or no tenant context, let other middleware handle it
        if (!$user || !$tenant) {
            return $next($request);
        }

        // Check if user belongs to this tenant
        if (!$user->belongsToTeam($tenant)) {
            // Log out the user
            auth()->logout();

            // Invalidate the session
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            // For API requests, return JSON error
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => __('neev::auth.not_member'),
                ], 403);
            }

            // For web requests, redirect to login with error
            return redirect()->route('login')
                ->withErrors(['tenant' => __('neev::auth.not_member')]);
        }

        return $next($request);
    }
}
