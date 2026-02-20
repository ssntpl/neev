<?php

namespace Ssntpl\Neev\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Ssntpl\Neev\Services\TenantResolver;
use Symfony\Component\HttpFoundation\Response;

class TenantMiddleware
{
    public function __construct(
        protected TenantResolver $tenantResolver
    ) {
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  $mode  'required' to 404 when no tenant found, 'optional' to pass through
     */
    public function handle(Request $request, Closure $next, string $mode = 'optional'): Response
    {
        if (!config('neev.tenant_isolation', false)) {
            return $next($request);
        }

        $tenant = $this->tenantResolver->resolve($request);

        if (!$tenant) {
            if ($mode === 'required') {
                return response()->json([
                    'message' => 'Tenant not found',
                ], 404);
            }

            return $next($request);
        }

        if (!$this->tenantResolver->isResolvedDomainVerified()) {
            return response()->json([
                'message' => 'Domain not verified',
            ], 403);
        }

        $request->attributes->set('tenant', $tenant);

        return $next($request);
    }
}
