<?php

namespace Ssntpl\Neev\Tests\Unit\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Models\Domain;
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
        config(['neev.tenant' => true, 'neev.team' => true]);

        $this->artisan('neev:tenant:create', ['name' => 'Acme', '--owner' => 'nobody@acme.test'])
            ->expectsOutputToContain('Owner not found')
            ->assertFailed();

        $this->assertDatabaseCount('tenants', 0);
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

    public function test_a_domain_verified_by_another_tenant_creates_nothing(): void
    {
        config(['neev.tenant' => true, 'neev.team' => true]);

        $other = Tenant::create(['name' => 'Other', 'slug' => 'other']);
        Domain::create([
            'domain' => 'acme.test',
            'owner_type' => 'tenant',
            'owner_id' => $other->id,
            'verified_at' => now(),
        ]);

        $this->artisan('neev:tenant:create', ['name' => 'Acme', '--domain' => 'acme.test'])
            ->expectsOutputToContain('already verified')
            ->assertFailed();

        $this->assertDatabaseCount('tenants', 1);
    }

    // -----------------------------------------------------------------
    // Happy path
    // -----------------------------------------------------------------

    public function test_it_creates_a_tenant_with_a_team_for_the_owner(): void
    {
        config(['neev.tenant' => true, 'neev.team' => true]);

        $user = User::factory()->create();

        $this->artisan('neev:tenant:create', ['name' => 'Acme', '--owner' => $user->email])
            ->assertSuccessful();

        $tenant = Tenant::firstWhere('name', 'Acme');
        $this->assertNotNull($tenant);
        $this->assertCount(1, $tenant->teams);
        $this->assertSame($user->id, $tenant->teams->first()->user_id);
    }
}
