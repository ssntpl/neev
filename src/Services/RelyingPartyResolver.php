<?php

namespace Ssntpl\Neev\Services;

use Illuminate\Database\Eloquent\Model;
use Ssntpl\Neev\Models\Domain;

/**
 * Resolves the WebAuthn relying party for the current request's context.
 *
 * The verified domain equal to the request's origin — the row the request
 * resolved through, or one the resolved context owns. With no context, no
 * such domain, or only domains inside the platform's own zone, the configured
 * value stands.
 */
class RelyingPartyResolver
{
    /** Resolved once per request (the resolver is request-scoped). */
    protected ?Domain $domain = null;

    /**
     * The request's own verified host under the configured relying party
     * (`acme.platform.com`), admitted as an exact origin instead of by
     * subdomain matching.
     *
     * Only the host the request names, never every platform-zone row the
     * context holds: those hosts all share the configured relying party, so
     * admitting a sibling would let a ceremony run there complete against a
     * credential enrolled here. What remains is the platform's own boundary —
     * the platform serves every host in its zone.
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
     * which apply to all tenants) plus either the tenant's own domain or the
     * one verified host under the platform domain the request names. Built
     * from the domain records, never from the request.
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
     * Name authenticators display: the domain owner's on its own domain,
     * otherwise the application's.
     */
    public function rpName(): string
    {
        $domain = $this->domain();

        // The domain may belong to a team that routes through the resolved
        // tenant, and the name to show is the one that owns the host.
        $context = $domain !== null
            ? ($domain->owner ?? $this->tenants->resolvedContext())
            : null;

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
     * The relying party: the verified domain equal to the request's origin,
     * else `configured()`. The row the request resolved through is taken
     * first, because it need not belong to the resolved context — in tenant
     * mode a team-owned host routes through that team's tenant. The match is
     * exact — a row covers the host it names and no other — because `domains`
     * is also the federation registry, so `acme.com` may sit there only so
     * `@acme.com` staff auto-join, never served. A row in the platform's own
     * zone keeps the platform relying party, so a subdomain tenant does not
     * displace it; that host becomes an extra origin only when it is the one
     * the request names, never a sibling's.
     */
    protected function settle(): void
    {
        $this->domain = null;
        $this->platformHosts = [];

        $context = $this->tenants->resolvedContext();

        if ($context === null) {
            return;
        }

        $origin = $this->originHost();

        // The row the request resolved through, which is not always among the
        // context's own: in tenant mode a team-owned host routes through that
        // team's tenant, so the tenant holds no row naming it. It is still the
        // host the browser is on, so it is still the relying party.
        $resolved = $this->tenants->currentDomain();

        if ($resolved !== null && $resolved->verified_at !== null) {
            $host = Domain::canonicalHost($resolved->domain);

            if ($this->usableFrom($this->configured(), $host)) {
                if ($host === $origin) {
                    $this->platformHosts[] = $host;
                }
            } elseif ($host === $origin) {
                $this->domain = $resolved;

                return;
            }
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
                if ($host === $origin) {
                    $this->platformHosts[] = $host;
                }
            } elseif ($this->domain === null && $host === $origin) {
                $this->domain = $row;
            }
        }

        $this->platformHosts = array_values(array_unique($this->platformHosts));
    }

    /**
     * The host the browser is on, per its `Origin` header — never the request's
     * host, which is only where the request was addressed. No origin, or one
     * that is not a host (`null` from a sandboxed frame, a native app's facet),
     * matches nothing and leaves `configured()`. Browsers attach `Origin`
     * themselves on cross-origin requests and on every POST, and omit it on a
     * same-origin GET, where it is a forbidden header name a client cannot add
     * back — so such a request runs under `configured()`.
     */
    protected function originHost(): string
    {
        $origin = (string) request()->headers->get('Origin');

        if ($origin === '') {
            return '';
        }

        return Domain::canonicalHost((string) (parse_url($origin, PHP_URL_HOST) ?: ''));
    }
}
