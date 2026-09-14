<?php

namespace Ssntpl\Neev\Services;

use Ssntpl\Neev\Models\Domain;

/**
 * Decides which WebAuthn relying party ID a ceremony may use.
 *
 * A passkey is bound to exactly one relying party ID for its lifetime, and the
 * browser only runs a ceremony when that ID is the origin's host or a
 * registrable suffix of it. A single app-wide value therefore locks passkeys
 * to the platform domain; reading it off the request's context is what lets a
 * tenant on its own verified domain use them.
 *
 * The rule is one line: the resolved context's primary domain, or its first
 * verified domain when none is primary. No context, no domain, or a domain
 * inside the platform's own zone, and the configured value stands.
 */
class RelyingPartyResolver
{
    /**
     * The settled domain, and whether it has been settled.
     *
     * A ceremony asks for the relying party, the allowed origin and the
     * subdomain rule, which are three readings of one answer. The resolver is
     * bound per request, so this holds the lookup to once per ceremony while
     * still re-reading on the next request — which is what lets a domain that
     * loses its verification stop answering immediately.
     */
    protected ?Domain $domain = null;

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
     * On the configured relying party this is the configured list, unchanged.
     * A tenant's own domain is not in that list and never will be, so it
     * admits its own origin instead — built from the domain record rather
     * than from the request, so a call arriving over http or on an odd port
     * cannot widen what the ceremony accepts.
     */
    public function allowedOrigins(): array
    {
        $domain = $this->domain();

        $configured = (array) config('neev.allowed_origins', []);

        return $domain !== null
            ? array_unique(array_merge($configured, ['https://' . Domain::canonicalHost($domain->domain)]))
            : $configured;
    }

    /**
     * Whether subdomains of an allowed origin may complete the ceremony.
     *
     * Only the configured relying party honours the setting. A tenant's domain
     * admits that host alone: verify the exact host you serve passkeys from.
     */
    public function allowSubdomains(): bool
    {
        return $this->domain() !== null
            ? false
            : (bool) config('neev.allow_origin_subdomains', false);
    }

    /**
     * The application-wide relying party ID.
     */
    public function configured(): string
    {
        return Domain::canonicalHost((string) config('neev.relying_party_id'));
    }

    /**
     * Whether a browser on this host can run a ceremony for this relying
     * party — the WebAuthn rule, exposed so a UI can hide a control that
     * would only fail.
     */
    public function usableFrom(string $rpId, string $host): bool
    {
        return $rpId !== '' && ($host === $rpId || str_ends_with($host, '.' . $rpId));
    }

    /**
     * The domain this request's context offers, if any.
     */
    protected function domain(): ?Domain
    {
        if (! $this->settled) {
            $this->domain = $this->settle();
            $this->settled = true;
        }

        return $this->domain;
    }

    protected function settle(): ?Domain
    {
        $context = $this->tenants->resolvedContext();

        if ($context === null) {
            return null;
        }

        $domain = Domain::where('owner_type', $context->getContextType())
            ->where('owner_id', $context->getContextId())
            ->whereNotNull('verified_at')
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();

        if ($domain === null || $this->usableFrom($this->configured(), Domain::canonicalHost($domain->domain))) {
            return null;
        }

        return $domain;
    }
}
