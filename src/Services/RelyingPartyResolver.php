<?php

namespace Ssntpl\Neev\Services;

use Illuminate\Database\Eloquent\Model;
use Ssntpl\Neev\Models\Domain;

/**
 * Resolves the WebAuthn relying party for the current request's context.
 *
 * The host equal to the request's origin that the resolved context is served
 * at: its platform subdomain, derived from its slug (RFC 006 §4.3), or a
 * verified domain — the row the request resolved through, or one the context
 * owns. With no context, no origin, or no such host, the configured value
 * stands.
 *
 * Every host gets its own relying party, including a platform subdomain.
 * Sharing one across a zone means sharing credentials across it: a tenant able
 * to run script on its own `evil.platform.com` could start a ceremony that
 * returns a victim's credential ids and completes with the browser showing
 * `platform.com`, because that is the relying party both hosts answer to. A
 * per-host relying party makes a credential enrolled on one tenant's host
 * unusable on another's.
 */
class RelyingPartyResolver
{
    /** Resolved once per request (the resolver is request-scoped). */
    protected ?string $host = null;

    /** The verified row a custom host came from; its owner is read lazily. */
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
        return $this->host() ?? $this->configured();
    }

    /**
     * Origins permitted to complete a ceremony for this relying party.
     *
     * Every origin is named exactly; nothing is matched by suffix. The
     * configured list is kept on every path (it carries native-app facets,
     * which apply to all tenants) plus the one host this relying party was
     * taken from. Built from the slug and the domain records, never from the
     * request.
     *
     * One host, because the relying party is that host: a sibling under the
     * same zone now answers to its own, so there is nothing for it to be
     * admitted against.
     *
     * @return array<int, string>
     */
    public function allowedOrigins(): array
    {
        $host = $this->host();

        $own = $host !== null ? [$host] : [];

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
        // The domain may belong to a team that routes through the resolved
        // tenant, and the name to show is the one that owns the host.
        $context = $this->host() !== null
            ? ($this->domain->owner ?? $this->tenants->resolvedContext())
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

    /** The host this request's context offers, if any. */
    protected function host(): ?string
    {
        if (! $this->settled) {
            $this->settle();
            $this->settled = true;
        }

        return $this->host;
    }

    /**
     * The relying party: the verified domain equal to the request's origin,
     * else `configured()`. The row the request resolved through is taken
     * first, because it need not belong to the resolved context — in tenant
     * mode a team-owned host routes through that team's tenant. The match is
     * exact — a row covers the host it names and no other — because `domains`
     * is also the federation registry, so `acme.com` may sit there only so
     * `@acme.com` staff auto-join, never served.
     *
     * A platform subdomain is taken from the slug, not a row: the context's
     * current one. A retired one is never a relying party, so a ceremony on
     * it runs under `configured()` and the browser refuses it. The host
     * string is the same one a row used to hold, so passkeys enrolled against
     * it keep working. `acme.platform.com` is its own relying party; the
     * platform keeps `configured()` for the hosts it serves itself, which
     * resolve no context.
     */
    protected function settle(): void
    {
        $this->host = null;
        $this->domain = null;

        $context = $this->tenants->resolvedContext();

        if ($context === null) {
            return;
        }

        $origin = $this->originHost();

        // Nothing to match: a request that names no origin runs under the
        // configured relying party, which the browser refuses on a tenant's
        // own host — the ceremony endpoints are POST so that this is rare.
        if ($origin === '') {
            return;
        }

        if ($origin === $this->tenants->platformHost($context)) {
            $this->host = $origin;

            return;
        }

        // The row the request resolved through, which is not always among the
        // context's own: in tenant mode a team-owned host routes through that
        // team's tenant, so the tenant holds no row naming it. It is still the
        // host the browser is on, so it is still the relying party.
        $resolved = $this->tenants->currentDomain();

        if ($resolved !== null
            && $resolved->verified_at !== null
            && Domain::canonicalHost($resolved->domain) === $origin) {
            $this->host = $origin;
            $this->domain = $resolved;

            return;
        }

        $this->domain = Domain::where('owner_type', $context->getContextType())
            ->where('owner_id', $context->getContextId())
            ->whereNotNull('verified_at')
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get()
            ->first(fn (Domain $row) => Domain::canonicalHost($row->domain) === $origin);

        $this->host = $this->domain !== null ? $origin : null;
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
