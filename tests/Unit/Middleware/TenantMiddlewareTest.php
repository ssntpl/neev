<?php

namespace Ssntpl\Neev\Tests\Unit\Middleware;

use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Ssntpl\Neev\Database\Factories\DomainFactory;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Http\Middleware\TenantMiddleware;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\TenantResolver;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;
use Symfony\Component\HttpFoundation\Response;

class TenantMiddlewareTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    private TenantMiddleware $middleware;
    private TenantResolver $tenantResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantResolver = new TenantResolver();
        $this->middleware = new TenantMiddleware($this->tenantResolver);
    }

    /**
     * The "next" closure that returns a simple 200 OK JSON response.
     */
    private function passThrough(): Closure
    {
        return fn (Request $req): Response => response()->json(['message' => 'OK'], 200);
    }

    // -----------------------------------------------------------------
    // Tenant isolation disabled
    // -----------------------------------------------------------------

    public function test_passes_through_when_tenant_isolation_disabled(): void
    {
        config(['neev.tenant_isolation' => false]);

        $request = Request::create('/api/test');

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Tenant not found
    // -----------------------------------------------------------------

    public function test_returns_404_when_tenant_not_found_in_required_mode(): void
    {
        $this->enableTenantIsolation('test.com');

        $request = Request::create('http://unknown.test.com/api/test');

        $response = $this->middleware->handle($request, $this->passThrough(), 'required');

        $this->assertEquals(404, $response->getStatusCode());
        $this->assertStringContainsString('Tenant not found', $response->getContent());
    }

    public function test_returns_404_when_no_tenant_header_or_subdomain_matches_in_required_mode(): void
    {
        $this->enableTenantIsolation('test.com');

        $request = Request::create('http://example.com/api/test');
        $request->headers->set('X-Tenant', 'nonexistent-slug');

        $response = $this->middleware->handle($request, $this->passThrough(), 'required');

        $this->assertEquals(404, $response->getStatusCode());
    }

    public function test_passes_through_when_tenant_not_found_in_optional_mode(): void
    {
        $this->enableTenantIsolation('test.com');

        $request = Request::create('http://unknown.test.com/api/test');

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Domain not verified (custom domain)
    // -----------------------------------------------------------------

    public function test_returns_403_when_custom_domain_not_verified(): void
    {
        $this->enableTenantIsolation('test.com');

        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        // Mock the TenantResolver to simulate resolved but unverified domain
        $resolver = $this->createPartialMock(TenantResolver::class, ['resolve', 'isResolvedDomainVerified']);
        $resolver->method('resolve')->willReturn($team);
        $resolver->method('isResolvedDomainVerified')->willReturn(false);

        $middleware = new TenantMiddleware($resolver);

        $request = Request::create('http://custom.example.org/api/test');

        $response = $middleware->handle($request, $this->passThrough());

        $this->assertEquals(403, $response->getStatusCode());
        $this->assertStringContainsString('Domain not verified', $response->getContent());
    }

    // -----------------------------------------------------------------
    // Valid tenant: sets attribute and passes through
    // -----------------------------------------------------------------

    public function test_sets_tenant_attribute_and_passes_through_for_valid_tenant(): void
    {
        $this->enableTenantIsolation('test.com');

        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id, 'slug' => 'acme']);

        $request = Request::create('http://acme.test.com/api/test');
        $tenant = null;

        $next = function (Request $req) use (&$tenant): Response {
            $tenant = $req->attributes->get('tenant');
            return response()->json(['message' => 'OK'], 200);
        };

        $response = $this->middleware->handle($request, $next);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertNotNull($tenant);
        $this->assertEquals($team->id, $tenant->id);
    }

    // -----------------------------------------------------------------
    // Subdomain resolution
    // -----------------------------------------------------------------

    public function test_resolves_tenant_via_subdomain(): void
    {
        $this->enableTenantIsolation('test.com');

        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id, 'slug' => 'myteam']);

        $request = Request::create('http://myteam.test.com/dashboard');

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals($team->id, $this->tenantResolver->current()->id);
        $this->assertSame('subdomain', $this->tenantResolver->resolvedVia());
    }

    // -----------------------------------------------------------------
    // X-Tenant header resolution
    // -----------------------------------------------------------------

    public function test_resolves_tenant_via_x_tenant_header_with_id(): void
    {
        $this->enableTenantIsolation('test.com');

        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $request = Request::create('http://example.com/api/test');
        $request->headers->set('X-Tenant', (string) $team->id);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals($team->id, $this->tenantResolver->current()->id);
        $this->assertSame('header', $this->tenantResolver->resolvedVia());
    }

    public function test_resolves_tenant_via_x_tenant_header_with_slug(): void
    {
        $this->enableTenantIsolation('test.com');

        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id, 'slug' => 'my-team']);

        $request = Request::create('http://example.com/api/test');
        $request->headers->set('X-Tenant', 'my-team');

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals($team->id, $this->tenantResolver->current()->id);
        $this->assertSame('header', $this->tenantResolver->resolvedVia());
    }

    // -----------------------------------------------------------------
    // Custom domain resolution (verified)
    // -----------------------------------------------------------------

    public function test_resolves_tenant_via_verified_custom_domain(): void
    {
        $this->enableTenantIsolation('test.com');

        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        DomainFactory::new()->verified()->create([
            'team_id' => $team->id,
            'domain' => 'custom.example.org',
        ]);

        $request = Request::create('http://custom.example.org/dashboard');

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals($team->id, $this->tenantResolver->current()->id);
        $this->assertSame('custom', $this->tenantResolver->resolvedVia());
    }

    // -----------------------------------------------------------------
    // Unverified custom domain returns 404 (not found by findByHost)
    // -----------------------------------------------------------------

    public function test_returns_404_for_unverified_custom_domain_in_required_mode(): void
    {
        $this->enableTenantIsolation('test.com');

        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        // Domain without verified_at -- findByHost won't find it
        DomainFactory::new()->create([
            'team_id' => $team->id,
            'domain' => 'unverified.example.org',
        ]);

        $request = Request::create('http://unverified.example.org/dashboard');

        $response = $this->middleware->handle($request, $this->passThrough(), 'required');

        $this->assertEquals(404, $response->getStatusCode());
    }
}
