<?php

namespace Ssntpl\Neev\Tests\Feature\Tenants;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Ssntpl\Neev\Database\Factories\EmailDomainFactory;
use Ssntpl\Neev\Database\Factories\HostnameFactory;
use Ssntpl\Neev\Database\Factories\TenantFactory;
use Ssntpl\Neev\Models\EmailDomain;
use Ssntpl\Neev\Models\Hostname;
use Ssntpl\Neev\Models\Tenant;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\Support\FakeDns;
use Ssntpl\Neev\Tests\TestCase;

// Must load before any test calls verify(); see the file for why.
require_once __DIR__ . '/../../Support/FakeDns.php';

/**
 * A tenant's own hosts and email domains over the API. Neev only scopes them to
 * the tenant the request resolved to; which members may manage them is the
 * application's own middleware's to decide.
 */
class TenantDomainsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Routes are registered while the providers boot, before setUp().
        $app['config']->set('neev.tenant', true);
    }

    protected function tearDown(): void
    {
        FakeDns::reset();

        parent::tearDown();
    }

    /**
     * @return array{Tenant, string}
     */
    protected function tenantMember(string $slug = 'acme'): array
    {
        $tenant = TenantFactory::new()->create(['slug' => $slug]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        // A login token belongs to the tenant it was issued in; signing in
        // there would set it, but no tenant resolved while the test made it.
        $token = $user->createLoginToken(60);
        $token->accessToken->forceFill(['tenant_id' => $tenant->id])->save();

        return [$tenant, $token->plainTextToken];
    }

    protected function asTenant(string $method, string $uri, Tenant $tenant, string $token, array $data = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $token, 'X-Tenant' => $tenant->slug])
            ->json($method, $uri, $data);
    }

    /**
     * With teams off, the tenant routes and the per-row routes are registered
     * and the team ones are not.
     */
    public function test_tenant_isolation_registers_the_tenant_and_per_row_routes(): void
    {
        $this->assertFalse((bool) config('neev.team'));

        $routes = [];
        foreach (Route::getRoutes() as $route) {
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $routes[] = $method . ' ' . $route->uri();
            }
        }

        foreach ([
            'GET neev/tenant/hostnames', 'POST neev/tenant/hostnames',
            'GET neev/tenant/email-domains', 'POST neev/tenant/email-domains',
            'POST neev/hostnames/{hostname}/verify', 'PATCH neev/email-domains/{emailDomain}',
        ] as $route) {
            $this->assertContains($route, $routes);
        }
        $this->assertNotContains('GET neev/teams/{team}/hostnames', $routes);
        $this->assertNotContains('GET neev/teams/{team}/email-domains', $routes);
    }

    // -----------------------------------------------------------------
    // Hosts
    // -----------------------------------------------------------------

    public function test_a_member_adds_lists_verifies_and_makes_a_host_primary(): void
    {
        [$tenant, $token] = $this->tenantMember();

        $record = $this->asTenant('POST', '/neev/tenant/hostnames', $tenant, $token, ['host' => 'App.Acme.com'])
            ->assertCreated()
            ->assertJsonPath('message', 'Host added.')
            ->assertJsonPath('dns_record.name', '_neev-host.app.acme.com')
            ->json('dns_record.value');

        $hostname = Hostname::forHost('app.acme.com')->sole();
        $this->assertTrue($hostname->isOwnedBy($tenant));

        $this->asTenant('GET', '/neev/tenant/hostnames', $tenant, $token)
            ->assertOk()
            ->assertJsonPath('data.0.host', 'app.acme.com')
            ->assertJsonPath('primary_hostname_id', null);

        FakeDns::txt('_neev-host.app.acme.com', $record);

        $this->asTenant('POST', "/neev/hostnames/{$hostname->id}/verify", $tenant, $token)
            ->assertOk()
            ->assertJsonPath('message', 'Host verified.');

        $this->asTenant('POST', "/neev/hostnames/{$hostname->id}/primary", $tenant, $token)
            ->assertOk();

        $this->assertSame($hostname->id, $tenant->fresh()->primary_hostname_id);
    }

    public function test_a_host_the_tenant_already_has_is_refused(): void
    {
        [$tenant, $token] = $this->tenantMember();
        $tenant->claimHost('app.acme.com');

        $this->asTenant('POST', '/neev/tenant/hostnames', $tenant, $token, ['host' => 'app.acme.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['host' => 'This tenant has already added this host.']);
    }

    public function test_a_member_issues_a_token_and_deletes_a_host(): void
    {
        [$tenant, $token] = $this->tenantMember();
        $hostname = $tenant->claimHost('app.acme.com');

        $this->asTenant('POST', "/neev/hostnames/{$hostname->id}/token", $tenant, $token)
            ->assertOk()
            ->assertJsonPath('message', 'Verification token issued.');

        $this->asTenant('GET', "/neev/hostnames/{$hostname->id}", $tenant, $token)
            ->assertOk()
            ->assertJsonPath('data.id', $hostname->id);

        $this->asTenant('DELETE', "/neev/hostnames/{$hostname->id}", $tenant, $token)
            ->assertOk();

        $this->assertNull($hostname->fresh());
    }

    // -----------------------------------------------------------------
    // Email domains
    // -----------------------------------------------------------------

    public function test_a_member_adds_lists_verifies_and_enforces_an_email_domain(): void
    {
        [$tenant, $token] = $this->tenantMember();

        $record = $this->asTenant('POST', '/neev/tenant/email-domains', $tenant, $token, ['domain' => 'acme.com'])
            ->assertCreated()
            ->assertJsonPath('message', 'Email domain added.')
            ->assertJsonPath('dns_record.name', '_neev-email.acme.com')
            ->json('dns_record.value');

        $domain = EmailDomain::forHost('acme.com')->sole();
        $this->assertSame($tenant->getMorphClass(), $domain->owner_type);
        $this->assertSame($tenant->id, (int) $domain->owner_id);

        $this->asTenant('GET', '/neev/tenant/email-domains', $tenant, $token)
            ->assertOk()
            ->assertJsonPath('data.0.domain', 'acme.com');

        FakeDns::txt('_neev-email.acme.com', $record);

        $this->asTenant('POST', "/neev/email-domains/{$domain->id}/verify", $tenant, $token)
            ->assertOk()
            ->assertJsonPath('message', 'Email domain verified.');

        $this->asTenant('PATCH', "/neev/email-domains/{$domain->id}", $tenant, $token, ['enforce' => true])
            ->assertOk();

        $this->assertTrue($domain->fresh()->enforce);

        $this->asTenant('DELETE', "/neev/email-domains/{$domain->id}", $tenant, $token)
            ->assertOk();

        $this->assertNull($domain->fresh());
    }

    // -----------------------------------------------------------------
    // Scope: only the tenant the request resolved to
    // -----------------------------------------------------------------

    public function test_another_tenants_rows_are_out_of_reach(): void
    {
        [$tenant, $token] = $this->tenantMember();
        $other = TenantFactory::new()->create(['slug' => 'globex']);
        $host = HostnameFactory::new()->forOwner($other)->create(['host' => 'app.globex.com']);
        $domain = EmailDomainFactory::new()->forOwner($other)->create(['domain' => 'globex.com']);

        $this->asTenant('GET', "/neev/hostnames/{$host->id}", $tenant, $token)->assertForbidden();
        $this->asTenant('POST', "/neev/hostnames/{$host->id}/token", $tenant, $token)->assertForbidden();
        $this->asTenant('DELETE', "/neev/hostnames/{$host->id}", $tenant, $token)->assertForbidden();
        $this->asTenant('PATCH', "/neev/email-domains/{$domain->id}", $tenant, $token, ['enforce' => true])->assertForbidden();
        $this->asTenant('DELETE', "/neev/email-domains/{$domain->id}", $tenant, $token)->assertForbidden();

        $this->assertNotNull($host->fresh());
        $this->assertFalse($domain->fresh()->enforce);
    }

    public function test_the_tenant_lists_hold_only_its_own_rows(): void
    {
        [$tenant, $token] = $this->tenantMember();
        $other = TenantFactory::new()->create(['slug' => 'globex']);
        HostnameFactory::new()->forOwner($other)->create(['host' => 'app.globex.com']);
        EmailDomainFactory::new()->forOwner($other)->create(['domain' => 'globex.com']);

        $this->asTenant('GET', '/neev/tenant/hostnames', $tenant, $token)->assertOk()->assertJsonCount(0, 'data');
        $this->asTenant('GET', '/neev/tenant/email-domains', $tenant, $token)->assertOk()->assertJsonCount(0, 'data');
    }

    /**
     * A user signed in outside any tenant, on a request that names none, has
     * no tenant to list or add to: each tenant route says so, and nothing is
     * stored.
     */
    public function test_the_tenant_routes_need_a_tenant_context(): void
    {
        $token = User::factory()->create()->createLoginToken(60)->plainTextToken;
        $bearer = ['Authorization' => 'Bearer ' . $token];

        foreach (['hostnames' => ['host' => 'app.acme.com'], 'email-domains' => ['domain' => 'acme.com']] as $uri => $data) {
            $this->withHeaders($bearer)->getJson("/neev/tenant/{$uri}")
                ->assertStatus(400)
                ->assertJsonPath('message', 'No tenant context.');

            $this->withHeaders($bearer)->postJson("/neev/tenant/{$uri}", $data)
                ->assertStatus(400)
                ->assertJsonPath('message', 'No tenant context.');
        }

        $this->assertSame(0, Hostname::count());
        $this->assertSame(0, EmailDomain::count());
    }

    public function test_the_tenant_routes_need_a_signed_in_user(): void
    {
        $tenant = TenantFactory::new()->create(['slug' => 'acme']);

        $this->withHeader('X-Tenant', 'acme')->getJson('/neev/tenant/hostnames')->assertUnauthorized();
        $this->withHeader('X-Tenant', 'acme')->getJson('/neev/tenant/email-domains')->assertUnauthorized();
        $this->assertSame(0, $tenant->hostnames()->count());
    }
}
