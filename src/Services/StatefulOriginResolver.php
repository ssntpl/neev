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
        $stateful = config('neev.spa.stateful', []);
        if (empty($stateful)) {
            return false;
        }

        $source = $request->headers->get('Origin') ?? $request->headers->get('Referer');
        if (!$source) {
            return false;
        }

        $host = parse_url($source, PHP_URL_HOST);
        if (!$host) {
            return false;
        }

        $port = parse_url($source, PHP_URL_PORT);
        $hostWithPort = $port ? "{$host}:{$port}" : $host;

        foreach ($stateful as $pattern) {
            if ($this->matches($host, $hostWithPort, trim($pattern))) {
                return true;
            }
        }

        return false;
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
