<?php

namespace Ssntpl\Neev\Services;

use Illuminate\Http\Request;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\TenantDomain;

class TenantResolver
{
    /**
     * The current tenant (team).
     */
    protected ?Team $currentTenant = null;

    /**
     * The current tenant domain.
     */
    protected ?TenantDomain $currentTenantDomain = null;

    /**
     * Resolve the tenant from the request.
     */
    public function resolve(Request $request): ?Team
    {
        if (!config('neev.tenant_isolation', false)) {
            return null;
        }

        $host = $request->getHost();
        $tenantDomain = TenantDomain::findByHost($host);

        if ($tenantDomain) {
            $this->currentTenantDomain = $tenantDomain;
            $this->currentTenant = $tenantDomain->team;
            return $this->currentTenant;
        }

        return null;
    }

    /**
     * Get the current tenant.
     */
    public function current(): ?Team
    {
        return $this->currentTenant;
    }

    /**
     * Get the current tenant domain.
     */
    public function currentDomain(): ?TenantDomain
    {
        return $this->currentTenantDomain;
    }

    /**
     * Set the current tenant.
     */
    public function setCurrentTenant(Team $team): void
    {
        $this->currentTenant = $team;
    }

    /**
     * Set the current tenant domain.
     */
    public function setCurrentTenantDomain(TenantDomain $tenantDomain): void
    {
        $this->currentTenantDomain = $tenantDomain;
        $this->currentTenant = $tenantDomain->team;
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
        $this->currentTenantDomain = null;
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
