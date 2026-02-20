<?php

namespace Ssntpl\Neev\Contracts;

interface ResolvableContextInterface
{
    /**
     * Look up a context container by its slug.
     * Used by resolvers for subdomain and header-based resolution.
     */
    public static function resolveBySlug(string $slug): ?static;

    /**
     * Look up a context container by a custom domain.
     * Used by resolvers for custom domain resolution.
     */
    public static function resolveByDomain(string $domain): ?static;
}
