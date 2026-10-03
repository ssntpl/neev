<?php

namespace Ssntpl\Neev\Tests\Unit\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Ssntpl\Neev\Database\Factories\DomainFactory;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Database\Factories\TenantFactory;
use Ssntpl\Neev\Http\Middleware\TenantMiddleware;
use Ssntpl\Neev\Services\RelyingPartyResolver;
use Ssntpl\Neev\Services\TenantResolver;
use Ssntpl\Neev\Support\PlatformHost;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

/**
 * RFC 006 §4.3: a platform subdomain is derived from the slug, not stored.
 */
class PlatformHostResolutionTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'neev.platform_domain' => 'otper.com',
            'neev.relying_party_id' => 'otper.com',
        ]);
    }

    private function resolve(string $host, ?string $origin = null): TenantResolver
    {
        $request = Request::create("https://{$host}/dashboard");

        if ($origin !== null) {
            $request->headers->set('Origin', "https://{$origin}");
        }

        $this->app->instance('request', $request);

        $resolver = new TenantResolver();
        $resolver->resolve($request);

        return $resolver;
    }

    // ---------------------------------------------------------------
    // PlatformHost
    // ---------------------------------------------------------------

    public function test_a_platform_host_names_one_label_under_the_zone(): void
    {
        $this->assertSame('acme', PlatformHost::slugOf('ACME.otper.com.'));
        $this->assertSame('acme.otper.com', PlatformHost::for('acme'));
        $this->assertNull(PlatformHost::slugOf('otper.com'));
        $this->assertNull(PlatformHost::slugOf('a.acme.otper.com'));
        $this->assertNull(PlatformHost::slugOf('acme.example.com'));
    }

    public function test_no_zone_means_no_platform_host(): void
    {
        config(['neev.platform_domain' => null]);

        $this->assertNull(PlatformHost::slugOf('acme.otper.com'));
        $this->assertNull(PlatformHost::for('acme'));
    }

    // ---------------------------------------------------------------
    // Resolution from the slug
    // ---------------------------------------------------------------

    public function test_shared_mode_resolves_a_team_subdomain_with_no_row(): void
    {
        $this->enableTeams();
        $team = TeamFactory::new()->create(['slug' => 'acme']);

        $resolver = $this->resolve('acme.otper.com');

        $this->assertTrue($resolver->resolvedContext()->is($team));
        $this->assertSame('subdomain', $resolver->resolvedVia());
        $this->assertSame('acme.otper.com', $resolver->resolvedDomain());
        $this->assertTrue($resolver->isResolvedDomainVerified());
    }

    public function test_isolated_mode_resolves_a_tenant_subdomain_and_not_a_team_one(): void
    {
        $this->enableTenantIsolation();
        $tenant = TenantFactory::new()->create(['slug' => 'acme']);
        TeamFactory::new()->create(['slug' => 'sales', 'tenant_id' => $tenant->id]);

        $this->assertTrue($this->resolve('acme.otper.com')->resolvedContext()->is($tenant));
        $this->assertNull($this->resolve('sales.otper.com')->resolvedContext());
    }

    public function test_a_host_outside_the_zone_or_below_a_label_resolves_nothing_by_slug(): void
    {
        $this->enableTeams();
        TeamFactory::new()->create(['slug' => 'acme']);

        $this->assertNull($this->resolve('acme.example.com')->resolvedContext());
        $this->assertNull($this->resolve('www.acme.otper.com')->resolvedContext());
    }

    // ---------------------------------------------------------------
    // Retired hosts
    // ---------------------------------------------------------------

    public function test_a_retired_host_serves_its_last_owner_for_the_window_then_stops(): void
    {
        $this->enableTeams();
        $team = TeamFactory::new()->create(['slug' => 'acme']);
        $team->update(['slug' => 'acme-corp']);

        $resolver = $this->resolve('acme.otper.com');
        $this->assertTrue($resolver->resolvedContext()->is($team));
        $this->assertSame('retired', $resolver->resolvedVia());
        $this->assertTrue($resolver->isResolvedDomainVerified());

        $this->travel(91)->days();
        cache()->flush();

        $this->assertNull($this->resolve('acme.otper.com')->resolvedContext());
        $this->assertTrue($this->resolve('acme-corp.otper.com')->resolvedContext()->is($team));
    }

    public function test_a_platform_host_no_slug_answers_falls_back_to_a_domain_row(): void
    {
        $this->enableTeams();
        $team = TeamFactory::new()->create(['slug' => 'acme']);
        DomainFactory::new()->verified()->create([
            'owner_type' => 'team', 'owner_id' => $team->id, 'domain' => 'legacy.otper.com',
        ]);

        $resolver = $this->resolve('legacy.otper.com');

        $this->assertTrue($resolver->resolvedContext()->is($team));
        $this->assertSame('custom', $resolver->resolvedVia());
    }

    // ---------------------------------------------------------------
    // X-Tenant header
    // ---------------------------------------------------------------

    private function resolveHeader(string $value): TenantResolver
    {
        $request = Request::create('https://api.otper.com/dashboard');
        $request->headers->set('X-Tenant', $value);

        $resolver = new TenantResolver();
        $resolver->resolve($request);

        return $resolver;
    }

    public function test_a_retired_slug_in_the_header_resolves_within_the_window_then_stops(): void
    {
        $this->enableTeams();
        $team = TeamFactory::new()->create(['slug' => 'acme']);
        $team->update(['slug' => 'acme-corp']);

        $resolver = $this->resolveHeader('acme');
        $this->assertTrue($resolver->resolvedContext()->is($team));
        $this->assertSame('header', $resolver->resolvedVia());

        $this->travel(91)->days();

        $this->assertNull($this->resolveHeader('acme')->resolvedContext());
        $this->assertTrue($this->resolveHeader('acme-corp')->resolvedContext()->is($team));
    }

    public function test_a_retired_slug_in_the_header_is_answered_with_the_current_one(): void
    {
        $this->enableTeams();
        $this->probeRoute();
        TeamFactory::new()->create(['slug' => 'acme'])->update(['slug' => 'acme-corp']);

        $this->get('https://api.otper.com/probe', ['X-Tenant' => 'acme'])
            ->assertOk()
            ->assertSee('served')
            ->assertHeader('X-Tenant-Slug', 'acme-corp');

        $this->get('https://api.otper.com/probe', ['X-Tenant' => 'acme-corp'])
            ->assertOk()
            ->assertHeaderMissing('X-Tenant-Slug');
    }

    public function test_isolated_mode_resolves_a_retired_tenant_slug_in_the_header(): void
    {
        $this->enableTenantIsolation();
        $tenant = TenantFactory::new()->create(['slug' => 'acme']);
        $tenant->update(['slug' => 'acme-corp']);

        $this->assertTrue($this->resolveHeader('acme')->resolvedContext()->is($tenant));
    }

    public function test_a_window_of_zero_days_serves_no_retired_host(): void
    {
        config(['neev.slug.retired_host_days' => 0]);
        $this->enableTeams();
        TeamFactory::new()->create(['slug' => 'acme'])->update(['slug' => 'acme-corp']);

        $this->assertNull($this->resolve('acme.otper.com')->resolvedContext());
    }

    public function test_a_rename_moves_both_hosts_at_once(): void
    {
        $this->enableTeams();
        $team = TeamFactory::new()->create(['slug' => 'acme']);
        $this->assertSame('subdomain', $this->resolve('acme.otper.com')->resolvedVia());

        $team->update(['slug' => 'acme-corp']);

        $this->assertSame('retired', $this->resolve('acme.otper.com')->resolvedVia());
        $this->assertSame('subdomain', $this->resolve('acme-corp.otper.com')->resolvedVia());

        // Taking the old slug back makes its host current again.
        $team->update(['slug' => 'acme']);

        $this->assertSame('subdomain', $this->resolve('acme.otper.com')->resolvedVia());
        $this->assertSame('retired', $this->resolve('acme-corp.otper.com')->resolvedVia());
    }

    // ---------------------------------------------------------------
    // Redirect middleware
    // ---------------------------------------------------------------

    private function probeRoute(): void
    {
        Route::middleware(TenantMiddleware::class)
            ->any('/probe', fn () => 'served');
    }

    public function test_a_retired_host_redirects_to_the_current_one_during_the_window(): void
    {
        $this->enableTeams();
        $this->probeRoute();
        TeamFactory::new()->create(['slug' => 'acme'])->update(['slug' => 'acme-corp']);

        $this->get('https://acme.otper.com/probe?tab=1')
            ->assertStatus(301)
            ->assertRedirect('https://acme-corp.otper.com/probe?tab=1');

        $this->head('https://acme.otper.com/probe')
            ->assertStatus(301)
            ->assertRedirect('https://acme-corp.otper.com/probe');

        $this->get('https://acme-corp.otper.com/probe')->assertOk()->assertSee('served');

        $this->travel(91)->days();
        cache()->flush();

        $this->get('https://acme.otper.com/probe')->assertOk()->assertSee('served');
    }

    /**
     * Clients drop Authorization when a redirect changes host, so an API call
     * is served in place and told the current slug instead.
     */
    public function test_an_api_call_on_a_retired_host_is_served_with_the_current_slug(): void
    {
        $this->enableTeams();
        $this->probeRoute();
        TeamFactory::new()->create(['slug' => 'acme'])->update(['slug' => 'acme-corp']);

        $this->getJson('https://acme.otper.com/probe')
            ->assertOk()
            ->assertHeader('X-Tenant-Slug', 'acme-corp');

        $this->post('https://acme.otper.com/probe')
            ->assertOk()
            ->assertHeader('X-Tenant-Slug', 'acme-corp');
    }

    public function test_a_retired_host_named_in_the_header_does_not_redirect_another_host(): void
    {
        $this->enableTeams();
        $this->probeRoute();
        TeamFactory::new()->create(['slug' => 'acme'])->update(['slug' => 'acme-corp']);

        $this->get('https://api.otper.com/probe', ['X-Tenant' => 'acme.otper.com'])
            ->assertOk()
            ->assertSee('served')
            ->assertHeader('X-Tenant-Slug', 'acme-corp');
    }

    // ---------------------------------------------------------------
    // Passkeys
    // ---------------------------------------------------------------

    public function test_a_platform_host_is_its_own_relying_party_with_no_row(): void
    {
        $this->enableTeams();
        TeamFactory::new()->create(['slug' => 'acme', 'name' => 'Acme']);

        $rp = new RelyingPartyResolver($this->resolve('acme.otper.com', 'acme.otper.com'));

        $this->assertSame('acme.otper.com', $rp->rpId());
        $this->assertSame('Acme', $rp->rpName());
        $this->assertContains('https://acme.otper.com', $rp->allowedOrigins());
    }

    public function test_a_retired_host_is_not_a_relying_party(): void
    {
        $this->enableTeams();
        TeamFactory::new()->create(['slug' => 'acme'])->update(['slug' => 'acme-corp']);

        $rp = new RelyingPartyResolver($this->resolve('acme.otper.com', 'acme.otper.com'));

        $this->assertSame('otper.com', $rp->rpId());
    }

    public function test_another_platform_origin_falls_back_to_the_configured_relying_party(): void
    {
        $this->enableTeams();
        TeamFactory::new()->create(['slug' => 'acme']);
        TeamFactory::new()->create(['slug' => 'evil']);

        $rp = new RelyingPartyResolver($this->resolve('acme.otper.com', 'evil.otper.com'));

        $this->assertSame('otper.com', $rp->rpId());
        $this->assertNotContains('https://evil.otper.com', $rp->allowedOrigins());
    }
}
