<?php

namespace Ssntpl\Neev\Tests\Feature\TenantDomains;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Database\Factories\DomainFactory;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Models\Domain;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\TenantResolver;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;
use Mockery;

class TenantDomainTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableTenantIsolation('test.com');
        config(['neev.tenant_isolation_options.allow_custom_domains' => true]);
    }

    protected function authenticatedUser(): array
    {
        $user = User::factory()->create();
        $token = $user->createLoginToken(60);

        return [$user, $token->plainTextToken];
    }

    // -----------------------------------------------------------------
    // GET /neev/tenant-domains/ — list tenant domains
    // -----------------------------------------------------------------

    public function test_list_tenant_domains(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);
        $team->allUsers()->attach($user, ['joined' => true, 'role' => '']);

        DomainFactory::new()->verified()->primary()->create([
            'team_id' => $team->id,
            'domain' => 'myteam.test.com',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/tenant-domains/?team_id=' . $team->id);

        $response->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonCount(1, 'data');
    }

    public function test_list_tenant_domains_returns_error_for_missing_team(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/tenant-domains/?team_id=99999');

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed');
    }

    public function test_list_tenant_domains_rejects_non_member(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $otherTeam = TeamFactory::new()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/tenant-domains/?team_id=' . $otherTeam->id);

        $response->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // POST /neev/tenant-domains/ — add domain
    // -----------------------------------------------------------------

    public function test_add_subdomain_is_auto_verified(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/tenant-domains/', [
                'team_id' => $team->id,
                'domain' => 'myteam.test.com',
                'type' => 'subdomain',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success');

        $domain = Domain::where('domain', 'myteam.test.com')->first();
        $this->assertNotNull($domain);
        $this->assertNotNull($domain->verified_at);
        $this->assertTrue($domain->is_primary); // First domain is primary
    }

    public function test_add_custom_domain_returns_verification_token(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/tenant-domains/', [
                'team_id' => $team->id,
                'domain' => 'custom.example.com',
                'type' => 'custom',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonStructure(['verification_token', 'dns_record']);

        $domain = Domain::where('domain', 'custom.example.com')->first();
        $this->assertNotNull($domain);
        $this->assertNull($domain->verified_at);
    }

    public function test_add_domain_rejects_non_owner(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $otherUser = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $otherUser->id]);
        $team->allUsers()->attach($user, ['joined' => true, 'role' => '']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/tenant-domains/', [
                'team_id' => $team->id,
                'domain' => 'notmine.test.com',
                'type' => 'subdomain',
            ]);

        $response->assertStatus(403);
    }

    public function test_add_duplicate_domain_fails(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);

        DomainFactory::new()->create([
            'team_id' => $team->id,
            'domain' => 'taken.test.com',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/tenant-domains/', [
                'team_id' => $team->id,
                'domain' => 'taken.test.com',
                'type' => 'subdomain',
            ]);

        $response->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // GET /neev/tenant-domains/{id} — show domain details
    // -----------------------------------------------------------------

    public function test_show_domain_details(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);
        $team->allUsers()->attach($user, ['joined' => true, 'role' => '']);

        $domain = DomainFactory::new()->verified()->create([
            'team_id' => $team->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/tenant-domains/' . $domain->id);

        $response->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonPath('data.id', $domain->id);
    }

    public function test_show_domain_returns_404_for_nonexistent(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/tenant-domains/99999');

        $response->assertStatus(404);
    }

    public function test_show_domain_rejects_non_member(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $otherTeam = TeamFactory::new()->create();
        $domain = DomainFactory::new()->create(['team_id' => $otherTeam->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/tenant-domains/' . $domain->id);

        $response->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // DELETE /neev/tenant-domains/{id} — delete domain
    // -----------------------------------------------------------------

    public function test_delete_domain(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);

        // Need at least 2 domains to delete one
        DomainFactory::new()->verified()->primary()->create([
            'team_id' => $team->id,
        ]);
        $domain2 = DomainFactory::new()->verified()->create([
            'team_id' => $team->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/tenant-domains/' . $domain2->id);

        $response->assertOk()
            ->assertJsonPath('status', 'Success');

        $this->assertDatabaseMissing('domains', ['id' => $domain2->id]);
    }

    public function test_cannot_delete_last_domain(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);

        $domain = DomainFactory::new()->verified()->primary()->create([
            'team_id' => $team->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/tenant-domains/' . $domain->id);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Cannot delete the last domain. Team must have at least one domain.');
    }

    public function test_delete_primary_domain_reassigns_primary(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);

        $primary = DomainFactory::new()->verified()->primary()->create([
            'team_id' => $team->id,
        ]);
        $secondary = DomainFactory::new()->verified()->create([
            'team_id' => $team->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/tenant-domains/' . $primary->id);

        $response->assertOk();

        $secondary->refresh();
        $this->assertTrue($secondary->is_primary);
    }

    public function test_delete_domain_rejects_non_owner(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $otherUser = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $otherUser->id]);
        $domain = DomainFactory::new()->create(['team_id' => $team->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/tenant-domains/' . $domain->id);

        $response->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // POST /neev/tenant-domains/{id}/regenerate-token
    // -----------------------------------------------------------------

    public function test_regenerate_token_rejects_non_custom_domain(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);

        // DB domains don't have a type column, so type is always null (not 'custom')
        $domain = DomainFactory::new()->create([
            'team_id' => $team->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/tenant-domains/' . $domain->id . '/regenerate-token');

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Only custom domains require verification.');
    }

    public function test_regenerate_token_rejects_non_owner(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $otherUser = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $otherUser->id]);
        $domain = DomainFactory::new()->create(['team_id' => $team->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/tenant-domains/' . $domain->id . '/regenerate-token');

        $response->assertStatus(403);
    }

    public function test_regenerate_token_returns_404_for_nonexistent(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/tenant-domains/99999/regenerate-token');

        $response->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // POST /neev/tenant-domains/{id}/primary — set primary
    // -----------------------------------------------------------------

    public function test_set_verified_domain_as_primary(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);

        DomainFactory::new()->verified()->primary()->create([
            'team_id' => $team->id,
        ]);
        $secondary = DomainFactory::new()->verified()->create([
            'team_id' => $team->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/tenant-domains/' . $secondary->id . '/primary');

        $response->assertOk()
            ->assertJsonPath('status', 'Success');

        $secondary->refresh();
        $this->assertTrue($secondary->is_primary);
    }

    public function test_set_primary_rejects_unverified_domain(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);

        $unverified = DomainFactory::new()->create([
            'team_id' => $team->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/tenant-domains/' . $unverified->id . '/primary');

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Only verified domains can be set as primary.');
    }

    public function test_set_primary_rejects_non_owner(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $otherUser = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $otherUser->id]);
        $domain = DomainFactory::new()->verified()->create(['team_id' => $team->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/tenant-domains/' . $domain->id . '/primary');

        $response->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // GET /neev/tenant-domains/current — current tenant context
    // -----------------------------------------------------------------

    public function test_current_tenant_returns_tenant_context(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);

        $resolver = Mockery::mock(TenantResolver::class);
        $resolver->shouldReceive('current')->andReturn($team);
        $resolver->shouldReceive('currentDomain')->andReturn(null);
        $this->app->instance(TenantResolver::class, $resolver);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/tenant-domains/current');

        $response->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonPath('data.team.id', $team->id);
    }

    public function test_current_tenant_returns_error_when_no_context(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $resolver = Mockery::mock(TenantResolver::class);
        $resolver->shouldReceive('current')->andReturn(null);
        $resolver->shouldReceive('currentDomain')->andReturn(null);
        $this->app->instance(TenantResolver::class, $resolver);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/tenant-domains/current');

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed');
    }

    // -----------------------------------------------------------------
    // POST /neev/tenant-domains/{id}/verify — verify domain
    // -----------------------------------------------------------------

    public function test_verify_returns_404_for_nonexistent_domain(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/tenant-domains/99999/verify');

        $response->assertStatus(404);
    }

    public function test_verify_rejects_non_owner(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $otherUser = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $otherUser->id]);
        $domain = DomainFactory::new()->create(['team_id' => $team->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/tenant-domains/' . $domain->id . '/verify');

        $response->assertStatus(403);
    }

    public function test_verify_returns_success_for_already_verified_domain(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);

        $domain = DomainFactory::new()->verified()->create([
            'team_id' => $team->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/tenant-domains/' . $domain->id . '/verify');

        $response->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonPath('message', 'Domain is already verified.');
    }

    // -----------------------------------------------------------------
    // DELETE /neev/tenant-domains/{id} — additional edge cases
    // -----------------------------------------------------------------

    public function test_delete_returns_404_for_nonexistent_domain(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/tenant-domains/99999');

        $response->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // POST /neev/tenant-domains/ — custom domains disabled
    // -----------------------------------------------------------------

    public function test_store_custom_domain_when_custom_domains_disabled(): void
    {
        config(['neev.tenant_isolation_options.allow_custom_domains' => false]);

        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/tenant-domains/', [
                'team_id' => $team->id,
                'domain' => 'custom-disabled.example.com',
                'type' => 'custom',
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed')
            ->assertJsonPath('message', 'Custom domains are not allowed.');
    }

    // -----------------------------------------------------------------
    // POST /neev/tenant-domains/ — store missing team
    // -----------------------------------------------------------------

    public function test_store_returns_error_for_missing_team(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/tenant-domains/', [
                'team_id' => 99999,
                'domain' => 'missing-team.test.com',
                'type' => 'subdomain',
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed');
    }

    // -----------------------------------------------------------------
    // GET /neev/tenant-domains/{id} — show non-owner but member
    // -----------------------------------------------------------------

    public function test_show_domain_for_team_member(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->allUsers()->attach($user, ['joined' => true, 'role' => '']);

        $domain = DomainFactory::new()->verified()->create([
            'team_id' => $team->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/tenant-domains/' . $domain->id);

        $response->assertOk()
            ->assertJsonPath('status', 'Success');
    }

    // -----------------------------------------------------------------
    // POST /neev/tenant-domains/{id}/primary — 404 domain not found
    // -----------------------------------------------------------------

    public function test_set_primary_returns_404_for_nonexistent_domain(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/tenant-domains/99999/primary');

        $response->assertStatus(404);
    }
}
