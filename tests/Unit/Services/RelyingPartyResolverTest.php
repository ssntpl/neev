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
     * passkey route runs. `$tenant` stands in for the `X-Tenant` header a
     * client on its own domain sends to an API elsewhere.
     */
    private function resolveOn(string $host, ?object $tenant = null): void
    {
        $request = Request::create("https://{$host}/neev/passkeys");

        if ($tenant !== null) {
            $request->headers->set('X-Tenant', (string) $tenant->getKey());
        }

        $this->tenantResolver->clear();
        $this->tenantResolver->resolve($request);
    }

    private function forHost(string $host): string
    {
        $this->resolveOn($host);

        return $this->resolver->rpId();
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
     * The whole rule: the primary domain, whichever of a context's domains
     * the request happens to have arrived on.
     */
    public function test_the_primary_domain_wins(): void
    {
        $this->enableTeams();
        $team = $this->teamOwning('acme.com');
        $this->domainFor($team, 'acme.io', primary: true);

        $this->assertSame('acme.io', $this->forHost('acme.com'));
        $this->assertSame('acme.io', $this->forHost('acme.io'));
    }

    /**
     * With nothing marked primary the context's first verified domain stands
     * in, so a tenant that never chose one still gets a relying party.
     */
    public function test_the_first_verified_domain_stands_in_for_an_unset_primary(): void
    {
        $this->enableTeams();
        $team = $this->teamOwning('acme.com');
        $this->domainFor($team, 'acme.io');

        $this->assertSame('acme.com', $this->forHost('acme.io'));
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
     * However the context was resolved — here the X-Tenant header a client on
     * its own domain sends to an API elsewhere — its domain is the relying
     * party. The API's own host plays no part in it.
     */
    public function test_a_header_resolved_context_offers_its_domain(): void
    {
        $this->enableTeams();
        $team = $this->teamOwning('acme.com');
        config(['neev.allowed_origins' => ['https://example.com']]);

        $this->resolveOn('api.platform.test', $team);

        $this->assertSame('acme.com', $this->resolver->rpId());
        $this->assertSame(['https://example.com', 'https://acme.com'], $this->resolver->allowedOrigins());
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
     * The origin is built from the domain record, not from the request — so a
     * request arriving over http, or on an odd port, cannot widen what the
     * ceremony will accept.
     */
    public function test_a_claimed_origin_ignores_the_requests_scheme_and_port(): void
    {
        $this->enableTeams();
        $this->teamOwning('acme.com');

        $request = Request::create('http://acme.com:8080/neev/passkeys');
        $this->tenantResolver->clear();
        $this->tenantResolver->resolve($request);

        $this->assertContains('https://acme.com', $this->resolver->allowedOrigins());
    }

    public function test_subdomain_matching_follows_the_config_on_the_configured_domain(): void
    {
        config(['neev.allow_origin_subdomains' => true]);
        $this->resolveOn('example.com');

        $this->assertTrue($this->resolver->allowSubdomains());
    }

    public function test_subdomain_matching_is_off_on_a_claimed_domain(): void
    {
        $this->enableTeams();
        config(['neev.allow_origin_subdomains' => true]);
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
     * The middleware resolved the context already, so a ceremony costs the
     * one query that reads its domains — asked once for all three readings.
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
