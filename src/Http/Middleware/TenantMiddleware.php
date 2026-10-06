<?php

namespace Ssntpl\Neev\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Ssntpl\Neev\Models\Hostname;
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
     * @param  \Closure(Request): (Response)  $next
     * @param  string  $mode  'required' to 404 when no tenant found, 'optional' to pass through
     */
    public function handle(Request $request, Closure $next, string $mode = 'optional'): Response
    {
        if (!config('neev.tenant', false) && !config('neev.team', false)) {
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

        $retired = $this->tenantResolver->resolvedVia() === 'retired';

        if ($retired && $this->isNavigationOnRetiredHost($request)) {
            $redirect = $this->redirectToCurrentHost($request);

            if ($redirect !== null) {
                return $redirect;
            }
        }

        if (!$this->tenantResolver->isResolvedDomainVerified()) {
            return response()->json([
                'message' => 'Domain not verified',
            ], 403);
        }

        $request->attributes->set('tenant', $tenant);

        $response = $next($request);

        // Named by a retired slug or host and served in place within the
        // window: the current slug is sent so the client can switch to it.
        if (($retired || $this->tenantResolver->headerSlugRetired()) && $tenant instanceof Model) {
            $response->headers->set('X-Tenant-Slug', (string) $tenant->getAttribute('slug'));
        }

        return $response;
    }

    /**
     * A browser navigating to a retired host: a GET or HEAD made on that host
     * itself, not wanting JSON. Anything else is served in place: clients drop
     * `Authorization` when a redirect changes host, so redirecting an API call
     * would turn it into a 401. A request that only named the host in
     * X-Tenant was sent to another host, which is not the one retired.
     */
    protected function isNavigationOnRetiredHost(Request $request): bool
    {
        return ($request->isMethod('GET') || $request->isMethod('HEAD'))
            && ! $request->expectsJson()
            && Hostname::canonicalHost($request->getHost()) === $this->tenantResolver->resolvedDomain();
    }

    /**
     * 302 a navigation on a retired platform host to the owner's current one
     * (RFC 006 §4.4). Not a 301: a browser caches that for good, and an owner
     * that takes its old slug back would send it round in a loop. The resolver only answers a retired host within
     * `neev.slug.retired_host_days`, so the redirect ends with the window.
     * Signed links do not survive it: the host is inside the signature, so a
     * redirected one fails as invalid rather than as the wrong host.
     */
    protected function redirectToCurrentHost(Request $request): ?Response
    {
        $host = $this->tenantResolver->platformHost();

        if ($host === null) {
            return null;
        }

        $port = $request->getPort();
        $port = in_array($port, [80, 443, null], true) ? '' : ':' . $port;

        return redirect()->away($request->getScheme() . '://' . $host . $port . $request->getRequestUri(), 302);
    }
}
