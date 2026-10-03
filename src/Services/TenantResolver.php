<?php

namespace Ssntpl\Neev\Services;

use Closure;
use Illuminate\Http\Request;
use LogicException;
use Illuminate\Support\Facades\Cache;
use Ssntpl\Neev\Contracts\ContextContainerInterface;
use Ssntpl\Neev\Contracts\HasMembersInterface;
use Ssntpl\Neev\Contracts\IdentityProviderOwnerInterface;
use Ssntpl\Neev\Contracts\ResolvableContextInterface;
use Illuminate\Database\Eloquent\Model;
use Ssntpl\Neev\Models\Domain;
use Ssntpl\Neev\Models\RetiredSlug;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\Tenant;
use Ssntpl\Neev\Support\PlatformHost;

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
     * How the tenant was resolved ('header', 'subdomain', 'retired', 'custom').
     */
    protected ?string $resolvedVia = null;

    /**
     * The domain or value used to resolve the tenant.
     */
    protected ?string $resolvedDomain = null;

    /**
     * Whether the X-Tenant header named a slug its owner has since given up.
     */
    protected bool $headerSlugRetired = false;

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
        // Isolated mode resolves a Tenant; shared mode resolves the Team that
        // owns the domain, which is what makes per-team SSO reachable — a team
        // carries its own auth settings and is an identity provider owner just
        // as a tenant is.
        if (!$this->isEnabled() && !config('neev.team', false)) {
            return null;
        }

        // 1. Try X-Tenant header resolution
        $headerValue = $request->header('X-Tenant');
        if ($headerValue !== null) {
            $result = $this->resolveFromHeader($headerValue);
            if ($result) {
                $context = $this->setResolved($result['context'], $result['via'], $result['domain'], $result['customDomain'] ?? null);
                $this->headerSlugRetired = $result['retiredSlug'] ?? false;

                return $context;
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

        // Then by one its owner gave up within the window
        $context = $this->retiredSlugOwner($headerValue);
        if ($context) {
            return ['context' => $context, 'via' => 'header', 'domain' => $headerValue, 'retiredSlug' => true];
        }

        // Try by domain (subdomain or custom domain)
        return $this->resolveFromHost($headerValue);
    }

    /**
     * Resolve tenant from a host string (subdomain or custom domain).
     */
    protected function resolveFromHost(string $host): ?array
    {
        // A platform host names its owner's slug, so it needs no row. Not
        // cached: it is one indexed lookup, and a rename takes effect at once.
        // Neither slug answering, the domain rows are still checked below.
        $slug = PlatformHost::slugOf($host);
        $platform = $slug !== null ? $this->platformContext($slug) : null;

        if ($platform !== null) {
            return $platform;
        }

        $isIsolated = $this->isIsolated();

        /** @var array{context_type: string, context_id: int}|null $cachedContext */
        $cachedContext = Cache::remember("neev:domain:{$host}", 300, function () use ($host, $isIsolated): ?array {
            $domain = $this->domainForMode($host, $isIsolated);
            $owner = $domain ? $this->domainOwner($domain) : null;

            if (! $owner) {
                return null;
            }

            if ($isIsolated) {
                if ($domain->owner_type === 'tenant') {
                    return ['context_type' => 'tenant', 'context_id' => $owner->getKey()];
                }

                // Domain owned by a team — resolve the team's tenant
                $tenant = $owner->tenant ?? null;
                if ($tenant) {
                    return ['context_type' => 'tenant', 'context_id' => $tenant->getKey()];
                }
            } else {
                if ($domain->owner_type === 'team') {
                    return ['context_type' => 'team', 'context_id' => $owner->getKey()];
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
                $domain = $this->domainForMode($host, $isIsolated);

                return ['context' => $context, 'via' => 'custom', 'domain' => $host, 'customDomain' => $domain];
            }
        }

        return null;
    }

    /**
     * The owner the platform host for a slug names (RFC 006 §4.3): the one
     * holding its slug now, else the one that gave it up within
     * `neev.slug.retired_host_days`. Shaped like resolveFromHost()'s result.
     *
     * Only the owner kind this mode routes on has a platform host: tenants in
     * isolated mode, where team slugs are unique per tenant only, and teams in
     * shared mode.
     *
     * @return array{context: ContextContainerInterface, via: string, domain: string}|null
     */
    protected function platformContext(string $slug): ?array
    {
        $host = PlatformHost::for($slug);
        $model = $this->getResolvableModel();

        $owner = $model::resolveBySlug($slug);

        if ($owner instanceof ContextContainerInterface) {
            return ['context' => $owner, 'via' => 'subdomain', 'domain' => $host];
        }

        $owner = $this->retiredSlugOwner($slug);

        return $owner !== null
            ? ['context' => $owner, 'via' => 'retired', 'domain' => $host]
            : null;
    }

    /**
     * The owner that gave up a slug within `neev.slug.retired_host_days`, of
     * the kind this mode routes on. A retired slug is never reissued, so this
     * can only be its last holder; past the window it is nobody.
     */
    protected function retiredSlugOwner(string $slug): ?ContextContainerInterface
    {
        $type = $this->isIsolated() ? 'tenant' : 'team';
        $days = (int) config('neev.slug.retired_host_days', 0);

        if ($days <= 0) {
            return null;
        }

        $retired = RetiredSlug::heldAgainst($type, $slug)
            ->where('created_at', '>=', now()->subDays($days))
            ->latest('id')
            ->first();

        if ($retired === null) {
            return null;
        }

        return $type === 'team'
            ? Team::getClass()::withoutTenantScope()->find($retired->owner_id)
            : Tenant::getClass()::find($retired->owner_id);
    }

    /**
     * The host the platform serves a context at now: its slug under
     * `neev.platform_domain`. Null for a context of a kind this mode does not
     * route on — in isolated mode a team's slug is unique only within its
     * tenant, so it names no host.
     */
    public function platformHost(?ContextContainerInterface $context = null): ?string
    {
        $context ??= $this->resolvedContext;

        if (! $context instanceof Model
            || $context->getContextType() !== ($this->isIsolated() ? 'tenant' : 'team')) {
            return null;
        }

        return PlatformHost::for($context->getAttribute('slug'));
    }

    /**
     * The verified domain row this host resolves through, chosen by the owner
     * kind the active mode routes on rather than by row order: shared mode
     * only ever routes a team, and isolated mode takes a tenant's own claim
     * ahead of a team's, which it has to route through that team's tenant.
     */
    protected function domainForMode(string $host, bool $isIsolated): ?Domain
    {
        if (! $isIsolated) {
            return Domain::findByHostForOwnerType($host, 'team');
        }

        return Domain::findByHostForOwnerType($host, 'tenant')
            ?? Domain::findByHostForOwnerType($host, 'team');
    }

    /**
     * The model owning a domain.
     *
     * A team owner is read without the team tenant scope: this runs while
     * resolving the tenant, so there is no resolved tenant yet to match.
     */
    protected function domainOwner(Domain $domain)
    {
        if ($domain->owner_type === 'team') {
            return Team::getClass()::withoutTenantScope()->find($domain->owner_id);
        }

        return $domain->owner;
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
        $this->headerSlugRetired = false;

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
     * Platform subdomains (current or retired) and header-resolved tenants are
     * always verified.
     * Custom domains require explicit verification.
     */
    public function isResolvedDomainVerified(): bool
    {
        if (in_array($this->resolvedVia, ['subdomain', 'retired', 'header'], true)) {
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
     * @return (ContextContainerInterface&IdentityProviderOwnerInterface&HasMembersInterface)|null
     */
    public function resolvedContext(): ?ContextContainerInterface
    {
        /** @var (ContextContainerInterface&IdentityProviderOwnerInterface&HasMembersInterface)|null */
        return $this->resolvedContext;
    }

    /**
     * How the tenant was resolved ('header', 'subdomain', 'retired', 'custom').
     * 'retired' is a platform host whose slug its owner has since given up.
     */
    public function resolvedVia(): ?string
    {
        return $this->resolvedVia;
    }

    /**
     * Whether the X-Tenant header named a retired slug: the request was served
     * within `neev.slug.retired_host_days`, and the client should switch to
     * the context's current slug.
     */
    public function headerSlugRetired(): bool
    {
        return $this->headerSlugRetired;
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
        $this->headerSlugRetired = false;

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
        // A request that has bound its context cannot change it — that is the
        // point of binding. Refusing here, before anything is mutated, is the
        // difference between a clear error and a resolver quietly pointing
        // somewhere else: ContextManager would throw from inside setResolved()
        // once this resolver had already moved, and the restore below would
        // throw the same way. Call this from a queued job, a command, or
        // before BindContextMiddleware runs.
        if (app()->bound(ContextManager::class) && app(ContextManager::class)->isBound()) {
            throw new LogicException(
                'runInContext() cannot re-enter a context on a request that has already bound one.'
            );
        }

        $previous = [
            'context' => $this->resolvedContext,
            'tenant' => $this->resolvedTenantModel,
            'team' => $this->currentTenant,
            'via' => $this->resolvedVia,
            'domain' => $this->resolvedDomain,
            'customDomain' => $this->resolvedCustomDomain,
            'headerSlugRetired' => $this->headerSlugRetired,
        ];

        try {
            // Inside the try, because setResolved() can throw: it assigns this
            // resolver's fields and only then hands the context to
            // ContextManager. A throw between the two left the resolver
            // pointing at the new context with nothing to put it back, so
            // every tenant-scoped query for the rest of the request ran
            // against the wrong tenant while ContextManager still reported the
            // right one.
            $this->setResolved($context, 'manual', 'manual');

            return $callback();
        } finally {
            $this->resolvedContext = $previous['context'];
            $this->resolvedTenantModel = $previous['tenant'];
            $this->currentTenant = $previous['team'];
            $this->resolvedVia = $previous['via'];
            $this->resolvedDomain = $previous['domain'];
            $this->resolvedCustomDomain = $previous['customDomain'];
            $this->headerSlugRetired = $previous['headerSlugRetired'];

            if (app()->bound(ContextManager::class)) {
                $manager = app(ContextManager::class);

                // Nothing to restore onto a context the callback itself bound:
                // clear() resets the bound flag as well as the context, so
                // putting it back here would silently un-bind a request that
                // is now relying on it. The guard above means we never started
                // from a bound one.
                //
                // A callback that binds therefore ends with the resolver
                // restored and the manager holding what the callback bound.
                // That is deliberate and it is the lesser of the two: binding
                // inside a callback is the caller saying this context is the
                // request's from here on, and un-binding it behind their back
                // would be worse than the two disagreeing.
                if (!$manager->isBound()) {
                    if ($previous['context']) {
                        $manager->setContext($previous['context']);
                    } else {
                        $manager->clear();
                    }
                }
            }
        }
    }
}
