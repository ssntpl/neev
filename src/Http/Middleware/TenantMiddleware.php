<?php

namespace Ssntpl\Neev\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Ssntpl\Neev\Models\TenantDomain;
use Ssntpl\Neev\Services\TenantResolver;
use Symfony\Component\HttpFoundation\Response;

class TenantMiddleware
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

        $host = $request->getHost();
        $tenantDomain = TenantDomain::findByHost($host);

        if (!$tenantDomain) {
            return response()->json([
                'message' => 'Tenant not found',
            ], 404);
        }

        // Check if the domain is verified (custom domains need verification)
        if (!$tenantDomain->isVerified()) {
            return response()->json([
                'message' => 'Domain not verified',
            ], 403);
        }

        // Set the current tenant in the resolver
        $this->tenantResolver->setCurrentTenantDomain($tenantDomain);

        // Store tenant info in the request for easy access
        $request->attributes->set('tenant', $tenantDomain->team);
        $request->attributes->set('tenant_domain', $tenantDomain);

        return $next($request);
    }
}
