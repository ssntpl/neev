<?php

namespace Ssntpl\Neev\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Ssntpl\Neev\Services\TenantResolver;

class EnsureTenantIsActive
{
    public function handle(Request $request, Closure $next)
    {
        if (!app()->bound(TenantResolver::class)) {
            return $next($request);
        }

        $resolver = app(TenantResolver::class);
        $tenant = $resolver->currentTenant();

        if ($tenant && !$tenant->isActive()) {
            abort(403, 'This tenant is currently inactive.');
        }

        return $next($request);
    }
}
