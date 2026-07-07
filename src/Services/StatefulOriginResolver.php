<?php

namespace Ssntpl\Neev\Services;

use Illuminate\Http\Request;

/**
 * Decides whether a request comes from a configured stateful SPA origin.
 *
 * Shared by EnsureSpaRequestsAreStateful (cookie -> bearer promotion,
 * CSRF enforcement) and, in later phases, the auth controllers that set
 * the SPA cookie on login responses.
 */
class StatefulOriginResolver
{
    public function isStateful(Request $request): bool
    {
        return $this->isStatefulUrl(
            $request->headers->get('Origin') ?? $request->headers->get('Referer')
        );
    }

    /**
     * Whether a URL's host matches the stateful list. Used for redirect
     * targets (OAuth/SSO callbacks), where the browser's Origin header
     * belongs to the identity provider, not the SPA.
     */
    public function isStatefulUrl(?string $url): bool
    {
        $stateful = config('neev.spa.stateful', []);
        if (empty($stateful) || !$url) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return false;
        }

        $port = parse_url($url, PHP_URL_PORT);
        $hostWithPort = $port ? "{$host}:{$port}" : $host;

        foreach ($stateful as $pattern) {
            if ($this->matches($host, $hostWithPort, trim($pattern))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the request's own host matches the stateful list — the
     * same-origin-SPA signal for full-page navigations (no usable
     * Origin header, e.g. an OAuth callback landing on the monolith).
     */
    public function isStatefulHost(Request $request): bool
    {
        return $this->isStatefulUrl($request->getSchemeAndHttpHost());
    }

    /**
     * Exact host, host:port (e.g. "localhost:3000"), or "*.suffix" wildcard.
     */
    protected function matches(string $host, string $hostWithPort, string $pattern): bool
    {
        if ($pattern === '') {
            return false;
        }

        if ($host === $pattern || $hostWithPort === $pattern) {
            return true;
        }

        return str_starts_with($pattern, '*.')
            && str_ends_with($host, substr($pattern, 1));
    }
}
