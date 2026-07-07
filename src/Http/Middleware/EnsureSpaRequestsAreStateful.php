<?php

namespace Ssntpl\Neev\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Ssntpl\Neev\Services\SpaCsrfToken;
use Ssntpl\Neev\Services\StatefulOriginResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bridges SPA cookie authentication onto the bearer-token path.
 *
 * For requests from a configured stateful origin this middleware
 * validates the signed double-submit CSRF token on state-changing
 * methods and promotes the HttpOnly auth cookie to an Authorization
 * header, so downstream token middleware (NeevAPIMiddleware,
 * JwtLoginMiddleware) runs unchanged. For every other request it is
 * a no-op — existing bearer callers are unaffected.
 */
class EnsureSpaRequestsAreStateful
{
    public function __construct(
        protected StatefulOriginResolver $origins,
        protected SpaCsrfToken $csrf,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->origins->isStateful($request)) {
            return $next($request);
        }

        if ($this->isStateChanging($request) && !$this->csrfValid($request)) {
            return response()->json(['message' => 'CSRF token mismatch.'], 419);
        }

        $token = $request->cookie(config('neev.spa.cookie_name', 'neev_session'));

        if (is_string($token) && $token !== '' && !$request->bearerToken()) {
            $request->headers->set('Authorization', 'Bearer ' . $token);
            $request->attributes->set('neev.spa', true);
        }

        return $next($request);
    }

    protected function isStateChanging(Request $request): bool
    {
        return in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    protected function csrfValid(Request $request): bool
    {
        $cookie = $request->cookie(config('neev.spa.csrf_cookie_name', 'XSRF-TOKEN'));
        $header = $request->header(config('neev.spa.csrf_header_name', 'X-XSRF-TOKEN'));

        return $this->csrf->validate(
            is_string($cookie) ? $cookie : null,
            is_string($header) ? $header : null,
        );
    }
}
