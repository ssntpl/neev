<?php

namespace Ssntpl\Neev\Tests\Unit\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Ssntpl\Neev\Database\Factories\DomainFactory;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Database\Factories\TenantFactory;
use Ssntpl\Neev\Models\Domain;
use Ssntpl\Neev\Services\RelyingPartyResolver;
use Ssntpl\Neev\Services\TenantResolver;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class RelyingPartyResolverTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    private RelyingPartyResolver $resolver;

    private TenantResolver $tenantResolver;

    protected function setUp(): void
    {
        parent::setUp();
        config(['neev.relying_party_id' => 'example.com']);
        $this->tenantResolver = app(TenantResolver::class);
        $this->resolver = new RelyingPartyResolver($this->tenantResolver);
    }

    /**
     * Resolve a request's context, as the tenant middleware does before any
     * passkey route runs. `$tenant` is the `X-Tenant` header, `$origin` the
     * host the browser says it is on (the request's own unless given; `''`
     * for a client that sends no `Origin`).
     */
    private function resolveOn(string $host, ?object $tenant = null, ?string $origin = null): void
    {
        $request = Request::create("https://{$host}/neev/passkeys");

        if ($tenant !== null) {
            $request->headers->set('X-Tenant', (string) $tenant->getKey());
        }

        $origin ??= $host;

        if ($origin !== '') {
            $request->headers->set('Origin', "https://{$origin}");
        }

        // The resolver reads the origin off the current request.
        $this->app->instance('request', $request);

        $this->tenantResolver->clear();
        $this->tenantResolver->resolve($request);
    }

    /**
     * The relying party a fresh request on this host resolves — the resolver
     * settles once, so reusing one across hosts re-reads the first answer.
     */
    private function forHost(string $host): string
    {
        $this->resolveOn($host);

        return (new RelyingPartyResolver($this->tenantResolver))->rpId();
    }

    /** A team owning one verified domain. */
    private function teamOwning(string $host): object
    {
        $team = TeamFactory::new()->create();
        $this->domainFor($team, $host);

        return $team;
    }

    private function domainFor(object $owner, string $host, bool $primary = false): Domain
    {
        return DomainFactory::new()->verified()->create([
            'owner_type' => $owner->getContextType(),
            'owner_id' => $owner->getKey(),
            'domain' => $host,
            'is_primary' => $primary,
        ]);
    }

    // ---------------------------------------------------------------
    // The context's domain
    // ---------------------------------------------------------------

    public function test_the_contexts_domain_is_the_relying_party(): void
    {
        $this->enableTeams();
        $this->teamOwning('acme.com');

        $this->assertSame('acme.com', $this->forHost('acme.com'));
    }

    /**
     * The primary decides nothing here: the origin does. Rank cannot hand a
     * browser on `acme.com` a relying party of `acme.io`, which it would only
     * refuse.
     */
    public function test_the_primary_does_not_override_the_origin(): void
    {
        $this->enableTeams();
        $team = $this->teamOwning('acme.com');
        $this->domainFor($team, 'acme.io', primary: true);

        $this->assertSame('acme.com', $this->forHost('acme.com'));
        $this->assertSame('acme.io', $this->forHost('acme.io'));
    }

    /**
     * A row covers the host it names and no other, primary or not: a verified
     * subdomain is its own relying party, with its own credentials.
     */
    public function test_a_verified_subdomain_does_not_run_under_its_parent(): void
    {
        $this->enableTeams();
        $team = $this->teamOwning('app.acme.io');
        $this->domainFor($team, 'acme.io', primary: true);

        $this->assertSame('app.acme.io', $this->forHost('app.acme.io'));
    }

    /**
     * And an unverified one is nobody's: `acme.io` is not offered to a browser
     * on `app.acme.io`, so verify the host users sign in on.
     */
    public function test_an_unverified_subdomain_keeps_the_configured_relying_party(): void
    {
        $this->enableTeams();
        $team = $this->teamOwning('acme.io');

        $this->resolveOn('acme.io', origin: 'app.acme.io');

        $this->assertSame('example.com', $this->resolver->rpId());
    }

    /** Nothing has to be marked primary for a domain to be offered. */
    public function test_a_context_without_a_primary_still_gets_a_relying_party(): void
    {
        $this->enableTeams();
        $team = $this->teamOwning('acme.com');
        $this->domainFor($team, 'acme.io');

        $this->assertSame('acme.com', $this->forHost('acme.com'));
        $this->assertSame('acme.io', $this->forHost('acme.io'));
    }

    public function test_an_unverified_domain_is_never_offered(): void
    {
        $this->enableTeams();
        $team = TeamFactory::new()->create();
        DomainFactory::new()->create([
            'owner_type' => $team->getContextType(), 'owner_id' => $team->getKey(),
            'domain' => 'acme.com', 'is_primary' => true,
        ]);

        $this->assertSame('example.com', $this->forHost('acme.com'));
    }

    /**
     * An unverified primary does not shadow a verified domain — only verified
     * rows are considered at all.
     */
    public function test_an_unverified_primary_does_not_shadow_a_verified_domain(): void
    {
        $this->enableTeams();
        $team = $this->teamOwning('acme.com');
        DomainFactory::new()->create([
            'owner_type' => $team->getContextType(), 'owner_id' => $team->getKey(),
            'domain' => 'acme.io', 'is_primary' => true,
        ]);

        $this->assertSame('acme.com', $this->forHost('acme.com'));
    }

    public function test_a_revoked_domain_stops_granting_a_relying_party(): void
    {
        $this->enableTeams();
        $this->teamOwning('acme.com');

        $this->assertSame('acme.com', $this->forHost('acme.com'));

        Domain::where('domain', 'acme.com')->update(['verified_at' => null]);

        $this->resolveOn('acme.com');
        $this->assertSame('example.com', (new RelyingPartyResolver($this->tenantResolver))->rpId());
    }

    public function test_no_context_keeps_the_configured_relying_party(): void
    {
        $this->enableTeams();
        $this->teamOwning('acme.com');

        $this->assertSame('example.com', $this->forHost('somewhere-else.com'));
    }

    // ---------------------------------------------------------------
    // The platform's own zone
    // ---------------------------------------------------------------

    public function test_the_configured_host_uses_the_configured_relying_party(): void
    {
        $this->assertSame('example.com', $this->forHost('example.com'));
    }

    /**
     * Subdomains hold domain rows too — the tenant-domains API verifies
     * `type: subdomain` on sight — so a verified row there must not displace
     * the platform relying party and retire the passkeys already enrolled
     * under it.
     */
    public function test_a_verified_subdomain_still_uses_the_configured_relying_party(): void
    {
        $this->enableTeams();
        $this->teamOwning('acme.example.com');

        $this->assertSame('example.com', $this->forHost('acme.example.com'));
    }

    public function test_a_host_merely_ending_in_the_configured_value_is_not_a_subdomain(): void
    {
        $this->enableTeams();
        $this->teamOwning('evil-example.com');

        $this->assertSame('evil-example.com', $this->forHost('evil-example.com'));
    }

    public function test_hosts_are_stored_and_compared_canonically(): void
    {
        $this->enableTeams();
        $this->teamOwning('ACME.com.');

        $this->assertSame('acme.com', $this->forHost('acme.com'));
    }

    // ---------------------------------------------------------------
    // A context resolved by any means
    // ---------------------------------------------------------------

    /**
     * The API's own host plays no part: a SPA naming its context in `X-Tenant`
     * is on `acme.com` and says so in `Origin`.
     */
    public function test_a_header_resolved_context_offers_its_domain(): void
    {
        $this->enableTeams();
        $team = $this->teamOwning('acme.com');
        config(['neev.allowed_origins' => ['https://example.com']]);

        $this->resolveOn('api.platform.test', $team, origin: 'acme.com');

        $this->assertSame('acme.com', $this->resolver->rpId());
        $this->assertSame(['https://example.com', 'https://acme.com'], $this->resolver->allowedOrigins());
    }

    /**
     * A native app's origin is a facet, not a host: it keeps the configured
     * relying party, the only one it can hold platform assets for.
     */
    public function test_a_context_named_without_an_origin_keeps_the_configured_relying_party(): void
    {
        $this->enableTeams();
        $team = $this->teamOwning('acme.com');

        $this->resolveOn('api.platform.test', $team, origin: '');

        $this->assertSame('example.com', $this->resolver->rpId());
    }

    /**
     * The request's host is never read in place of the origin. Browsers omit
     * `Origin` on a same-origin GET and a client cannot add it back, so such a
     * request runs under the configured relying party; a ceremony on the
     * tenant's own domain reaches the options endpoint cross-origin or by POST.
     */
    public function test_the_requests_host_is_not_read_in_place_of_a_missing_origin(): void
    {
        $this->enableTeams();
        $this->teamOwning('acme.com');

        $this->resolveOn('acme.com', origin: '');

        $this->assertSame('example.com', $this->resolver->rpId());
    }

    /** A `null` origin — a sandboxed frame — names no host and matches none. */
    public function test_an_origin_that_is_not_a_host_matches_nothing(): void
    {
        $this->enableTeams();
        $this->teamOwning('acme.com');

        $request = Request::create('https://acme.com/neev/passkeys');
        $request->headers->set('Origin', 'null');
        $this->app->instance('request', $request);
        $this->tenantResolver->clear();
        $this->tenantResolver->resolve($request);

        $this->assertSame('example.com', (new RelyingPartyResolver($this->tenantResolver))->rpId());
    }

    // ---------------------------------------------------------------
    // The four tenancy modes
    // ---------------------------------------------------------------

    public function test_neither_tenants_nor_teams_leaves_the_configured_relying_party(): void
    {
        config(['neev.tenant' => false, 'neev.team' => false]);
        $this->teamOwning('acme.com');

        $this->assertSame('example.com', $this->forHost('acme.com'));
    }

    public function test_teams_only_resolves_a_team_owned_domain(): void
    {
        $this->enableTeams();
        $this->teamOwning('acme.com');

        $this->assertSame('acme.com', $this->forHost('acme.com'));
    }

    public function test_tenants_only_resolves_a_tenant_owned_domain(): void
    {
        $this->enableTenantIsolation();
        $tenant = TenantFactory::new()->create();
        $this->domainFor($tenant, 'acme.com', primary: true);

        $this->assertSame('acme.com', $this->forHost('acme.com'));
    }

    /**
     * In isolated mode a team's domain resolves that team's tenant, so the
     * relying party is read off the tenant the domain routes to.
     */
    public function test_tenants_and_teams_resolves_through_the_tenant(): void
    {
        $this->enableTenantIsolation();
        $this->enableTeams();
        $tenant = TenantFactory::new()->create();
        $team = TeamFactory::new()->create(['tenant_id' => $tenant->id]);
        $this->domainFor($team, 'acme.com');
        $this->domainFor($tenant, 'acme.com');

        $this->assertSame('acme.com', $this->forHost('acme.com'));
    }

    /**
     * The team alone holds the row, and it routes to the tenant, so the tenant
     * has no row of its own naming the host. The row the request resolved
     * through is still the host the browser is on, and still the relying
     * party — reading only the tenant's rows would drop it to `configured()`,
     * which its browser then refuses.
     */
    public function test_a_team_owned_domain_is_the_relying_party_under_its_tenant(): void
    {
        $this->enableTenantIsolation();
        $this->enableTeams();
        $tenant = TenantFactory::new()->create();
        $team = TeamFactory::new()->create(['tenant_id' => $tenant->id]);
        $this->domainFor($team, 'acme.com');

        $this->assertSame('acme.com', $this->forHost('acme.com'));
    }

    /** An unverified row routes nothing, so it grants nothing either. */
    public function test_an_unverified_team_owned_domain_grants_nothing(): void
    {
        $this->enableTenantIsolation();
        $this->enableTeams();
        $tenant = TenantFactory::new()->create();
        $team = TeamFactory::new()->create(['tenant_id' => $tenant->id]);
        $this->domainFor($team, 'acme.com')->forceFill(['verified_at' => null])->save();

        $this->assertSame('example.com', $this->forHost('acme.com'));
    }

    public function test_a_tenant_owned_domain_is_not_resolved_in_shared_mode(): void
    {
        $this->enableTeams();
        $tenant = TenantFactory::new()->create();
        $this->domainFor($tenant, 'acme.com', primary: true);

        $this->assertSame('example.com', $this->forHost('acme.com'));
    }

    // ---------------------------------------------------------------
    // allowedOrigins() / allowSubdomains()
    // ---------------------------------------------------------------

    public function test_the_configured_relying_party_keeps_the_configured_origins(): void
    {
        config(['neev.allowed_origins' => ['https://example.com']]);
        $this->resolveOn('example.com');

        $this->assertSame(['https://example.com'], $this->resolver->allowedOrigins());
    }

    public function test_a_claimed_domain_merges_configured_and_tenant_origins(): void
    {
        $this->enableTeams();
        config(['neev.allowed_origins' => ['https://example.com']]);
        $this->teamOwning('acme.com');
        $this->resolveOn('acme.com');

        $this->assertSame(['https://example.com', 'https://acme.com'], $this->resolver->allowedOrigins());
    }

    /**
     * Native apps complete a ceremony with a non-URL facet as their origin —
     * Android sends `android:apk-key-hash:…` — and the platform's app serves
     * every tenant. Keeping the configured list whole is what lets that one
     * entry hold on a tenant's own relying party.
     */
    public function test_a_claimed_domain_keeps_configured_native_app_facets(): void
    {
        $this->enableTeams();
        config(['neev.allowed_origins' => ['https://example.com', 'android:apk-key-hash:abc123']]);
        $this->teamOwning('acme.com');
        $this->resolveOn('acme.com');

        $this->assertSame(
            ['https://example.com', 'android:apk-key-hash:abc123', 'https://acme.com'],
            $this->resolver->allowedOrigins()
        );
    }

    /**
     * Pins the documented rule: a credential on `acme.com` is offered to a
     * browser on `app.acme.com` by WebAuthn, but the server refuses the
     * origin. Verify the host users sign in on and make it primary.
     */
    public function test_a_subdomain_of_a_claimed_domain_is_not_an_allowed_origin(): void
    {
        $this->enableTeams();
        $this->teamOwning('acme.com');
        $this->resolveOn('acme.com');

        $this->assertNotContains('https://app.acme.com', $this->resolver->allowedOrigins());
        $this->assertFalse($this->resolver->allowSubdomains());
    }

    public function test_rp_name_is_the_tenants_name_on_its_own_domain(): void
    {
        $this->enableTeams();
        config(['app.name' => 'Platform']);
        $team = $this->teamOwning('acme.com');
        $team->forceFill(['name' => 'Acme Corp'])->save();
        $this->resolveOn('acme.com');

        $this->assertSame('Acme Corp', $this->resolver->rpName());
    }

    /**
     * The row's owner is the team, not the tenant it routes through, and the
     * name authenticators show is the one that owns the host users are on.
     */
    public function test_rp_name_is_the_owning_teams_under_its_tenant(): void
    {
        $this->enableTenantIsolation();
        $this->enableTeams();
        config(['app.name' => 'Platform']);
        $tenant = TenantFactory::new()->create(['name' => 'Acme Holdings']);
        $team = TeamFactory::new()->create(['tenant_id' => $tenant->id, 'name' => 'Acme Corp']);
        $this->domainFor($team, 'acme.com');
        $this->resolveOn('acme.com');

        $this->assertSame('Acme Corp', $this->resolver->rpName());
    }

    public function test_rp_name_is_the_app_name_on_the_platform(): void
    {
        config(['app.name' => 'Platform']);
        $this->resolveOn('example.com');

        $this->assertSame('Platform', $this->resolver->rpName());
    }

    /**
     * The origin is built from the domain record, not from the request — so a
     * request arriving over http, or on an odd port, cannot widen what the
     * ceremony will accept.
     */
    public function test_a_claimed_origin_ignores_the_requests_scheme_and_port(): void
    {
        $this->enableTeams();
        $this->teamOwning('acme.com');

        $request = Request::create('http://acme.com:8080/neev/passkeys');
        $request->headers->set('Origin', 'http://acme.com:8080');
        $this->app->instance('request', $request);
        $this->tenantResolver->clear();
        $this->tenantResolver->resolve($request);

        $this->assertContains('https://acme.com', $this->resolver->allowedOrigins());
    }

    /**
     * A security invariant, not a setting: every admitted origin is named,
     * on every relying party.
     */
    public function test_subdomain_matching_is_off_on_the_configured_relying_party(): void
    {
        $this->resolveOn('example.com');

        $this->assertFalse($this->resolver->allowSubdomains());
    }

    /**
     * What replaces subdomain matching: a tenant on a platform subdomain
     * runs under the platform relying party, and its origin is admitted
     * because its own verified row says so.
     */
    public function test_a_verified_platform_subdomain_is_an_explicit_origin(): void
    {
        $this->enableTeams();
        config(['neev.allowed_origins' => ['https://example.com']]);
        $this->teamOwning('acme.example.com');
        $this->resolveOn('acme.example.com');

        $this->assertSame('example.com', $this->resolver->rpId());
        $this->assertSame(
            ['https://example.com', 'https://acme.example.com'],
            $this->resolver->allowedOrigins()
        );
    }

    /** One tenant's verified subdomain does not admit a sibling. */
    public function test_a_sibling_platform_subdomain_is_not_an_allowed_origin(): void
    {
        $this->enableTeams();
        $this->teamOwning('acme.example.com');
        $this->resolveOn('acme.example.com');

        $this->assertNotContains('https://other.example.com', $this->resolver->allowedOrigins());
    }

    public function test_an_unverified_platform_subdomain_is_not_an_allowed_origin(): void
    {
        $this->enableTeams();
        $team = $this->teamOwning('acme.example.com');
        DomainFactory::new()->create([
            'owner_type' => $team->getContextType(),
            'owner_id' => $team->getKey(),
            'domain' => 'pending.example.com',
            'verified_at' => null,
        ]);
        $this->resolveOn('acme.example.com');

        $this->assertNotContains('https://pending.example.com', $this->resolver->allowedOrigins());
    }

    /**
     * Hosts in the platform's zone all share the configured relying party, so
     * only the one the request names is admitted — a sibling the same context
     * happens to hold is not, or a ceremony run there would complete against a
     * credential enrolled here.
     */
    public function test_only_the_platform_host_the_request_names_is_admitted(): void
    {
        $this->enableTeams();
        $team = $this->teamOwning('acme.example.com');
        $this->domainFor($team, 'eu.acme.example.com');
        $this->resolveOn('acme.example.com');

        $origins = $this->resolver->allowedOrigins();
        $this->assertContains('https://acme.example.com', $origins);
        $this->assertNotContains('https://eu.acme.example.com', $origins);
    }

    /**
     * The sharp case: platform subdomains are verified on sight, so one tenant
     * must never have another's host admitted for the shared relying party.
     */
    public function test_another_tenants_platform_host_is_never_admitted(): void
    {
        $this->enableTeams();
        $this->teamOwning('victim.example.com');
        $this->teamOwning('evil.example.com');

        $this->resolveOn('victim.example.com');

        $this->assertNotContains('https://evil.example.com', $this->resolver->allowedOrigins());
    }

    /**
     * A client calling an API elsewhere with `X-Tenant` names its own origin,
     * and that is the host admitted — not whatever platform-zone rows the
     * context holds.
     */
    public function test_platform_origins_follow_the_origin_not_the_context(): void
    {
        $this->enableTeams();
        $team = $this->teamOwning('acme.example.com');

        $this->resolveOn('api.example.com', $team, origin: 'acme.example.com');

        $this->assertContains('https://acme.example.com', $this->resolver->allowedOrigins());
    }

    /**
     * And a request naming an origin the context does not hold gets only the
     * configured list — the context's own platform host is not stood in for it.
     */
    public function test_an_origin_the_context_does_not_hold_admits_nothing(): void
    {
        $this->enableTeams();
        $team = $this->teamOwning('acme.example.com');

        $this->resolveOn('api.example.com', $team);

        $this->assertNotContains('https://acme.example.com', $this->resolver->allowedOrigins());
    }

    /**
     * With a custom domain primary the ceremony runs under it, and the
     * tenant's platform-zone host is inert — no browser there can run a
     * ceremony for `acme.com`.
     */
    public function test_a_custom_domain_primary_does_not_carry_the_platform_host(): void
    {
        $this->enableTeams();
        config(['neev.allowed_origins' => ['https://example.com']]);
        $team = TeamFactory::new()->create();
        $this->domainFor($team, 'acme.example.com');
        $this->domainFor($team, 'acme.com', primary: true);

        $this->resolveOn('acme.com');

        $this->assertSame('acme.com', $this->resolver->rpId());
        $this->assertSame(
            ['https://example.com', 'https://acme.com'],
            $this->resolver->allowedOrigins()
        );
    }

    /**
     * A platform-zone primary is unusable from `acme.com`, so the tenant's own
     * domain takes the relying party there.
     */
    public function test_a_verified_custom_domain_takes_the_relying_party_from_a_platform_primary(): void
    {
        $this->enableTeams();
        config(['neev.allowed_origins' => ['https://example.com']]);
        $team = TeamFactory::new()->create();
        $this->domainFor($team, 'acme.example.com', primary: true);
        $this->domainFor($team, 'acme.com');

        $this->resolveOn('acme.com');

        $this->assertSame('acme.com', $this->resolver->rpId());

        $this->assertSame(
            ['https://example.com', 'https://acme.com'],
            $this->resolver->allowedOrigins()
        );
    }

    // ---------------------------------------------------------------
    // Federation rows are not serving hosts
    // ---------------------------------------------------------------

    /**
     * `domains` is primarily the federation registry: `acme.com` may be there
     * only so `@acme.com` staff auto-join, with nothing served on it. Taking
     * it would throw `SecurityError` on the host the team is reached on.
     */
    public function test_a_federated_domain_does_not_displace_the_platform_relying_party(): void
    {
        $this->enableTeams();
        $team = TeamFactory::new()->create();
        $this->domainFor($team, 'acme.example.com');
        $this->domainFor($team, 'acme.com');

        $this->assertSame('example.com', $this->forHost('acme.example.com'));
    }

    /** Marking it primary does not change that. */
    public function test_a_primary_federated_domain_does_not_displace_it_either(): void
    {
        $this->enableTeams();
        $team = TeamFactory::new()->create();
        $this->domainFor($team, 'acme.example.com');
        $this->domainFor($team, 'acme.com', primary: true);

        $this->assertSame('example.com', $this->forHost('acme.example.com'));
    }

    /** The origins stay whole too: the platform host, not the federated one. */
    public function test_a_federated_domain_is_not_an_allowed_origin_on_the_platform_host(): void
    {
        $this->enableTeams();
        config(['neev.allowed_origins' => ['https://example.com']]);
        $team = TeamFactory::new()->create();
        $this->domainFor($team, 'acme.example.com');
        $this->domainFor($team, 'acme.com');

        $this->resolveOn('acme.example.com');

        $this->assertSame(
            ['https://example.com', 'https://acme.example.com'],
            $this->resolver->allowedOrigins()
        );
    }

    /** Down to the name authenticators show: nothing about the host changes. */
    public function test_a_federated_domain_leaves_the_platform_rp_name_alone(): void
    {
        $this->enableTeams();
        config(['app.name' => 'Platform']);
        $team = TeamFactory::new()->create(['name' => 'Acme Corp']);
        $this->domainFor($team, 'acme.example.com');
        $this->domainFor($team, 'acme.com');

        $this->resolveOn('acme.example.com');

        $this->assertSame('Platform', $this->resolver->rpName());
    }

    /** The other half: on the custom domain that domain takes it, unpromoted. */
    public function test_a_request_served_on_the_custom_domain_still_uses_it(): void
    {
        $this->enableTeams();
        config(['neev.allowed_origins' => ['https://example.com']]);
        $team = TeamFactory::new()->create();
        $this->domainFor($team, 'acme.example.com');
        $this->domainFor($team, 'acme.com');

        $this->resolveOn('acme.com');

        $this->assertSame('acme.com', $this->resolver->rpId());
        $this->assertSame(
            ['https://example.com', 'https://acme.com'],
            $this->resolver->allowedOrigins()
        );
    }

    /** Each verified host of a context is its own relying party. */
    public function test_a_host_under_the_custom_domain_is_its_own_relying_party(): void
    {
        $this->enableTeams();
        $team = TeamFactory::new()->create();
        $this->domainFor($team, 'acme.com');
        $this->domainFor($team, 'app.acme.com');

        $this->assertSame('acme.com', $this->forHost('acme.com'));
        $this->assertSame('app.acme.com', $this->forHost('app.acme.com'));
    }

    /** The origin decides for a header-named context too. */
    public function test_a_header_resolved_context_uses_the_browsers_origin(): void
    {
        $this->enableTeams();
        $team = TeamFactory::new()->create();
        $this->domainFor($team, 'acme.example.com');
        $this->domainFor($team, 'acme.com');

        $this->resolveOn('api.platform.test', $team, origin: 'acme.com');

        $this->assertSame('acme.com', $this->resolver->rpId());
    }

    /** And on the platform host it keeps the platform relying party. */
    public function test_a_header_resolved_context_on_the_platform_host_keeps_it(): void
    {
        $this->enableTeams();
        $team = TeamFactory::new()->create();
        $this->domainFor($team, 'acme.example.com');
        $this->domainFor($team, 'acme.com');

        $this->resolveOn('api.platform.test', $team, origin: 'acme.example.com');

        $this->assertSame('example.com', $this->resolver->rpId());
    }

    public function test_subdomain_matching_is_always_off_on_a_claimed_domain(): void
    {
        $this->enableTeams();
        $this->teamOwning('acme.com');
        $this->resolveOn('acme.com');

        $this->assertFalse($this->resolver->allowSubdomains());
    }

    // ---------------------------------------------------------------
    // usableFrom()
    // ---------------------------------------------------------------

    /**
     * The WebAuthn rule a UI uses to hide a control that would only fail.
     * Keep the leading dot: without it `evil-example.com` would pass as a
     * subdomain of `example.com`.
     */
    public function test_usable_from_follows_the_registrable_suffix_rule(): void
    {
        $this->assertTrue($this->resolver->usableFrom('acme.com', 'acme.com'));
        $this->assertTrue($this->resolver->usableFrom('acme.com', 'app.acme.com'));
        $this->assertFalse($this->resolver->usableFrom('acme.com', 'evil-acme.com'));
        $this->assertFalse($this->resolver->usableFrom('acme.com', 'otper.com'));
        $this->assertFalse($this->resolver->usableFrom('', 'acme.com'));
    }

    // ---------------------------------------------------------------
    // Lookups
    // ---------------------------------------------------------------

    /**
     * The middleware resolved the context through the row already, so a
     * ceremony on that host reads no domains at all — settled once for all
     * three readings.
     */
    public function test_a_ceremony_settles_the_claim_once(): void
    {
        $this->enableTeams();
        $this->teamOwning('acme.com');
        $this->resolveOn('acme.com');

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->resolver->rpId();
        $this->resolver->allowedOrigins();
        $this->resolver->allowSubdomains();

        $this->assertSame(0, $queries);
    }

    /**
     * Resolved by header rather than through a domain row, so the context's
     * domains are read — once, for all three readings.
     */
    public function test_a_header_resolved_ceremony_reads_the_domains_once(): void
    {
        $this->enableTeams();
        $team = $this->teamOwning('acme.com');
        $this->resolveOn('platform.test', $team, 'acme.com');

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->resolver->rpId();
        $this->resolver->allowedOrigins();
        $this->resolver->allowSubdomains();

        $this->assertSame(1, $queries);
    }

    public function test_no_context_costs_no_query(): void
    {
        $this->enableTeams();
        $this->resolveOn('example.com');

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->resolver->rpId();
        $this->resolver->allowedOrigins();
        $this->resolver->allowSubdomains();

        $this->assertSame(0, $queries);
    }
}
