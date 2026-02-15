<?php

namespace Ssntpl\Neev\Tests\Unit\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Ssntpl\Neev\Database\Factories\DomainFactory;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Services\TenantResolver;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class TenantResolverTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    private TenantResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new TenantResolver();
    }

    // ---------------------------------------------------------------
    // resolve() -- disabled tenant isolation
    // ---------------------------------------------------------------

    public function test_resolve_returns_null_when_tenant_isolation_disabled(): void
    {
        config(['neev.tenant_isolation' => false]);

        $team = TeamFactory::new()->create(['slug' => 'acme']);

        $request = Request::create('http://acme.test.com/dashboard');
        $request->headers->set('X-Tenant', (string) $team->id);

        $this->assertNull($this->resolver->resolve($request));
    }

    // ---------------------------------------------------------------
    // resolve() -- X-Tenant header
    // ---------------------------------------------------------------

    public function test_resolve_from_header_with_team_id(): void
    {
        $this->enableTenantIsolation();

        $team = TeamFactory::new()->create();

        $request = Request::create('http://example.com/api');
        $request->headers->set('X-Tenant', (string) $team->id);

        $resolved = $this->resolver->resolve($request);

        $this->assertNotNull($resolved);
        $this->assertEquals($team->id, $resolved->id);
        $this->assertSame('header', $this->resolver->resolvedVia());
    }

    public function test_resolve_from_header_with_slug(): void
    {
        $this->enableTenantIsolation();

        $team = TeamFactory::new()->create(['slug' => 'my-team']);

        $request = Request::create('http://example.com/api');
        $request->headers->set('X-Tenant', 'my-team');

        $resolved = $this->resolver->resolve($request);

        $this->assertNotNull($resolved);
        $this->assertEquals($team->id, $resolved->id);
        $this->assertSame('header', $this->resolver->resolvedVia());
    }

    public function test_resolve_from_header_with_unknown_value_returns_null(): void
    {
        $this->enableTenantIsolation();

        $request = Request::create('http://example.com/api');
        $request->headers->set('X-Tenant', 'nonexistent-slug');

        $this->assertNull($this->resolver->resolve($request));
    }

    public function test_resolve_from_header_with_empty_value_falls_through(): void
    {
        $this->enableTenantIsolation();

        $request = Request::create('http://example.com/api');
        $request->headers->set('X-Tenant', '   ');

        $this->assertNull($this->resolver->resolve($request));
    }

    // ---------------------------------------------------------------
    // resolve() -- subdomain
    // ---------------------------------------------------------------

    public function test_resolve_from_subdomain(): void
    {
        $this->enableTenantIsolation('test.com');

        $team = TeamFactory::new()->create(['slug' => 'myteam']);

        $request = Request::create('http://myteam.test.com/dashboard');

        $resolved = $this->resolver->resolve($request);

        $this->assertNotNull($resolved);
        $this->assertEquals($team->id, $resolved->id);
        $this->assertSame('subdomain', $this->resolver->resolvedVia());
        $this->assertSame('myteam.test.com', $this->resolver->resolvedDomain());
    }

    public function test_resolve_from_subdomain_returns_null_for_unknown_slug(): void
    {
        $this->enableTenantIsolation('test.com');

        $request = Request::create('http://unknown.test.com/dashboard');

        $this->assertNull($this->resolver->resolve($request));
    }

    public function test_resolve_from_subdomain_ignores_non_matching_suffix(): void
    {
        $this->enableTenantIsolation('test.com');

        TeamFactory::new()->create(['slug' => 'myteam']);

        // Host does not end with .test.com
        $request = Request::create('http://myteam.other.com/dashboard');

        $this->assertNull($this->resolver->resolve($request));
    }

    // ---------------------------------------------------------------
    // resolve() -- custom domain
    // ---------------------------------------------------------------

    public function test_resolve_from_custom_domain(): void
    {
        $this->enableTenantIsolation('test.com');

        $team = TeamFactory::new()->create();
        DomainFactory::new()->verified()->create([
            'team_id' => $team->id,
            'domain' => 'custom.example.org',
        ]);

        $request = Request::create('http://custom.example.org/dashboard');

        $resolved = $this->resolver->resolve($request);

        $this->assertNotNull($resolved);
        $this->assertEquals($team->id, $resolved->id);
        $this->assertSame('custom', $this->resolver->resolvedVia());
        $this->assertSame('custom.example.org', $this->resolver->resolvedDomain());
    }

    public function test_resolve_does_not_match_unverified_custom_domain(): void
    {
        $this->enableTenantIsolation('test.com');

        $team = TeamFactory::new()->create();
        // Domain without verified_at (unverified)
        DomainFactory::new()->create([
            'team_id' => $team->id,
            'domain' => 'unverified.example.org',
        ]);

        $request = Request::create('http://unverified.example.org/dashboard');

        $this->assertNull($this->resolver->resolve($request));
    }

    // ---------------------------------------------------------------
    // resolve() -- priority
    // ---------------------------------------------------------------

    public function test_header_takes_precedence_over_subdomain(): void
    {
        $this->enableTenantIsolation('test.com');

        $teamA = TeamFactory::new()->create(['slug' => 'team-a']);
        $teamB = TeamFactory::new()->create(['slug' => 'team-b']);

        // Request has subdomain for team-b but header for team-a
        $request = Request::create('http://team-b.test.com/dashboard');
        $request->headers->set('X-Tenant', (string) $teamA->id);

        $resolved = $this->resolver->resolve($request);

        $this->assertNotNull($resolved);
        $this->assertEquals($teamA->id, $resolved->id);
        $this->assertSame('header', $this->resolver->resolvedVia());
    }

    // ---------------------------------------------------------------
    // isResolvedDomainVerified()
    // ---------------------------------------------------------------

    public function test_is_resolved_domain_verified_returns_true_for_header(): void
    {
        $this->enableTenantIsolation();

        $team = TeamFactory::new()->create();

        $request = Request::create('http://example.com/api');
        $request->headers->set('X-Tenant', (string) $team->id);

        $this->resolver->resolve($request);

        $this->assertTrue($this->resolver->isResolvedDomainVerified());
    }

    public function test_is_resolved_domain_verified_returns_true_for_subdomain(): void
    {
        $this->enableTenantIsolation('test.com');

        $team = TeamFactory::new()->create(['slug' => 'acme']);

        $request = Request::create('http://acme.test.com/dashboard');
        $this->resolver->resolve($request);

        $this->assertTrue($this->resolver->isResolvedDomainVerified());
    }

    public function test_is_resolved_domain_verified_returns_true_for_verified_custom_domain(): void
    {
        $this->enableTenantIsolation('test.com');

        $team = TeamFactory::new()->create();
        DomainFactory::new()->verified()->create([
            'team_id' => $team->id,
            'domain' => 'verified.example.org',
        ]);

        $request = Request::create('http://verified.example.org/dashboard');
        $this->resolver->resolve($request);

        $this->assertTrue($this->resolver->isResolvedDomainVerified());
    }

    public function test_is_resolved_domain_verified_returns_false_when_not_resolved(): void
    {
        // No resolution has happened
        $this->assertFalse($this->resolver->isResolvedDomainVerified());
    }

    // ---------------------------------------------------------------
    // State management: setCurrentTenant, hasTenant, clear, currentId
    // ---------------------------------------------------------------

    public function test_set_current_tenant_sets_the_tenant(): void
    {
        $team = TeamFactory::new()->create();

        $this->resolver->setCurrentTenant($team);

        $this->assertSame($team->id, $this->resolver->current()->id);
    }

    public function test_has_tenant_returns_false_initially(): void
    {
        $this->assertFalse($this->resolver->hasTenant());
    }

    public function test_has_tenant_returns_true_after_setting_tenant(): void
    {
        $team = TeamFactory::new()->create();

        $this->resolver->setCurrentTenant($team);

        $this->assertTrue($this->resolver->hasTenant());
    }

    public function test_current_id_returns_null_initially(): void
    {
        $this->assertNull($this->resolver->currentId());
    }

    public function test_current_id_returns_team_id_after_setting_tenant(): void
    {
        $team = TeamFactory::new()->create();

        $this->resolver->setCurrentTenant($team);

        $this->assertSame($team->id, $this->resolver->currentId());
    }

    public function test_clear_resets_all_state(): void
    {
        $this->enableTenantIsolation('test.com');

        $team = TeamFactory::new()->create(['slug' => 'acme']);

        $request = Request::create('http://acme.test.com/dashboard');
        $this->resolver->resolve($request);

        $this->assertTrue($this->resolver->hasTenant());

        $this->resolver->clear();

        $this->assertFalse($this->resolver->hasTenant());
        $this->assertNull($this->resolver->current());
        $this->assertNull($this->resolver->currentId());
        $this->assertNull($this->resolver->resolvedVia());
        $this->assertNull($this->resolver->resolvedDomain());
    }

    // ---------------------------------------------------------------
    // isEnabled() and singleTenantUsers()
    // ---------------------------------------------------------------

    public function test_is_enabled_returns_false_by_default(): void
    {
        config(['neev.tenant_isolation' => false]);

        $this->assertFalse($this->resolver->isEnabled());
    }

    public function test_is_enabled_returns_true_when_enabled(): void
    {
        $this->enableTenantIsolation();

        $this->assertTrue($this->resolver->isEnabled());
    }

    public function test_single_tenant_users_returns_false_by_default(): void
    {
        $this->assertFalse($this->resolver->singleTenantUsers());
    }

    public function test_single_tenant_users_returns_true_when_configured(): void
    {
        config(['neev.tenant_isolation_options.single_tenant_users' => true]);

        $this->assertTrue($this->resolver->singleTenantUsers());
    }
}
