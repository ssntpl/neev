<?php

namespace Ssntpl\Neev\Tests\Unit\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Models\Hostname;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\Tenant;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class CreateTenantCommandTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    // -----------------------------------------------------------------
    // Feature guards — nothing is created for a disabled install
    // -----------------------------------------------------------------

    public function test_it_refuses_to_create_a_team_when_teams_are_disabled(): void
    {
        config(['neev.tenant' => false, 'neev.team' => false]);

        $this->artisan('neev:tenant:create', ['name' => 'Acme'])
            ->expectsOutputToContain('Team support is disabled.')
            ->assertFailed();

        $this->assertDatabaseCount('teams', 0);
    }

    public function test_it_refuses_owner_when_teams_are_disabled_under_isolation(): void
    {
        config(['neev.tenant' => true, 'neev.team' => false]);

        $user = User::factory()->create();

        $this->artisan('neev:tenant:create', ['name' => 'Acme', '--owner' => $user->email])
            ->expectsOutputToContain('team support is disabled')
            ->assertFailed();

        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('teams', 0);
    }

    // -----------------------------------------------------------------
    // Inputs are checked before the first row is written
    // -----------------------------------------------------------------

    public function test_an_unknown_owner_creates_nothing(): void
    {
        config(['neev.tenant' => false, 'neev.team' => true]);

        $this->artisan('neev:tenant:create', ['name' => 'Acme', '--owner' => 'nobody@acme.test'])
            ->expectsOutputToContain('Owner not found')
            ->assertFailed();

        $this->assertDatabaseCount('teams', 0);
    }

    /**
     * Under isolation the owner is created in the new tenant, which no
     * existing user can belong to, so a user ID names nobody usable.
     */
    public function test_an_owner_given_by_id_under_isolation_creates_nothing(): void
    {
        config(['neev.tenant' => true, 'neev.team' => true]);

        $user = User::factory()->create();

        $this->artisan('neev:tenant:create', ['name' => 'Acme', '--owner' => (string) $user->id])
            ->expectsOutputToContain('--owner takes an email')
            ->assertFailed();

        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('teams', 0);
    }

    public function test_an_invalid_slug_creates_nothing(): void
    {
        config(['neev.tenant' => true, 'neev.team' => true]);

        $this->artisan('neev:tenant:create', ['name' => 'Acme', '--slug' => 'Not A Slug!'])
            ->expectsOutputToContain('Invalid slug')
            ->assertFailed();

        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_a_taken_slug_creates_nothing(): void
    {
        config(['neev.tenant' => true, 'neev.team' => true]);

        Tenant::create(['name' => 'Existing', 'slug' => 'acme']);

        $this->artisan('neev:tenant:create', ['name' => 'Acme', '--slug' => 'acme'])
            ->expectsOutputToContain('Slug already in use')
            ->assertFailed();

        $this->assertDatabaseCount('tenants', 1);
    }

    public function test_a_host_another_tenant_holds_creates_nothing(): void
    {
        config(['neev.tenant' => true, 'neev.team' => true]);

        $this->verifiedHost(Tenant::create(['name' => 'Other', 'slug' => 'other']), 'acme.test');

        $this->artisan('neev:tenant:create', ['name' => 'Acme', '--domain' => 'acme.test'])
            ->expectsOutputToContain('Host already claimed: acme.test')
            ->assertFailed();

        $this->assertDatabaseCount('tenants', 1);
    }

    /**
     * A host is unique, so even another owner's pending claim holds it.
     */
    public function test_a_host_another_tenant_has_only_claimed_creates_nothing(): void
    {
        config(['neev.tenant' => true, 'neev.team' => true]);

        Tenant::create(['name' => 'Other', 'slug' => 'other'])->claimHost('acme.test');

        $this->artisan('neev:tenant:create', ['name' => 'Acme', '--domain' => 'acme.test'])
            ->expectsOutputToContain('Host already claimed: acme.test')
            ->assertFailed();

        $this->assertDatabaseCount('tenants', 1);
    }

    public function test_a_host_under_the_platform_domain_creates_nothing(): void
    {
        config(['neev.tenant' => true, 'neev.team' => true, 'neev.platform_domain' => 'neev.test']);

        $this->artisan('neev:tenant:create', ['name' => 'Acme', '--domain' => 'acme.neev.test'])
            ->expectsOutputToContain('A host under the platform domain follows the slug: acme.neev.test')
            ->assertFailed();

        $this->assertDatabaseCount('tenants', 0);
    }

    // -----------------------------------------------------------------
    // Happy path
    // -----------------------------------------------------------------

    /**
     * The owner is the tenant's own user: a member of the tenant as well as
     * its team, so they can sign in on its host. A platform user of the same
     * email is a separate account and is left alone.
     */
    public function test_it_creates_a_tenant_with_its_owner_inside_it(): void
    {
        config(['neev.tenant' => true, 'neev.team' => true]);

        $platformUser = User::factory()->create(['email' => 'owner@acme.test']);

        $this->artisan('neev:tenant:create', ['name' => 'Acme', '--owner' => 'owner@acme.test', '--owner-name' => 'Ada'])
            ->expectsOutputToContain('created in the tenant')
            ->assertSuccessful();

        $tenant = Tenant::firstWhere('name', 'Acme');
        $this->assertNotNull($tenant);

        $owner = User::withoutTenantScope()->where('tenant_id', $tenant->id)->sole();
        $this->assertSame('owner@acme.test', $owner->email);
        $this->assertSame('Ada', $owner->name);
        $this->assertTrue($tenant->hasMember($owner));

        $this->assertCount(1, $tenant->teams);
        $team = $tenant->teams->first();
        $this->assertSame($owner->id, $team->user_id);
        $this->assertTrue($team->hasMember($owner));

        $this->assertNull($platformUser->fresh()->tenant_id, 'The platform account is untouched.');
        $this->assertFalse($team->hasMember($platformUser));
    }

    /** The owner signs in on the tenant's platform subdomain. */
    public function test_it_prints_the_sign_in_url_under_the_platform_domain(): void
    {
        config(['neev.tenant' => true, 'neev.team' => true, 'neev.platform_domain' => 'neev.test']);

        $this->artisan('neev:tenant:create', ['name' => 'Acme', '--slug' => 'acme', '--owner' => 'ada@acme.test'])
            ->expectsOutputToContain('The owner has no password yet: they sign in at https://acme.neev.test with a login link.')
            ->assertSuccessful();
    }

    /** With no platform domain there is no URL to print yet. */
    public function test_it_prints_no_sign_in_url_without_a_platform_domain(): void
    {
        config(['neev.tenant' => true, 'neev.team' => true, 'neev.platform_domain' => null]);

        $this->artisan('neev:tenant:create', ['name' => 'Acme', '--owner' => 'ada@acme.test'])
            ->doesntExpectOutputToContain('they sign in at')
            ->assertSuccessful();
    }

    public function test_an_owner_without_a_name_is_named_from_the_email(): void
    {
        config(['neev.tenant' => true, 'neev.team' => true]);

        $this->artisan('neev:tenant:create', ['name' => 'Acme', '--owner' => 'ada@acme.test'])
            ->assertSuccessful();

        $this->assertSame('ada', User::withoutTenantScope()->where('email', 'ada@acme.test')->sole()->name);
    }

    /** In shared mode the owner is still an existing (platform) user. */
    public function test_shared_mode_makes_an_existing_user_the_owner(): void
    {
        config(['neev.tenant' => false, 'neev.team' => true]);

        $user = User::factory()->create();

        $this->artisan('neev:tenant:create', ['name' => 'Acme', '--owner' => $user->email])
            ->assertSuccessful();

        $this->assertDatabaseCount('users', 1);
        $this->assertTrue(Team::firstWhere('name', 'Acme')->hasMember($user));
    }

    public function test_domain_claims_a_pending_host_which_is_not_primary_yet(): void
    {
        config(['neev.tenant' => true, 'neev.team' => true]);

        $this->artisan('neev:tenant:create', ['name' => 'Acme', '--domain' => 'App.Acme.test'])
            ->expectsOutputToContain('Domain attached: app.acme.test')
            ->expectsOutputToContain('_neev-host.app.acme.test')
            ->expectsOutputToContain('neev:hostname:primary app.acme.test')
            ->assertSuccessful();

        $tenant = Tenant::firstWhere('name', 'Acme');
        $hostname = Hostname::forHost('app.acme.test')->first();
        $this->assertNotNull($hostname);
        $this->assertTrue($hostname->isOwnedBy($tenant));
        $this->assertFalse($hostname->isVerified());
        $this->assertNotNull($hostname->verification_token);
        $this->assertNull($tenant->primary_hostname_id, 'Only a verified host can be primary.');
    }

    public function test_domain_on_a_team_claims_a_pending_host(): void
    {
        config(['neev.tenant' => false, 'neev.team' => true]);

        $user = User::factory()->create();

        $this->artisan('neev:tenant:create', ['name' => 'Acme', '--owner' => $user->email, '--domain' => 'app.acme.test'])
            ->assertSuccessful();

        $team = Team::withoutTenantScope()->firstWhere('name', 'Acme');
        $hostname = $team->hostnames()->first();
        $this->assertSame('app.acme.test', $hostname->host);
        $this->assertNull($team->primary_hostname_id);
        $this->assertDatabaseCount('email_domains', 0);
    }
}
