<?php

namespace Ssntpl\Neev\Services;

use Illuminate\Database\Eloquent\Model;
use Ssntpl\Neev\Models\Domain;

/**
 * Resolves the WebAuthn relying party for the current request's context.
 *
 * The context's primary verified domain, or its first verified one. With no
 * context, no domain, or only domains inside the platform's own zone, the
 * configured value stands.
 */
class RelyingPartyResolver
{
    /** Resolved once per request (the resolver is request-scoped). */
    protected ?Domain $domain = null;

    /**
     * Verified hosts under the configured relying party (`acme.platform.com`),
     * admitted as exact origins instead of by subdomain matching.
     *
     * @var array<int, string>
     */
    protected array $platformHosts = [];

    protected bool $settled = false;

    public function __construct(protected TenantResolver $tenants)
    {
    }

    /**
     * The relying party ID for this request's context.
     */
    public function rpId(): string
    {
        $domain = $this->domain();

        return $domain !== null
            ? Domain::canonicalHost($domain->domain)
            : $this->configured();
    }

    /**
     * Origins permitted to complete a ceremony for this relying party.
     *
     * Every origin is named exactly; nothing is matched by suffix. The
     * configured list is kept on every path (it carries native-app facets,
     * which apply to all tenants) plus either the tenant's own domain or its
     * verified hosts under the platform domain. Built from the domain records,
     * never from the request.
     *
     * @return array<int, string>
     */
    public function allowedOrigins(): array
    {
        $domain = $this->domain();

        $own = $domain !== null
            ? [Domain::canonicalHost($domain->domain)]
            : $this->platformHosts;

        return array_values(array_unique(array_merge(
            (array) config('neev.allowed_origins', []),
            array_map(fn (string $host) => 'https://' . $host, $own)
        )));
    }

    /**
     * Name authenticators display: the tenant's on its own domain, otherwise
     * the application's.
     */
    public function rpName(): string
    {
        $context = $this->domain() !== null ? $this->tenants->resolvedContext() : null;

        // The context interfaces declare no name; Team and Tenant carry one as
        // an Eloquent attribute, and a custom context may not.
        $name = $context instanceof Model ? $context->getAttribute('name') : null;

        return is_string($name) && $name !== ''
            ? $name
            : (string) (config('app.name') ?: $this->configured());
    }

    /**
     * Whether subdomains of an allowed origin may complete the ceremony.
     *
     * Never: a compromised sibling host would otherwise assert as any user.
     * Security invariant, so no config key — override this method and rebind
     * the class to widen it.
     */
    public function allowSubdomains(): bool
    {
        return false;
    }

    /** The application-wide relying party ID. */
    public function configured(): string
    {
        return Domain::canonicalHost((string) config('neev.relying_party_id'));
    }

    /**
     * The WebAuthn host rule, exposed so a UI can hide a control that would
     * only fail.
     */
    public function usableFrom(string $rpId, string $host): bool
    {
        return $rpId !== '' && ($host === $rpId || str_ends_with($host, '.' . $rpId));
    }

    /** The domain this request's context offers, if any. */
    protected function domain(): ?Domain
    {
        if (! $this->settled) {
            $this->settle();
            $this->settled = true;
        }

        return $this->domain;
    }

    /**
     * Sort the context's verified domains: hosts under the configured relying
     * party become extra origins on it, the first host outside it becomes the
     * tenant's own relying party. Keeping subdomain tenants on the platform
     * relying party preserves passkeys already enrolled there.
     */
    protected function settle(): void
    {
        $this->domain = null;
        $this->platformHosts = [];

        $context = $this->tenants->resolvedContext();

        if ($context === null) {
            return;
        }

        $verified = Domain::where('owner_type', $context->getContextType())
            ->where('owner_id', $context->getContextId())
            ->whereNotNull('verified_at')
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get();

        foreach ($verified as $row) {
            $host = Domain::canonicalHost($row->domain);

            if ($this->usableFrom($this->configured(), $host)) {
                $this->platformHosts[] = $host;
            } elseif ($this->domain === null) {
                $this->domain = $row;
            }
        }
    }
}
