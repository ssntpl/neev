<?php

namespace Ssntpl\Neev\Models;

/**
 * TenantDomain - Utility class for tenant domain resolution.
 *
 * This is NOT a database model. Subdomains are computed from team slugs,
 * and custom domains are stored in the `domains` table.
 *
 * Use this class for:
 * - Resolving a team from a request host
 * - Checking if a host is a valid tenant domain
 */
class TenantDomain
{
    public int $team_id;
    public string $domain;
    public string $type; // 'subdomain' or 'custom'
    public bool $is_primary;
    public ?\DateTime $verified_at;
    protected ?Team $team = null;

    /**
     * Find the team for a given host.
     *
     * @param string $host The request host (e.g., "tenant.app.com" or "custom.com")
     * @return Team|null The team if found
     */
    public static function findTeamByHost(string $host): ?Team
    {
        $subdomainSuffix = config('neev.tenant_isolation_options.subdomain_suffix');

        // Check if it's a subdomain
        if ($subdomainSuffix) {
            $suffix = '.' . ltrim($subdomainSuffix, '.');
            if (str_ends_with($host, $suffix)) {
                $slug = str_replace($suffix, '', $host);
                $team = Team::model()->where('slug', $slug)->first();
                if ($team) {
                    return $team;
                }
            }
        }

        // Check custom domains in domains table
        $domain = Domain::findByHost($host);
        if ($domain) {
            return $domain->team;
        }

        return null;
    }

    /**
     * Find a tenant domain object by host.
     * Returns a TenantDomain instance (not a database model).
     *
     * @param string $host The request host
     * @return self|null
     */
    public static function findByHost(string $host): ?self
    {
        $subdomainSuffix = config('neev.tenant_isolation_options.subdomain_suffix');

        // Check if it's a subdomain
        if ($subdomainSuffix) {
            $suffix = '.' . ltrim($subdomainSuffix, '.');
            if (str_ends_with($host, $suffix)) {
                $slug = str_replace($suffix, '', $host);
                $team = Team::model()->where('slug', $slug)->first();
                if ($team) {
                    return self::makeForSubdomain($team, $host);
                }
            }
        }

        // Check custom domains in domains table
        $domain = Domain::findByHost($host);
        if ($domain) {
            return self::makeFromDomain($domain);
        }

        return null;
    }

    /**
     * Find a tenant domain by host, or fail with 404.
     */
    public static function findByHostOrFail(string $host): self
    {
        $tenantDomain = static::findByHost($host);

        if (!$tenantDomain) {
            abort(404, 'Tenant not found');
        }

        return $tenantDomain;
    }

    /**
     * Create a TenantDomain instance for a computed subdomain.
     */
    protected static function makeForSubdomain(Team $team, string $host): self
    {
        $instance = new self();
        $instance->team_id = $team->id;
        $instance->domain = $host;
        $instance->type = 'subdomain';
        $instance->is_primary = true;
        $instance->verified_at = $team->created_at;
        $instance->team = $team;

        return $instance;
    }

    /**
     * Create a TenantDomain instance from a Domain model.
     */
    protected static function makeFromDomain(Domain $domain): self
    {
        $instance = new self();
        $instance->team_id = $domain->team_id;
        $instance->domain = $domain->domain;
        $instance->type = 'custom';
        $instance->is_primary = $domain->is_primary;
        $instance->verified_at = $domain->verified_at;
        $instance->team = $domain->team;

        return $instance;
    }

    /**
     * Check if this domain is verified.
     */
    public function isVerified(): bool
    {
        // Subdomains are always verified, custom domains need verification
        return $this->type === 'subdomain' || $this->verified_at !== null;
    }

    /**
     * Get the team for this tenant domain.
     */
    public function getTeam(): ?Team
    {
        if ($this->team) {
            return $this->team;
        }

        return Team::model()->find($this->team_id);
    }

    /**
     * Magic getter for team relationship.
     */
    public function __get(string $name)
    {
        if ($name === 'team') {
            return $this->getTeam();
        }

        return null;
    }
}
