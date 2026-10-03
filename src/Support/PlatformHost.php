<?php

namespace Ssntpl\Neev\Support;

use Ssntpl\Neev\Models\Hostname;

/**
 * The subdomain the platform serves an owner at (RFC 006 §4.3): its slug, one
 * label under `neev.platform_domain`. Derived on every read and never stored,
 * so it follows the slug by definition.
 */
class PlatformHost
{
    /**
     * The configured platform zone, canonicalised. Null when none is set, in
     * which case no host is a platform host.
     */
    public static function zone(): ?string
    {
        $configured = config('neev.platform_domain');

        if (! is_string($configured)) {
            return null;
        }

        return Hostname::canonicalHost($configured) ?: null;
    }

    /**
     * The host a slug is served at, or null with no zone or no slug.
     */
    public static function for(?string $slug): ?string
    {
        $slug = strtolower(trim((string) $slug));
        $zone = static::zone();

        return $slug !== '' && $zone !== null ? $slug . '.' . $zone : null;
    }

    /**
     * The slug a host names: its one label under the zone. The apex and
     * anything deeper (`a.b.zone`) name no slug.
     */
    public static function slugOf(string $host): ?string
    {
        $zone = static::zone();

        if ($zone === null) {
            return null;
        }

        $host = Hostname::canonicalHost($host);
        $suffix = '.' . $zone;

        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $label = substr($host, 0, -strlen($suffix));

        return $label !== '' && ! str_contains($label, '.') ? $label : null;
    }
}
