<?php

namespace Ssntpl\Neev\Services;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
        if (!config('neev.tenant', false)) {
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
        // Check domain via domains table (cached for 5 minutes)
        $isIsolated = $this->isIsolated();

        /** @var array{context_type: string, context_id: int}|null $cachedContext */
        $cachedContext = Cache::remember("neev:domain:{$host}", 300, function () use ($host, $isIsolated): ?array {
            $domain = Domain::findByHost($host);

            if (! $domain || ! $domain->owner) {
                return null;
            }

            if ($isIsolated) {
                if ($domain->owner_type === 'tenant') {
                    return ['context_type' => 'tenant', 'context_id' => $domain->owner->getKey()];
                }

                // Domain owned by a team — resolve the team's tenant
                $tenant = $domain->owner->tenant ?? null;
                if ($tenant) {
                    return ['context_type' => 'tenant', 'context_id' => $tenant->getKey()];
                }
            } else {
                if ($domain->owner_type === 'team') {
                    return ['context_type' => 'team', 'context_id' => $domain->owner->getKey()];
                }
            }

            return null;
        });

        if ($cachedContext) {
            // Re-fetch the context and domain models from the cached IDs
            if ($cachedContext['context_type'] === 'tenant') {
                $context = Tenant::getClass()::find($cachedContext['context_id']);
            } else {
                $context = Team::getClass()::find($cachedContext['context_id']);
            }

            if ($context) {
                // Fetch the domain record for the customDomain reference
                $domain = Domain::findByHost($host);

                return ['context' => $context, 'via' => 'custom', 'domain' => $host, 'customDomain' => $domain];
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
     *
     * Both Team and Tenant implement all four context interfaces.
     *
     * @return (ContextContainerInterface&\Ssntpl\Neev\Contracts\IdentityProviderOwnerInterface&\Ssntpl\Neev\Contracts\HasMembersInterface)|null
     */
    public function resolvedContext(): ?ContextContainerInterface
    {
        /** @var (ContextContainerInterface&\Ssntpl\Neev\Contracts\IdentityProviderOwnerInterface&\Ssntpl\Neev\Contracts\HasMembersInterface)|null */
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
        return config('neev.tenant', false);
    }

    /**
     * Check if identity strategy is isolated.
     */
    public function isIsolated(): bool
    {
        return config('neev.tenant', false);
    }

    /**
     * Run a callback within a specific tenant/team context.
     *
     * Useful for platform code that needs to create tenant-scoped records
     * outside of a request (e.g., provisioning the first user for a new tenant).
     */
    public function runInContext(ContextContainerInterface $context, Closure $callback): mixed
    {
        $previous = [
            'context' => $this->resolvedContext,
            'tenant' => $this->resolvedTenantModel,
            'team' => $this->currentTenant,
            'via' => $this->resolvedVia,
            'domain' => $this->resolvedDomain,
            'customDomain' => $this->resolvedCustomDomain,
        ];

        $this->setResolved($context, 'manual', 'manual');

        try {
            return $callback();
        } finally {
            $this->resolvedContext = $previous['context'];
            $this->resolvedTenantModel = $previous['tenant'];
            $this->currentTenant = $previous['team'];
            $this->resolvedVia = $previous['via'];
            $this->resolvedDomain = $previous['domain'];
            $this->resolvedCustomDomain = $previous['customDomain'];

            if (app()->bound(ContextManager::class)) {
                if ($previous['context']) {
                    app(ContextManager::class)->setContext($previous['context']);
                } else {
                    app(ContextManager::class)->clear();
                }
            }
        }
    }
}
