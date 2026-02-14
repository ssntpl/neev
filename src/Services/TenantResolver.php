<?php

namespace Ssntpl\Neev\Services;

use Illuminate\Http\Request;
use Ssntpl\Neev\Models\Domain;
use Ssntpl\Neev\Models\Team;

class TenantResolver
{
    /**
     * The current tenant (team).
     */
    protected ?Team $currentTenant = null;

    /**
     * How the tenant was resolved ('header', 'subdomain', 'custom').
     */
    protected ?string $resolvedVia = null;

    /**
     * The domain or value used to resolve the tenant.
     */
    protected ?string $resolvedDomain = null;

    /**
     * The custom domain model (only set for custom domain resolution).
     */
    protected ?Domain $resolvedCustomDomain = null;

    /**
     * Resolve the tenant from the request.
     * Priority: X-Tenant header → subdomain → custom domain.
     */
    public function resolve(Request $request): ?Team
    {
        if (!config('neev.tenant_isolation', false)) {
            return null;
        }

        // 1. Try X-Tenant header resolution
        $headerValue = $request->header('X-Tenant');
        if ($headerValue !== null) {
            $result = $this->resolveFromHeader($headerValue);
            if ($result) {
                return $this->setResolved($result['team'], $result['via'], $result['domain'], $result['customDomain'] ?? null);
            }
        }

        // 2. Fall back to host-based resolution (subdomain + custom domain)
        $result = $this->resolveFromHost($request->getHost());
        if ($result) {
            return $this->setResolved($result['team'], $result['via'], $result['domain'], $result['customDomain'] ?? null);
        }

        return null;
    }

    /**
     * Resolve tenant from X-Tenant header value.
     * Tries: team ID (numeric) → slug → domain (subdomain or custom).
     */
    protected function resolveFromHeader(string $headerValue): ?array
    {
        $headerValue = trim($headerValue);

        if ($headerValue === '') {
            return null;
        }

        // Try by ID
        if (ctype_digit($headerValue)) {
            $team = Team::model()->find((int) $headerValue);
            if ($team) {
                return ['team' => $team, 'via' => 'header', 'domain' => $headerValue];
            }
        }

        // Try by slug
        $team = Team::model()->where('slug', $headerValue)->first();
        if ($team) {
            return ['team' => $team, 'via' => 'header', 'domain' => $headerValue];
        }

        // Try by domain (subdomain or custom domain)
        return $this->resolveFromHost($headerValue);
    }

    /**
     * Resolve tenant from a host string (subdomain or custom domain).
     */
    protected function resolveFromHost(string $host): ?array
    {
        $subdomainSuffix = config('neev.tenant_isolation_options.subdomain_suffix');

        // Check if it's a subdomain
        if ($subdomainSuffix) {
            $suffix = '.' . ltrim($subdomainSuffix, '.');
            if (str_ends_with($host, $suffix)) {
                $slug = str_replace($suffix, '', $host);
                $team = Team::model()->where('slug', $slug)->first();
                if ($team) {
                    return ['team' => $team, 'via' => 'subdomain', 'domain' => $host];
                }
            }
        }

        // Check custom domains in domains table
        $domain = Domain::findByHost($host);
        if ($domain) {
            return ['team' => $domain->team, 'via' => 'custom', 'domain' => $host, 'customDomain' => $domain];
        }

        return null;
    }

    /**
     * Set the resolved tenant and metadata.
     */
    protected function setResolved(Team $team, string $via, string $domain, ?Domain $customDomain = null): Team
    {
        $this->currentTenant = $team;
        $this->resolvedVia = $via;
        $this->resolvedDomain = $domain;
        $this->resolvedCustomDomain = $customDomain;

        return $team;
    }

    /**
     * Check if the resolved domain is verified.
     * Subdomains and header-resolved tenants are always verified.
     * Custom domains require explicit verification.
     */
    public function isResolvedDomainVerified(): bool
    {
        if ($this->resolvedVia === 'subdomain' || $this->resolvedVia === 'header') {
            return true;
        }

        if ($this->resolvedVia === 'custom') {
            return $this->resolvedCustomDomain?->isVerified() ?? false;
        }

        return false;
    }

    /**
     * Get the current tenant.
     */
    public function current(): ?Team
    {
        return $this->currentTenant;
    }

    /**
     * How the tenant was resolved ('header', 'subdomain', 'custom').
     */
    public function resolvedVia(): ?string
    {
        return $this->resolvedVia;
    }

    /**
     * The domain or value used to resolve the tenant.
     */
    public function resolvedDomain(): ?string
    {
        return $this->resolvedDomain;
    }

    /**
     * Set the current tenant.
     */
    public function setCurrentTenant(Team $team): void
    {
        $this->currentTenant = $team;
    }

    /**
     * Check if a tenant is currently set.
     */
    public function hasTenant(): bool
    {
        return $this->currentTenant !== null;
    }

    /**
     * Clear the current tenant.
     */
    public function clear(): void
    {
        $this->currentTenant = null;
        $this->resolvedVia = null;
        $this->resolvedDomain = null;
        $this->resolvedCustomDomain = null;
    }

    /**
     * Get the current tenant ID.
     */
    public function currentId(): ?int
    {
        return $this->currentTenant?->id;
    }

    /**
     * Check if tenant isolation is enabled.
     */
    public function isEnabled(): bool
    {
        return config('neev.tenant_isolation', false);
    }

    /**
     * Check if single tenant users is enabled.
     */
    public function singleTenantUsers(): bool
    {
        return config('neev.tenant_isolation_options.single_tenant_users', false);
    }
}
