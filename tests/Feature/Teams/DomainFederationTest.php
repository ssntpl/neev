<?php

namespace Ssntpl\Neev\Tests\Feature\Teams;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Database\Factories\DomainFactory;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Models\Domain;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class DomainFederationTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableTeams();
        $this->enableDomainFederation();
    }

    /**
     * Create an authenticated user with a login token.
     *
     * @return array{0: \Ssntpl\Neev\Models\User, 1: string}
     */
    protected function authenticatedUser(): array
    {
        $user = User::factory()->create();
        $token = $user->createLoginToken(60);

        return [$user, $token->plainTextToken];
    }

    // -----------------------------------------------------------------
    // POST /neev/domains — federate domain
    // -----------------------------------------------------------------

    public function test_owner_can_add_domain_to_team(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/domains', [
                'team_id' => $team->id,
                'domain' => 'example.com',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonStructure(['token']);

        $this->assertDatabaseHas('domains', [
            'team_id' => $team->id,
            'domain' => 'example.com',
        ]);
    }

    public function test_first_domain_is_set_as_primary(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/domains', [
                'team_id' => $team->id,
                'domain' => 'primary-domain.com',
            ]);

        $response->assertOk();

        $domain = Domain::where('team_id', $team->id)->where('domain', 'primary-domain.com')->first();
        $this->assertNotNull($domain);
        $this->assertTrue($domain->is_primary);
    }

    public function test_non_owner_cannot_add_domain(): void
    {
        [$nonOwner, $token] = $this->authenticatedUser();

        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/domains', [
                'team_id' => $team->id,
                'domain' => 'forbidden.com',
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed');

        $this->assertDatabaseMissing('domains', [
            'domain' => 'forbidden.com',
        ]);
    }

    // -----------------------------------------------------------------
    // GET /neev/domains — list team domains
    // -----------------------------------------------------------------

    public function test_can_list_team_domains(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        DomainFactory::new()->create(['team_id' => $team->id, 'domain' => 'alpha.com']);
        DomainFactory::new()->create(['team_id' => $team->id, 'domain' => 'beta.com']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/domains?team_id=' . $team->id);

        $response->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonCount(2, 'data');
    }

    public function test_list_domains_returns_error_for_missing_team(): void
    {
        [$owner, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/domains?team_id=99999');

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed');
    }

    // -----------------------------------------------------------------
    // PUT /neev/domains — update domain (verify, enforce, regenerate token)
    // -----------------------------------------------------------------

    public function test_owner_can_update_domain_enforce_flag(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $domain = DomainFactory::new()->create([
            'team_id' => $team->id,
            'enforce' => false,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/domains', [
                'domain_id' => $domain->id,
                'enforce' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success');

        $this->assertTrue($domain->fresh()->enforce);
    }

    public function test_owner_can_regenerate_verification_token(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $domain = DomainFactory::new()->create([
            'team_id' => $team->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/domains', [
                'domain_id' => $domain->id,
                'token' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonStructure(['token']);
    }

    public function test_non_owner_cannot_update_domain(): void
    {
        [$nonOwner, $token] = $this->authenticatedUser();

        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $domain = DomainFactory::new()->create(['team_id' => $team->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/domains', [
                'domain_id' => $domain->id,
                'enforce' => true,
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed');
    }

    // -----------------------------------------------------------------
    // PUT /neev/domains/primary — set primary domain
    // -----------------------------------------------------------------

    public function test_can_set_verified_domain_as_primary(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        // Attach owner to team so they pass the membership check
        $team->allUsers()->attach($owner, ['joined' => true, 'role' => '']);

        $domainA = DomainFactory::new()->verified()->primary()->create(['team_id' => $team->id]);
        $domainB = DomainFactory::new()->verified()->create(['team_id' => $team->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/domains/primary', [
                'domain_id' => $domainB->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success');

        $this->assertTrue($domainB->fresh()->is_primary);
        $this->assertFalse($domainA->fresh()->is_primary);
    }

    public function test_cannot_set_unverified_domain_as_primary(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->allUsers()->attach($owner, ['joined' => true, 'role' => '']);

        $domain = DomainFactory::new()->create([
            'team_id' => $team->id,
            'verified_at' => null,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/domains/primary', [
                'domain_id' => $domain->id,
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed');
    }

    // -----------------------------------------------------------------
    // DELETE /neev/domains — delete domain
    // -----------------------------------------------------------------

    public function test_owner_can_delete_domain(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $domain = DomainFactory::new()->create(['team_id' => $team->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/domains', [
                'domain_id' => $domain->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success');

        $this->assertDatabaseMissing('domains', ['id' => $domain->id]);
    }

    public function test_non_owner_cannot_delete_domain(): void
    {
        [$nonOwner, $token] = $this->authenticatedUser();

        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $domain = DomainFactory::new()->create(['team_id' => $team->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/domains', [
                'domain_id' => $domain->id,
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed');

        $this->assertDatabaseHas('domains', ['id' => $domain->id]);
    }

    // -----------------------------------------------------------------
    // PUT /neev/domains/rules — update domain rules
    // -----------------------------------------------------------------

    public function test_owner_can_update_domain_rules(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $domain = DomainFactory::new()->verified()->create([
            'team_id' => $team->id,
        ]);

        // Create a domain rule
        $domain->rules()->create([
            'name' => 'mfa',
            'value' => false,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/domains/rules', [
                'domain_id' => $domain->id,
                'mfa' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success');

        $rule = $domain->rules()->where('name', 'mfa')->first();
        $this->assertTrue((bool) $rule->value);
    }

    public function test_non_owner_cannot_update_domain_rules(): void
    {
        [$nonOwner, $token] = $this->authenticatedUser();

        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $domain = DomainFactory::new()->create(['team_id' => $team->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/domains/rules', [
                'domain_id' => $domain->id,
                'mfa' => true,
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed');
    }

    public function test_update_domain_rules_returns_error_for_nonexistent_domain(): void
    {
        [$owner, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/domains/rules', [
                'domain_id' => 99999,
                'mfa' => true,
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed');
    }

    // -----------------------------------------------------------------
    // GET /neev/domains/rules — get domain rules
    // -----------------------------------------------------------------

    public function test_member_can_get_domain_rules(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->allUsers()->attach($owner, ['joined' => true, 'role' => '']);

        $domain = DomainFactory::new()->verified()->create([
            'team_id' => $team->id,
        ]);

        $domain->rules()->create([
            'name' => 'mfa',
            'value' => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/domains/rules?domain_id=' . $domain->id);

        $response->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonCount(1, 'data');
    }

    public function test_non_member_cannot_get_domain_rules(): void
    {
        [$nonMember, $token] = $this->authenticatedUser();

        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $domain = DomainFactory::new()->create(['team_id' => $team->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/domains/rules?domain_id=' . $domain->id);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed');
    }

    public function test_get_domain_rules_returns_error_for_nonexistent_domain(): void
    {
        [$owner, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/domains/rules?domain_id=99999');

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed');
    }

    // -----------------------------------------------------------------
    // GET /neev/domains — list domains with outside member count
    // -----------------------------------------------------------------

    public function test_list_domains_shows_outside_member_count_for_enforced_verified_domain(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->allUsers()->attach($owner, ['joined' => true, 'role' => '']);

        $domain = DomainFactory::new()->verified()->create([
            'team_id' => $team->id,
            'domain' => 'company.com',
            'enforce' => true,
        ]);

        // Add a member with an email outside the domain
        $outsideMember = User::factory()->create();
        $team->allUsers()->attach($outsideMember, ['joined' => true, 'role' => '']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/domains?team_id=' . $team->id);

        $response->assertOk()
            ->assertJsonPath('status', 'Success');
    }

    // -----------------------------------------------------------------
    // POST /neev/domains — domain federation with enforcement
    // -----------------------------------------------------------------

    public function test_add_domain_with_enforcement(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/domains', [
                'team_id' => $team->id,
                'domain' => 'enforced-domain.com',
                'enforce' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success');

        $domain = Domain::where('domain', 'enforced-domain.com')->first();
        $this->assertNotNull($domain);
        $this->assertTrue($domain->enforce);
    }

    // -----------------------------------------------------------------
    // PUT /neev/domains/primary — already primary returns early
    // -----------------------------------------------------------------

    public function test_set_already_primary_domain_returns_success(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->allUsers()->attach($owner, ['joined' => true, 'role' => '']);

        $domain = DomainFactory::new()->verified()->primary()->create(['team_id' => $team->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/domains/primary', [
                'domain_id' => $domain->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success');
    }

    // -----------------------------------------------------------------
    // PUT /neev/domains — verify domain (DNS lookup)
    // -----------------------------------------------------------------

    public function test_verify_domain_fails_when_dns_record_not_found(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        // Create an unverified domain with a fake domain name
        $domain = DomainFactory::new()->create([
            'team_id' => $team->id,
            'domain' => 'nonexistent-test-domain-' . uniqid() . '.invalid',
            'verification_token' => 'test-verification-token',
            'verified_at' => null,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/domains', [
                'domain_id' => $domain->id,
                'verify' => true,
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed');
    }

    // -----------------------------------------------------------------
    // PUT /neev/domains — update nonexistent domain
    // -----------------------------------------------------------------

    public function test_update_nonexistent_domain_returns_error(): void
    {
        [$owner, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/domains', [
                'domain_id' => 99999,
                'enforce' => true,
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed');
    }

    // -----------------------------------------------------------------
    // DELETE /neev/domains — delete nonexistent domain
    // -----------------------------------------------------------------

    public function test_delete_nonexistent_domain_returns_error(): void
    {
        [$owner, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/domains', [
                'domain_id' => 99999,
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed');
    }

    // -----------------------------------------------------------------
    // POST /neev/domains — add domain with nonexistent team
    // -----------------------------------------------------------------

    public function test_add_domain_with_nonexistent_team_returns_error(): void
    {
        [$owner, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/domains', [
                'team_id' => 99999,
                'domain' => 'orphan.com',
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed');
    }

    // -----------------------------------------------------------------
    // PUT /neev/domains/primary — nonexistent domain
    // -----------------------------------------------------------------

    public function test_set_primary_nonexistent_domain_returns_error(): void
    {
        [$owner, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/domains/primary', [
                'domain_id' => 99999,
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed');
    }

    // -----------------------------------------------------------------
    // DELETE /neev/domains — delete domain also deletes rules
    // -----------------------------------------------------------------

    public function test_delete_domain_also_deletes_rules(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $domain = DomainFactory::new()->create(['team_id' => $team->id]);

        // Create a rule for this domain
        $domain->rules()->create(['name' => 'mfa', 'value' => true]);
        $this->assertDatabaseHas('domain_rules', ['domain_id' => $domain->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/domains', [
                'domain_id' => $domain->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success');

        $this->assertDatabaseMissing('domains', ['id' => $domain->id]);
        $this->assertDatabaseMissing('domain_rules', ['domain_id' => $domain->id]);
    }
}
