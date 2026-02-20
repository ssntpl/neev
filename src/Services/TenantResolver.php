<?php

namespace Ssntpl\Neev\Services;

use Illuminate\Http\Request;
use Ssntpl\Neev\Contracts\ContextContainerInterface;
use Ssntpl\Neev\Contracts\ResolvableContextInterface;
use Ssntpl\Neev\Models\Domain;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\Tenant;

class TenantResolver
{
    /**
     * The current tenant (team) — backward compat.
     */
    protected ?Team $currentTenant = null;

    /**
     * The resolved Tenant model (isolated mode only).
     */
    protected ?Tenant $resolvedTenantModel = null;

    /**
     * The resolved context container (Tenant or Team depending on strategy).
     */
    protected ?ContextContainerInterface $resolvedContext = null;

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
     *
     * In isolated mode, resolves a Tenant. In shared mode, resolves a Team.
     * Returns the resolved context container, or null if not found.
     */
    public function resolve(Request $request): ?ContextContainerInterface
    {
        if (!config('neev.tenant_isolation', false)) {
            return null;
        }

        // 1. Try X-Tenant header resolution
        $headerValue = $request->header('X-Tenant');
        if ($headerValue !== null) {
            $result = $this->resolveFromHeader($headerValue);
            if ($result) {
                return $this->setResolved($result['context'], $result['via'], $result['domain'], $result['customDomain'] ?? null);
            }
        }

        // 2. Fall back to host-based resolution (subdomain + custom domain)
        $result = $this->resolveFromHost($request->getHost());
        if ($result) {
            return $this->setResolved($result['context'], $result['via'], $result['domain'], $result['customDomain'] ?? null);
        }

        return null;
    }

    /**
     * Resolve tenant from X-Tenant header value.
     * Tries: ID (numeric) → slug → domain (subdomain or custom).
     */
    protected function resolveFromHeader(string $headerValue): ?array
    {
        $headerValue = trim($headerValue);

        if ($headerValue === '') {
            return null;
        }

        $model = $this->getResolvableModel();

        // Try by ID
        if (ctype_digit($headerValue)) {
            $context = $model::find((int) $headerValue);
            if ($context) {
                return ['context' => $context, 'via' => 'header', 'domain' => $headerValue];
            }
            return null;
        }

        // Try by slug
        $context = $model::resolveBySlug($headerValue);
        if ($context) {
            return ['context' => $context, 'via' => 'header', 'domain' => $headerValue];
        }

        // Try by domain (subdomain or custom domain)
        return $this->resolveFromHost($headerValue);
    }

    /**
     * Resolve tenant from a host string (subdomain or custom domain).
     */
    protected function resolveFromHost(string $host): ?array
    {
        $model = $this->getResolvableModel();
        $subdomainSuffix = config('neev.tenant_isolation_options.subdomain_suffix');

        // Check if it's a subdomain
        if ($subdomainSuffix) {
            $suffix = '.' . ltrim($subdomainSuffix, '.');
            if (str_ends_with($host, $suffix)) {
                $slug = str_replace($suffix, '', $host);
                $context = $model::resolveBySlug($slug);
                if ($context) {
                    return ['context' => $context, 'via' => 'subdomain', 'domain' => $host];
                }
            }
        }

        // Check custom domain via domains table
        if ($this->isIsolated()) {
            // In isolated mode, try tenant-owned domains first
            $domain = Domain::findByHostWithTenant($host);
            if ($domain && $domain->tenant) {
                return ['context' => $domain->tenant, 'via' => 'custom', 'domain' => $host, 'customDomain' => $domain];
            }

            // Fall back to team-owned domains and navigate to tenant
            $domain = Domain::findByHost($host);
            if ($domain) {
                $context = $domain->team?->tenant;
                if ($context) {
                    return ['context' => $context, 'via' => 'custom', 'domain' => $host, 'customDomain' => $domain];
                }
            }
        } else {
            // In shared mode, domains belong to teams
            $domain = Domain::findByHost($host);
            if ($domain && $domain->team) {
                return ['context' => $domain->team, 'via' => 'custom', 'domain' => $host, 'customDomain' => $domain];
            }
        }

        return null;
    }

    /**
     * Get the model class to use for resolution based on identity strategy.
     *
     * @return class-string<ResolvableContextInterface>
     */
    protected function getResolvableModel(): string
    {
        if ($this->isIsolated()) {
            return Tenant::getClass();
        }

        return Team::getClass();
    }

    /**
     * Set the resolved context and metadata.
     */
    protected function setResolved(ContextContainerInterface $context, string $via, string $domain, ?Domain $customDomain = null): ContextContainerInterface
    {
        $this->resolvedContext = $context;
        $this->resolvedVia = $via;
        $this->resolvedDomain = $domain;
        $this->resolvedCustomDomain = $customDomain;

        // Backward compat: keep currentTenant as Team
        match ($context->getContextType()) { // @phpstan-ignore match.unhandled
            'team' => $this->currentTenant = $context, // @phpstan-ignore assign.propertyType
            'tenant' => $this->resolvedTenantModel = $context, // @phpstan-ignore assign.propertyType
        };

        // Populate ContextManager if available
        if (app()->bound(ContextManager::class)) {
            app(ContextManager::class)->setContext($context);
        }

        return $context;
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
     * Get the current team (backward compat).
     * In shared mode: returns the resolved Team.
     * In isolated mode: returns null (use currentTenant() or resolvedContext() instead).
     */
    public function current(): ?Team
    {
        return $this->currentTenant;
    }

    /**
     * Get the resolved Tenant model (isolated mode only).
     */
    public function currentTenant(): ?Tenant
    {
        return $this->resolvedTenantModel;
    }

    /**
     * Get the resolved context container (Tenant or Team).
     */
    public function resolvedContext(): ?ContextContainerInterface
    {
        return $this->resolvedContext;
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
     * Get the resolved custom Domain model (only set for custom domain resolution).
     */
    public function currentDomain(): ?Domain
    {
        return $this->resolvedCustomDomain;
    }

    /**
     * Set the current tenant (backward compat — accepts Team).
     */
    public function setCurrentTenant(Team $team): void
    {
        $this->currentTenant = $team;
        $this->resolvedContext = $team;

        if (app()->bound(ContextManager::class)) {
            app(ContextManager::class)->setContext($team);
        }
    }

    /**
     * Check if a context is currently resolved.
     */
    public function hasTenant(): bool
    {
        return $this->resolvedContext !== null;
    }

    /**
     * Clear the current context.
     */
    public function clear(): void
    {
        $this->currentTenant = null;
        $this->resolvedTenantModel = null;
        $this->resolvedContext = null;
        $this->resolvedVia = null;
        $this->resolvedDomain = null;
        $this->resolvedCustomDomain = null;

        if (app()->bound(ContextManager::class)) {
            app(ContextManager::class)->clear();
        }
    }

    /**
     * Get the current context ID.
     * In shared mode: returns Team ID. In isolated mode: returns Tenant ID.
     */
    public function currentId(): ?int
    {
        return $this->resolvedContext?->getContextId();
    }

    /**
     * Check if tenant isolation is enabled.
     */
    public function isEnabled(): bool
    {
        return config('neev.tenant_isolation', false);
    }

    /**
     * Check if identity strategy is isolated.
     */
    public function isIsolated(): bool
    {
        return config('neev.identity_strategy', 'shared') === 'isolated';
    }

    /**
     * Check if single tenant users is enabled.
     */
    public function singleTenantUsers(): bool
    {
        return config('neev.tenant_isolation_options.single_tenant_users', false);
    }
}
