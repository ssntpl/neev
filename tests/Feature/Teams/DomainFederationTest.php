<?php

namespace Ssntpl\Neev\Tests\Feature\Teams;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Database\Factories\DomainFactory;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Models\Domain;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\Support\FakeDns;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

// Must load before any test calls Domain::verify(); see the file for why.
require_once __DIR__ . '/../../Support/FakeDns.php';

class DomainFederationTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The team routes are registered only when `neev.team` is on, and that
        // happens while the providers boot — before setUp() runs. Enabling
        // teams from setUp() would set the config too late for the routes.
        $app['config']->set('neev.team', true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableDomainFederation();
    }

    protected function tearDown(): void
    {
        FakeDns::reset();

        parent::tearDown();
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

    public function test_federating_a_domain_another_team_verified_is_refused(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        DomainFactory::new()->verified()->create(['owner_type' => 'team', 'domain' => 'acme.com']);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/domains', ['team_id' => $team->id, 'domain' => 'ACME.com.'])
            ->assertStatus(400)
            ->assertJsonPath('message', 'This domain is already verified by another team.');

        $this->assertSame(0, $team->domains()->count());
    }

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
            ->assertJsonStructure(['token'])
            ->assertJsonPath('dns_record.type', 'TXT')
            ->assertJsonPath('dns_record.name', '_neev-verification.example.com')
            ->assertJsonPath('dns_record.value', $response->json('token'));
        // The same generator as the tenant-domain endpoints.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $response->json('token'));

        $this->assertDatabaseHas('domains', [
            'owner_type' => 'team',
            'owner_id' => $team->id,
            'domain' => 'example.com',
        ]);
    }

    public function test_refederating_the_primary_domain_keeps_it_primary(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $domain = DomainFactory::new()->primary()->create([
            'owner_type' => 'team', 'owner_id' => $team->id,
            'domain' => 'acme.com',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/domains', [
                'team_id' => $team->id,
                'domain' => 'acme.com',
            ])
            ->assertOk();

        $this->assertTrue($domain->fresh()->is_primary);
    }

    public function test_federating_another_spelling_of_a_held_domain_updates_the_same_row(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        DomainFactory::new()->create([
            'owner_type' => 'team', 'owner_id' => $team->id,
            'domain' => 'acme.com',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/domains', [
                'team_id' => $team->id,
                'domain' => 'ACME.com.',
            ])
            ->assertOk()
            ->assertJsonPath('dns_record.name', '_neev-verification.acme.com');

        $this->assertSame(1, Domain::where('owner_type', 'team')->where('owner_id', $team->id)->count());
    }

    public function test_federating_requires_a_domain(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/domains', ['team_id' => $team->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('domain');

        $this->assertSame(0, Domain::count());
    }

    public function test_federating_a_domain_that_is_only_dots_is_refused(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/domains', ['team_id' => $team->id, 'domain' => '...'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['domain' => 'The domain must be a host name.']);

        $this->assertSame(0, Domain::count());
    }

    public function test_federating_answers_400_when_saving_fails(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        Domain::saving(fn () => throw new \RuntimeException('database down'));

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/domains', ['team_id' => $team->id, 'domain' => 'acme.com'])
            ->assertStatus(400)
            ->assertJsonPath('message', 'An unexpected error occurred.');
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

        $domain = Domain::where('owner_type', 'team')->where('owner_id', $team->id)->where('domain', 'primary-domain.com')->first();
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

        $response->assertStatus(400);

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
        $team->addMember($owner);

        DomainFactory::new()->create(['owner_type' => 'team', 'owner_id' => $team->id, 'domain' => 'alpha.com']);
        DomainFactory::new()->create(['owner_type' => 'team', 'owner_id' => $team->id, 'domain' => 'beta.com']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/domains?team_id=' . $team->id);

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_list_domains_returns_error_for_missing_team(): void
    {
        [$owner, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/domains?team_id=99999');

        $response->assertStatus(400);
    }

    // -----------------------------------------------------------------
    // PUT /neev/domains — update domain (verify, enforce, regenerate token)
    // -----------------------------------------------------------------

    public function test_owner_can_update_domain_enforce_flag(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $domain = DomainFactory::new()->create([
            'owner_type' => 'team', 'owner_id' => $team->id,
            'enforce' => false,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/domains', [
                'domain_id' => $domain->id,
                'enforce' => true,
            ]);

        $response->assertOk();

        $this->assertTrue($domain->fresh()->enforce);
    }

    public function test_owner_can_regenerate_verification_token(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $domain = DomainFactory::new()->create([
            'owner_type' => 'team', 'owner_id' => $team->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/domains', [
                'domain_id' => $domain->id,
                'token' => true,
            ]);

        $response->assertOk()
            ->assertJsonStructure(['token'])
            ->assertJsonPath('dns_record.name', '_neev-verification.' . $domain->domain)
            ->assertJsonPath('dns_record.value', $response->json('token'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $response->json('token'));
    }

    public function test_regenerating_the_token_unverifies_the_domain(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $domain = DomainFactory::new()->verified()->create([
            'owner_type' => 'team', 'owner_id' => $team->id,
            'verification_failed_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/domains', [
                'domain_id' => $domain->id,
                'token' => true,
            ])
            ->assertOk();

        $domain->refresh();
        $this->assertNull($domain->verified_at);
        $this->assertNull($domain->verification_failed_at);
    }

    public function test_refederating_a_verified_domain_unverifies_it(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $domain = DomainFactory::new()->verified()->create([
            'owner_type' => 'team', 'owner_id' => $team->id,
            'domain' => 'acme.com',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/domains', [
                'team_id' => $team->id,
                'domain' => 'acme.com',
            ])
            ->assertOk();

        $this->assertNull($domain->fresh()->verified_at);
    }

    public function test_non_owner_cannot_update_domain(): void
    {
        [$nonOwner, $token] = $this->authenticatedUser();

        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $domain = DomainFactory::new()->create(['owner_type' => 'team', 'owner_id' => $team->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/domains', [
                'domain_id' => $domain->id,
                'enforce' => true,
            ]);

        $response->assertStatus(400);
    }

    // -----------------------------------------------------------------
    // PUT /neev/domains/primary — set primary domain
    // -----------------------------------------------------------------

    public function test_can_set_verified_domain_as_primary(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        // Attach owner to team so they pass the membership check
        $team->allUsers()->attach($owner, ['joined' => true]);

        $domainA = DomainFactory::new()->verified()->primary()->create(['owner_type' => 'team', 'owner_id' => $team->id]);
        $domainB = DomainFactory::new()->verified()->create(['owner_type' => 'team', 'owner_id' => $team->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/domains/primary', [
                'domain_id' => $domainB->id,
            ]);

        $response->assertOk();

        $this->assertTrue($domainB->fresh()->is_primary);
        $this->assertFalse($domainA->fresh()->is_primary);
    }

    public function test_cannot_set_unverified_domain_as_primary(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->allUsers()->attach($owner, ['joined' => true]);

        $domain = DomainFactory::new()->create([
            'owner_type' => 'team', 'owner_id' => $team->id,
            'verified_at' => null,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/domains/primary', [
                'domain_id' => $domain->id,
            ]);

        $response->assertStatus(400);
    }

    // -----------------------------------------------------------------
    // DELETE /neev/domains — delete domain
    // -----------------------------------------------------------------

    public function test_owner_can_delete_domain(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $domain = DomainFactory::new()->create(['owner_type' => 'team', 'owner_id' => $team->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/domains', [
                'domain_id' => $domain->id,
            ]);

        $response->assertOk();

        $this->assertDatabaseMissing('domains', ['id' => $domain->id]);
    }

    public function test_deleting_the_primary_domain_promotes_a_verified_one(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $primary = DomainFactory::new()->verified()->primary()->forTeam($team)->create();
        DomainFactory::new()->forTeam($team)->create();
        $verified = DomainFactory::new()->verified()->forTeam($team)->create();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/domains', ['domain_id' => $primary->id])
            ->assertOk();

        $this->assertTrue($verified->fresh()->is_primary);
        $this->assertSame(1, $team->domains()->where('is_primary', true)->count());
    }

    public function test_deleting_the_last_primary_falls_back_to_an_unverified_domain(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $primary = DomainFactory::new()->verified()->primary()->forTeam($team)->create();
        $pending = DomainFactory::new()->forTeam($team)->create();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/domains', ['domain_id' => $primary->id])
            ->assertOk();

        $this->assertTrue($pending->fresh()->is_primary);
    }

    public function test_deleting_a_non_primary_domain_keeps_the_primary(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $primary = DomainFactory::new()->verified()->primary()->forTeam($team)->create();
        $other = DomainFactory::new()->verified()->forTeam($team)->create();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/domains', ['domain_id' => $other->id])
            ->assertOk();

        $this->assertTrue($primary->fresh()->is_primary);
    }

    public function test_non_owner_cannot_delete_domain(): void
    {
        [$nonOwner, $token] = $this->authenticatedUser();

        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $domain = DomainFactory::new()->create(['owner_type' => 'team', 'owner_id' => $team->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/domains', [
                'domain_id' => $domain->id,
            ]);

        $response->assertStatus(400);

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
            'owner_type' => 'team', 'owner_id' => $team->id,
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

        $response->assertOk();

        $rule = $domain->rules()->where('name', 'mfa')->first();
        $this->assertTrue((bool) $rule->value);
    }

    public function test_non_owner_cannot_update_domain_rules(): void
    {
        [$nonOwner, $token] = $this->authenticatedUser();

        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $domain = DomainFactory::new()->create(['owner_type' => 'team', 'owner_id' => $team->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/domains/rules', [
                'domain_id' => $domain->id,
                'mfa' => true,
            ]);

        $response->assertStatus(400);
    }

    public function test_update_domain_rules_returns_error_for_nonexistent_domain(): void
    {
        [$owner, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/domains/rules', [
                'domain_id' => 99999,
                'mfa' => true,
            ]);

        $response->assertStatus(400);
    }

    // -----------------------------------------------------------------
    // GET /neev/domains/rules — get domain rules
    // -----------------------------------------------------------------

    public function test_member_can_get_domain_rules(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->allUsers()->attach($owner, ['joined' => true]);

        $domain = DomainFactory::new()->verified()->create([
            'owner_type' => 'team', 'owner_id' => $team->id,
        ]);

        $domain->rules()->create([
            'name' => 'mfa',
            'value' => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/domains/rules?domain_id=' . $domain->id);

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_non_member_cannot_get_domain_rules(): void
    {
        [$nonMember, $token] = $this->authenticatedUser();

        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $domain = DomainFactory::new()->create(['owner_type' => 'team', 'owner_id' => $team->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/domains/rules?domain_id=' . $domain->id);

        $response->assertStatus(400);
    }

    public function test_get_domain_rules_returns_error_for_nonexistent_domain(): void
    {
        [$owner, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/domains/rules?domain_id=99999');

        $response->assertStatus(400);
    }

    // -----------------------------------------------------------------
    // GET /neev/domains — list domains with outside member count
    // -----------------------------------------------------------------

    public function test_list_domains_shows_outside_member_count_for_enforced_verified_domain(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->allUsers()->attach($owner, ['joined' => true]);

        $domain = DomainFactory::new()->verified()->create([
            'owner_type' => 'team', 'owner_id' => $team->id,
            'domain' => 'company.com',
            'enforce' => true,
        ]);

        // Add a member with an email outside the domain
        $outsideMember = User::factory()->create();
        $team->allUsers()->attach($outsideMember, ['joined' => true]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/domains?team_id=' . $team->id);

        $response->assertOk();
    }

    public function test_list_domains_api_does_not_flag_a_member_on_a_second_federated_domain(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $owner->forceFill(['email' => 'owner@acme.com'])->save();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->allUsers()->attach($owner, ['joined' => true]);

        foreach (['acme.com', 'acme.io'] as $domain) {
            DomainFactory::new()->verified()->create([
                'owner_type' => 'team', 'owner_id' => $team->id,
                'domain' => $domain,
                'enforce' => true,
            ]);
        }

        $member = User::factory()->create(['email' => 'member@acme.io']);
        $team->allUsers()->attach($member, ['joined' => true]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/domains?team_id=' . $team->id);

        $response->assertOk();
        foreach ($response->json('data') as $domain) {
            $this->assertSame(0, $domain['outside_members']);
        }
    }

    // -----------------------------------------------------------------
    // GET /neev/teams/{team}/domain — the web page's "outside members"
    // warning counts a member against every federated domain at once
    // -----------------------------------------------------------------

    public function test_a_member_on_a_second_federated_domain_is_not_flagged_as_outside(): void
    {
        $owner = User::factory()->create(['email' => 'owner@acme.com']);
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->allUsers()->attach($owner, ['joined' => true]);

        foreach (['acme.com', 'acme.io'] as $domain) {
            DomainFactory::new()->verified()->create([
                'owner_type' => 'team', 'owner_id' => $team->id,
                'domain' => $domain,
                'enforce' => true,
            ]);
        }

        // On acme.io — inside the team's boundary, just not on acme.com.
        $member = User::factory()->create(['email' => 'member@acme.io']);
        $team->allUsers()->attach($member, ['joined' => true]);

        $response = $this->actingAs($owner)->get(route('teams.domain', ['team' => $team->id]));

        $response->assertOk();

        foreach ($response->viewData('outsideMembers') as $count) {
            $this->assertSame(0, $count);
        }
    }

    public function test_a_member_outside_every_federated_domain_is_flagged(): void
    {
        $owner = User::factory()->create(['email' => 'owner@acme.com']);
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->allUsers()->attach($owner, ['joined' => true]);

        DomainFactory::new()->verified()->create([
            'owner_type' => 'team', 'owner_id' => $team->id,
            'domain' => 'acme.com',
            'enforce' => true,
        ]);

        $outsider = User::factory()->create(['email' => 'someone@gmail.com']);
        $team->allUsers()->attach($outsider, ['joined' => true]);

        $response = $this->actingAs($owner)->get(route('teams.domain', ['team' => $team->id]));

        $response->assertOk();
        $this->assertSame([1], array_values($response->viewData('outsideMembers')));
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

        $response->assertOk();

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
        $team->allUsers()->attach($owner, ['joined' => true]);

        $domain = DomainFactory::new()->verified()->primary()->create(['owner_type' => 'team', 'owner_id' => $team->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/domains/primary', [
                'domain_id' => $domain->id,
            ]);

        $response->assertOk();
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
            'owner_type' => 'team', 'owner_id' => $team->id,
            'domain' => 'nonexistent-test-domain-' . uniqid() . '.invalid',
            'verification_token' => 'test-verification-token',
            'verified_at' => null,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/domains', [
                'domain_id' => $domain->id,
                'verify' => true,
            ]);

        $response->assertStatus(400);
    }

    /**
     * Verifying again after a new token finds the domain's rules already in
     * place; it must succeed and leave them as they are.
     */
    public function test_verifying_again_after_a_new_token_keeps_the_rules(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $domain = DomainFactory::new()->verified()->forTeam($team)->create(['domain' => 'acme.com']);
        $domain->rules()->create(['name' => 'mfa', 'value' => true]);

        $newToken = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/domains', ['domain_id' => $domain->id, 'token' => true])
            ->assertOk()
            ->json('token');

        FakeDns::txt('_neev-verification.acme.com', $newToken);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/domains', ['domain_id' => $domain->id, 'verify' => true])
            ->assertOk()
            ->assertJsonPath('message', 'Domain verified successfully!');

        $this->assertNotNull($domain->fresh()->verified_at);
        $this->assertSame(1, $domain->rules()->where('name', 'mfa')->count());
        $this->assertTrue((bool) $domain->rules()->where('name', 'mfa')->value('value'));
    }

    public function test_verify_refuses_a_domain_another_team_already_verified(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        DomainFactory::new()->verified()->create(['domain' => 'acme.com']);
        $claim = DomainFactory::new()->create([
            'owner_type' => 'team', 'owner_id' => $team->id,
            'domain' => 'acme.com',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/domains', [
                'domain_id' => $claim->id,
                'verify' => true,
            ])
            ->assertStatus(400)
            ->assertJsonPath('message', 'This domain is already verified by another team.');

        $this->assertNull($claim->fresh()->verified_at);
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

        $response->assertStatus(400);
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

        $response->assertStatus(400);
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

        $response->assertStatus(400);
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

        $response->assertStatus(400);
    }

    // -----------------------------------------------------------------
    // DELETE /neev/domains — delete domain also deletes rules
    // -----------------------------------------------------------------

    public function test_delete_domain_also_deletes_rules(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $domain = DomainFactory::new()->create(['owner_type' => 'team', 'owner_id' => $team->id]);

        // Create a rule for this domain
        $domain->rules()->create(['name' => 'mfa', 'value' => true]);
        $this->assertDatabaseHas('domain_rules', ['domain_id' => $domain->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/domains', [
                'domain_id' => $domain->id,
            ]);

        $response->assertOk();

        $this->assertDatabaseMissing('domains', ['id' => $domain->id]);
        $this->assertDatabaseMissing('domain_rules', ['domain_id' => $domain->id]);
    }

    // -----------------------------------------------------------------
    // Platform subdomains and malformed input
    // -----------------------------------------------------------------

    /**
     * A new token unverifies the domain until its record is published, and
     * nobody can publish a record in the platform's zone: the subdomain would
     * never verify again.
     */
    public function test_a_platform_subdomain_does_not_get_a_new_token(): void
    {
        config(['neev.platform_domain' => 'otper.com']);
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id, 'slug' => 'acme']);
        $domain = DomainFactory::new()->verified()->create([
            'owner_type' => 'team', 'owner_id' => $team->id,
            'domain' => 'acme.otper.com',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/domains', ['domain_id' => $domain->id, 'token' => true])
            ->assertStatus(400)
            ->assertJsonPath('message', 'A platform subdomain does not use a verification token.');

        $this->assertNotNull($domain->fresh()->verified_at);
    }

    public function test_refederating_a_platform_subdomain_is_refused(): void
    {
        config(['neev.platform_domain' => 'otper.com']);
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id, 'slug' => 'acme']);
        $domain = DomainFactory::new()->verified()->create([
            'owner_type' => 'team', 'owner_id' => $team->id,
            'domain' => 'acme.otper.com',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/domains', ['team_id' => $team->id, 'domain' => 'acme.otper.com'])
            ->assertStatus(400)
            ->assertJsonPath('message', 'A platform subdomain does not use a verification token.');

        $this->assertNotNull($domain->fresh()->verified_at);
    }

    public function test_federating_a_domain_that_is_not_a_string_is_refused(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/domains', ['team_id' => $team->id, 'domain' => ['acme.com']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('domain');

        $this->assertSame(0, Domain::count());
    }

    public function test_federating_something_that_is_not_a_host_name_is_refused(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        foreach (['https://acme.com/x', 'ac me.com', 'acme.com:8080'] as $value) {
            $this->withHeader('Authorization', 'Bearer ' . $token)
                ->postJson('/neev/domains', ['team_id' => $team->id, 'domain' => $value])
                ->assertStatus(422)
                ->assertJsonValidationErrors('domain');
        }

        $this->assertSame(0, Domain::count());
    }

    /**
     * With several candidates, the oldest verified one becomes primary, so the
     * choice does not depend on the order the database returns rows in.
     */
    public function test_deleting_the_primary_promotes_the_oldest_verified_domain(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $primary = DomainFactory::new()->verified()->primary()->create([
            'owner_type' => 'team', 'owner_id' => $team->id, 'domain' => 'acme.com',
        ]);
        $oldest = DomainFactory::new()->verified()->create([
            'owner_type' => 'team', 'owner_id' => $team->id, 'domain' => 'acme.io',
        ]);
        DomainFactory::new()->verified()->create([
            'owner_type' => 'team', 'owner_id' => $team->id, 'domain' => 'acme.dev',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/domains', ['domain_id' => $primary->id])
            ->assertOk();

        $this->assertTrue($oldest->fresh()->is_primary);
        $this->assertSame(1, $team->domains()->where('is_primary', true)->count());
    }

    // -----------------------------------------------------------------
    // Deleting a domain gives back the accounts it deactivated
    // -----------------------------------------------------------------

    /**
     * A verified domain deactivates members without detaching them. Once it
     * is deleted nothing manages them, so their accounts come back rather than
     * staying locked with nothing left to undo it.
     */
    public function test_deleting_a_verified_domain_reactivates_the_members_it_deactivated(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->addMember($owner);
        $domain = DomainFactory::new()->verified()->primary()->create([
            'owner_type' => 'team', 'owner_id' => $team->id,
            'domain' => 'acme.com',
        ]);
        $deactivated = User::factory()->create(['active' => true, 'email' => 'bob@ACME.com']);
        $team->addMember($deactivated);
        $elsewhere = User::factory()->create(['active' => true, 'email' => 'eve@other.com']);
        $team->addMember($elsewhere);
        $deactivated->deactivate();
        $elsewhere->deactivate();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/domains', ['domain_id' => $domain->id])
            ->assertOk();

        $this->assertTrue($deactivated->fresh()->active);
        $this->assertFalse($elsewhere->fresh()->active, 'Only members on the deleted host are reactivated.');
        $this->assertTrue($team->refresh()->hasMember($deactivated));
    }

    /**
     * A new token unverifies the domain but leaves the members it deactivated
     * as they were. Deleting it then must still give their accounts back, or
     * nothing is left that could.
     */
    public function test_deleting_a_domain_a_new_token_unverified_reactivates_the_members_it_deactivated(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->addMember($owner);
        $domain = DomainFactory::new()->verified()->primary()->create([
            'owner_type' => 'team', 'owner_id' => $team->id,
            'domain' => 'acme.com',
        ]);
        $member = User::factory()->create(['active' => true, 'email' => 'bob@acme.com']);
        $team->addMember($member);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/teams/leave', ['team_id' => $team->id, 'user_id' => $member->id])
            ->assertJsonPath('message', 'User Deactivated Successfully');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/domains', ['domain_id' => $domain->id, 'token' => true])
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/domains', ['domain_id' => $domain->id])
            ->assertOk();

        $this->assertTrue($member->fresh()->active);
        $this->assertTrue($team->refresh()->hasMember($member));
    }

    // -----------------------------------------------------------------
    // Removing a deactivated member another team may answer for
    // -----------------------------------------------------------------

    /**
     * Team A verified acme.com and deactivated Bob. Team B holds a pending
     * claim on the same host and also has Bob as a member. Removing Bob from
     * team B must not undo team A's deactivation.
     */
    public function test_removing_a_deactivated_member_leaves_them_deactivated_when_another_of_their_teams_claims_the_host(): void
    {
        $ownerA = User::factory()->create();
        $teamA = TeamFactory::new()->create(['user_id' => $ownerA->id]);
        $teamA->addMember($ownerA);

        [$ownerB, $tokenB] = $this->authenticatedUser();
        $teamB = TeamFactory::new()->create(['user_id' => $ownerB->id]);
        $teamB->addMember($ownerB);

        DomainFactory::new()->create([
            'owner_type' => 'team', 'owner_id' => $teamB->id,
            'domain' => 'acme.com',
        ]);
        DomainFactory::new()->verified()->primary()->create([
            'owner_type' => 'team', 'owner_id' => $teamA->id,
            'domain' => 'acme.com',
        ]);

        $bob = User::factory()->create(['active' => true, 'email' => 'bob@acme.com']);
        $teamA->addMember($bob);
        $teamB->addMember($bob);
        $bob->deactivate();

        $this->withHeader('Authorization', 'Bearer ' . $tokenB)
            ->putJson('/neev/teams/leave', ['team_id' => $teamB->id, 'user_id' => $bob->id])
            ->assertOk()
            ->assertJsonPath('message', 'Removed Successfully');

        $this->assertFalse($bob->fresh()->active);
        $this->assertFalse($teamB->refresh()->hasMember($bob));
        $this->assertTrue($teamA->refresh()->hasMember($bob));
    }
}
