<?php

namespace Ssntpl\Neev\Tests\Unit\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Ssntpl\Neev\Database\Factories\DomainFactory;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Database\Factories\TenantFactory;
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
        config(['neev.tenant' => false]);

        $tenant = TenantFactory::new()->create(['slug' => 'acme']);

        $request = Request::create('http://acme.test.com/dashboard');
        $request->headers->set('X-Tenant', (string) $tenant->id);

        $this->assertNull($this->resolver->resolve($request));
    }

    // ---------------------------------------------------------------
    // resolve() -- X-Tenant header
    // ---------------------------------------------------------------

    public function test_resolve_from_header_with_tenant_id(): void
    {
        $this->enableTenantIsolation();

        $tenant = TenantFactory::new()->create();

        $request = Request::create('http://example.com/api');
        $request->headers->set('X-Tenant', (string) $tenant->id);

        $resolved = $this->resolver->resolve($request);

        $this->assertNotNull($resolved);
        $this->assertEquals($tenant->id, $resolved->getContextId());
        $this->assertSame('header', $this->resolver->resolvedVia());
    }

    public function test_resolve_from_header_with_slug(): void
    {
        $this->enableTenantIsolation();

        $tenant = TenantFactory::new()->create(['slug' => 'my-tenant']);

        $request = Request::create('http://example.com/api');
        $request->headers->set('X-Tenant', 'my-tenant');

        $resolved = $this->resolver->resolve($request);

        $this->assertNotNull($resolved);
        $this->assertEquals($tenant->id, $resolved->getContextId());
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
    // resolve() -- domain lookup (all host resolution goes through
    //              domain table)
    // ---------------------------------------------------------------

    public function test_resolve_from_host_with_verified_domain(): void
    {
        $this->enableTenantIsolation();

        $tenant = TenantFactory::new()->create(['slug' => 'myteam']);
        DomainFactory::new()->verified()->create([
            'owner_type' => 'tenant', 'owner_id' => $tenant->id,
            'domain' => 'myteam.test.com',
        ]);

        $request = Request::create('http://myteam.test.com/dashboard');

        $resolved = $this->resolver->resolve($request);

        $this->assertNotNull($resolved);
        $this->assertEquals($tenant->id, $resolved->getContextId());
        $this->assertSame('custom', $this->resolver->resolvedVia());
        $this->assertSame('myteam.test.com', $this->resolver->resolvedDomain());
    }

    public function test_resolve_from_host_returns_null_for_unknown_domain(): void
    {
        $this->enableTenantIsolation();

        $request = Request::create('http://unknown.test.com/dashboard');

        $this->assertNull($this->resolver->resolve($request));
    }

    public function test_resolve_from_host_returns_null_for_unregistered_domain(): void
    {
        $this->enableTenantIsolation();

        TenantFactory::new()->create(['slug' => 'myteam']);

        // Host is not registered in domains table
        $request = Request::create('http://myteam.other.com/dashboard');

        $this->assertNull($this->resolver->resolve($request));
    }

    // ---------------------------------------------------------------
    // resolve() -- custom domain
    // ---------------------------------------------------------------

    public function test_resolve_from_custom_domain(): void
    {
        $this->enableTenantIsolation();

        $tenant = TenantFactory::new()->create();
        DomainFactory::new()->verified()->create([
            'owner_type' => 'tenant', 'owner_id' => $tenant->id,
            'domain' => 'custom.example.org',
        ]);

        $request = Request::create('http://custom.example.org/dashboard');

        $resolved = $this->resolver->resolve($request);

        $this->assertNotNull($resolved);
        $this->assertEquals($tenant->id, $resolved->getContextId());
        $this->assertSame('custom', $this->resolver->resolvedVia());
        $this->assertSame('custom.example.org', $this->resolver->resolvedDomain());
    }

    public function test_resolve_does_not_match_unverified_custom_domain(): void
    {
        $this->enableTenantIsolation();

        $tenant = TenantFactory::new()->create();
        // Domain without verified_at (unverified)
        DomainFactory::new()->create([
            'owner_type' => 'tenant', 'owner_id' => $tenant->id,
            'domain' => 'unverified.example.org',
        ]);

        $request = Request::create('http://unverified.example.org/dashboard');

        $this->assertNull($this->resolver->resolve($request));
    }

    // ---------------------------------------------------------------
    // resolve() -- priority
    // ---------------------------------------------------------------

    public function test_header_takes_precedence_over_domain(): void
    {
        $this->enableTenantIsolation();

        $tenantA = TenantFactory::new()->create(['slug' => 'tenant-a']);
        $tenantB = TenantFactory::new()->create(['slug' => 'tenant-b']);

        DomainFactory::new()->verified()->create([
            'owner_type' => 'tenant', 'owner_id' => $tenantB->id,
            'domain' => 'tenant-b.test.com',
        ]);

        // Request has domain for tenant-b but header for tenant-a
        $request = Request::create('http://tenant-b.test.com/dashboard');
        $request->headers->set('X-Tenant', (string) $tenantA->id);

        $resolved = $this->resolver->resolve($request);

        $this->assertNotNull($resolved);
        $this->assertEquals($tenantA->id, $resolved->getContextId());
        $this->assertSame('header', $this->resolver->resolvedVia());
    }

    // ---------------------------------------------------------------
    // isResolvedDomainVerified()
    // ---------------------------------------------------------------

    public function test_is_resolved_domain_verified_returns_true_for_header(): void
    {
        $this->enableTenantIsolation();

        $tenant = TenantFactory::new()->create();

        $request = Request::create('http://example.com/api');
        $request->headers->set('X-Tenant', (string) $tenant->id);

        $this->resolver->resolve($request);

        $this->assertTrue($this->resolver->isResolvedDomainVerified());
    }

    public function test_is_resolved_domain_verified_returns_true_for_verified_domain(): void
    {
        $this->enableTenantIsolation();

        $tenant = TenantFactory::new()->create(['slug' => 'acme']);
        DomainFactory::new()->verified()->create([
            'owner_type' => 'tenant', 'owner_id' => $tenant->id,
            'domain' => 'acme.test.com',
        ]);

        $request = Request::create('http://acme.test.com/dashboard');
        $this->resolver->resolve($request);

        $this->assertTrue($this->resolver->isResolvedDomainVerified());
    }

    public function test_is_resolved_domain_verified_returns_true_for_verified_custom_domain(): void
    {
        $this->enableTenantIsolation();

        $tenant = TenantFactory::new()->create();
        DomainFactory::new()->verified()->create([
            'owner_type' => 'tenant', 'owner_id' => $tenant->id,
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
        $this->enableTenantIsolation();

        $tenant = TenantFactory::new()->create(['slug' => 'acme']);

        $request = Request::create('http://example.com/api');
        $request->headers->set('X-Tenant', (string) $tenant->id);
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
        config(['neev.tenant' => false]);

        $this->assertFalse($this->resolver->isEnabled());
    }

    public function test_is_enabled_returns_true_when_enabled(): void
    {
        $this->enableTenantIsolation();

        $this->assertTrue($this->resolver->isEnabled());
    }

    // ---------------------------------------------------------------
    // runInContext()
    // ---------------------------------------------------------------

    public function test_run_in_context_sets_tenant_for_callback(): void
    {
        $this->enableTenantIsolation();

        $tenant = TenantFactory::new()->create();

        $this->assertFalse($this->resolver->hasTenant());

        $result = $this->resolver->runInContext($tenant, function () {
            return $this->resolver->currentId();
        });

        $this->assertSame($tenant->id, $result);
    }

    public function test_run_in_context_restores_previous_state(): void
    {
        $this->enableTenantIsolation();

        $tenantA = TenantFactory::new()->create();
        $tenantB = TenantFactory::new()->create();

        // Resolve tenant A first
        $request = Request::create('http://example.com/api');
        $request->headers->set('X-Tenant', (string) $tenantA->id);
        $this->resolver->resolve($request);

        $this->assertSame($tenantA->id, $this->resolver->currentId());
        $this->assertSame('header', $this->resolver->resolvedVia());

        // Run in context of tenant B
        $this->resolver->runInContext($tenantB, function () use ($tenantB) {
            $this->assertSame($tenantB->id, $this->resolver->currentId());
        });

        // Should restore tenant A
        $this->assertSame($tenantA->id, $this->resolver->currentId());
        $this->assertSame('header', $this->resolver->resolvedVia());
    }

    public function test_run_in_context_restores_state_on_exception(): void
    {
        $this->enableTenantIsolation();

        $tenant = TenantFactory::new()->create();

        $this->assertFalse($this->resolver->hasTenant());

        try {
            $this->resolver->runInContext($tenant, function () {
                throw new \RuntimeException('test');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertFalse($this->resolver->hasTenant());
    }

    public function test_run_in_context_restores_null_state(): void
    {
        $this->enableTenantIsolation();

        $tenant = TenantFactory::new()->create();

        // No context initially
        $this->assertNull($this->resolver->currentId());

        $this->resolver->runInContext($tenant, function () use ($tenant) {
            $this->assertSame($tenant->id, $this->resolver->currentId());
        });

        // Back to no context
        $this->assertNull($this->resolver->currentId());
        $this->assertFalse($this->resolver->hasTenant());
    }
}
