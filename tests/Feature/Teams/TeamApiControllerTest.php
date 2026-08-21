<?php

namespace Ssntpl\Neev\Tests\Feature\Teams;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Database\Factories\DomainFactory;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class TeamApiControllerTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableTeams();
        $this->loadMigrationsFrom(
            dirname(__DIR__, 3) . '/vendor/ssntpl/laravel-acl/database/migrations'
        );
    }

    protected function authenticatedUser(): array
    {
        $user = User::factory()->create();
        $token = $user->createLoginToken(60);
        return [$user, $token->plainTextToken];
    }

    // -----------------------------------------------------------------
    // GET /neev/teams/invitations — get invitations
    // -----------------------------------------------------------------

    public function test_get_invitations_returns_user_invitations(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/teams/invitations');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'invitations',
                    'teamRequests',
                    'join_requests'
                ]
            ]);
    }

    public function test_get_invitations_with_invalid_user_returns_error(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer invalid-token')
            ->getJson('/neev/teams/invitations');

        $response->assertStatus(401);
    }

    // -----------------------------------------------------------------
    // PUT /neev/teams/default — set default team
    // -----------------------------------------------------------------

    public function test_set_default_team_success(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);
        $team->users()->attach($user, ['joined' => true]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/teams/default', ['team_id' => $team->id]);

        $response->assertOk()
            ->assertJsonPath('message', 'Default team updated successfully.');
    }

    public function test_set_default_team_user_not_member_returns_error(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $otherUser = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $otherUser->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/teams/default', ['team_id' => $team->id]);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Team not found');
    }

    // -----------------------------------------------------------------
    // GET /neev/teams — get user teams
    // -----------------------------------------------------------------

    public function test_get_teams_returns_user_teams(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/teams');

        $response->assertOk();
    }

    // -----------------------------------------------------------------
    // GET /neev/teams/{id} — get team details
    // -----------------------------------------------------------------

    public function test_get_team_returns_team_details(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);
        $team->users()->attach($user, ['joined' => true]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/neev/teams/{$team->id}");

        $response->assertOk();
    }

    public function test_get_team_non_member_returns_error(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $otherUser = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $otherUser->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/neev/teams/{$team->id}");

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Team not found');
    }

    // -----------------------------------------------------------------
    // POST /neev/teams — create team
    // -----------------------------------------------------------------

    public function test_create_team_success(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/teams', [
                'name' => 'Test Team',
                'public' => false
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('teams', [
            'name' => 'Test Team',
            'user_id' => $user->id,
            'is_public' => false
        ]);
    }

    // -----------------------------------------------------------------
    // PUT /neev/teams — update team
    // -----------------------------------------------------------------

    public function test_update_team_success(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);
        $team->addMember($user);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/teams', [
                'team_id' => $team->id,
                'name' => 'Updated Team Name',
                'public' => true
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Team has been updated.');

        $this->assertDatabaseHas('teams', [
            'id' => $team->id,
            'name' => 'Updated Team Name',
            'is_public' => true
        ]);
    }

    /**
     * The authorisation check runs first and answers the same way whether the
     * team is missing or simply not yours, so the endpoint never confirms
     * that a team id exists.
     */
    public function test_update_nonexistent_team_returns_error(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/teams', [
                'team_id' => 99999,
                'name' => 'Updated Team Name'
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'You cannot perform this action on this team.');
    }

    // -----------------------------------------------------------------
    // DELETE /neev/teams — delete team
    // -----------------------------------------------------------------

    public function test_delete_team_with_multiple_teams_success(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team1 = TeamFactory::new()->create(['user_id' => $user->id]);
        $team2 = TeamFactory::new()->create(['user_id' => $user->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/teams', ['team_id' => $team1->id]);

        $response->assertOk()
            ->assertJsonPath('message', 'Team has been deleted.');

        $this->assertDatabaseMissing('teams', ['id' => $team1->id]);
    }

    public function test_delete_team_with_single_team_returns_error(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/teams', ['team_id' => $team->id]);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'You cannot delete this team.');
    }

    // -----------------------------------------------------------------
    // POST /neev/changeTeamOwner — change team owner
    // -----------------------------------------------------------------

    public function test_change_team_owner_success(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $newOwner = User::factory()->create();
        $team->users()->attach($newOwner, ['joined' => true]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/changeTeamOwner', [
                'team_id' => $team->id,
                'user_id' => $newOwner->id
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('teams', [
            'id' => $team->id,
            'user_id' => $newOwner->id
        ]);
    }

    public function test_change_team_owner_non_member_returns_error(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $nonMember = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/changeTeamOwner', [
                'team_id' => $team->id,
                'user_id' => $nonMember->id
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'This user is not the member in this team.');
    }

    public function test_change_team_owner_non_owner_returns_error(): void
    {
        [$nonOwner, $token] = $this->authenticatedUser();
        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $newOwner = User::factory()->create();
        $team->users()->attach($newOwner, ['joined' => true]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/changeTeamOwner', [
                'team_id' => $team->id,
                'user_id' => $newOwner->id
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'You cannot change owner.');
    }

    // -----------------------------------------------------------------
    // GET /neev/domains — get team domains
    // -----------------------------------------------------------------

    public function test_get_domains_returns_team_domains(): void
    {
        $this->enableDomainFederation();

        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);
        $team->addMember($user);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/domains?team_id=' . $team->id);

        $response->assertOk()
            ->assertJsonPath('message', 'Domains fetched successfully.');
    }

    public function test_get_domains_nonexistent_team_returns_error(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/domains?team_id=99999');

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Team not found.');
    }

    // -----------------------------------------------------------------
    // POST /neev/domains — federate domain
    // -----------------------------------------------------------------

    public function test_domain_federate_success(): void
    {
        $this->enableDomainFederation();

        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/domains', [
                'team_id' => $team->id,
                'domain' => 'example.com',
                'enforce' => false
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Domain federated successfully.');

        $this->assertDatabaseHas('domains', [
            'owner_type' => 'team',
            'owner_id' => $team->id,
            'domain' => 'example.com'
        ]);
    }

    public function test_domain_federate_non_owner_returns_error(): void
    {
        $this->enableDomainFederation();

        [$nonOwner, $token] = $this->authenticatedUser();
        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/domains', [
                'team_id' => $team->id,
                'domain' => 'example.com'
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'You do not have the required permissions to federate domain.');
    }

    // -----------------------------------------------------------------
    // PUT /neev/domains — update domain
    // -----------------------------------------------------------------

    public function test_update_domain_regenerate_token_success(): void
    {
        $this->enableDomainFederation();

        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);
        $domain = DomainFactory::new()->create(['owner_type' => 'team', 'owner_id' => $team->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/domains', [
                'domain_id' => $domain->id,
                'token' => true
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Domain verification token has been updated.');
    }

    public function test_update_domain_enforce_setting_success(): void
    {
        $this->enableDomainFederation();

        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);
        $domain = DomainFactory::new()->create(['owner_type' => 'team', 'owner_id' => $team->id, 'enforce' => false]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/domains', [
                'domain_id' => $domain->id,
                'enforce' => true
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Domain has been updated.');

        $this->assertDatabaseHas('domains', [
            'id' => $domain->id,
            'enforce' => true
        ]);
    }

    // -----------------------------------------------------------------
    // DELETE /neev/domains — delete domain
    // -----------------------------------------------------------------

    public function test_delete_domain_success(): void
    {
        $this->enableDomainFederation();

        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);
        $domain = DomainFactory::new()->create(['owner_type' => 'team', 'owner_id' => $team->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/domains', ['domain_id' => $domain->id]);

        $response->assertOk()
            ->assertJsonPath('message', 'Domain has been deleted.');

        $this->assertDatabaseMissing('domains', ['id' => $domain->id]);
    }

    public function test_delete_domain_non_owner_returns_error(): void
    {
        $this->enableDomainFederation();

        [$nonOwner, $token] = $this->authenticatedUser();
        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $domain = DomainFactory::new()->create(['owner_type' => 'team', 'owner_id' => $team->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/domains', ['domain_id' => $domain->id]);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'You do not have the required permissions to delete domain.');
    }

    // -----------------------------------------------------------------
    // PUT /neev/domains/rules — update domain rules
    // -----------------------------------------------------------------

    public function test_update_domain_rules_success(): void
    {
        $this->enableDomainFederation();

        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);
        $domain = DomainFactory::new()->verified()->create(['owner_type' => 'team', 'owner_id' => $team->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/domains/rules', [
                'domain_id' => $domain->id,
                'mfa' => true
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Domain Rules have been updated.');
    }

    // -----------------------------------------------------------------
    // GET /neev/domains/rules — get domain rules
    // -----------------------------------------------------------------

    public function test_get_domain_rules_success(): void
    {
        $this->enableDomainFederation();

        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);
        $domain = DomainFactory::new()->verified()->create(['owner_type' => 'team', 'owner_id' => $team->id]);
        $team->users()->attach($user, ['joined' => true]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/domains/rules?domain_id=' . $domain->id);

        $response->assertOk();
    }

    // -----------------------------------------------------------------
    // PUT /neev/domains/primary — set primary domain
    // -----------------------------------------------------------------

    public function test_set_primary_domain_success(): void
    {
        $this->enableDomainFederation();

        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);
        $domain = DomainFactory::new()->verified()->create(['owner_type' => 'team', 'owner_id' => $team->id]);
        $team->users()->attach($user, ['joined' => true]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/domains/primary', ['domain_id' => $domain->id]);

        $response->assertOk();
    }

    public function test_set_primary_domain_unverified_returns_error(): void
    {
        $this->enableDomainFederation();

        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);
        $domain = DomainFactory::new()->create(['owner_type' => 'team', 'owner_id' => $team->id, 'verified_at' => null]);
        $team->users()->attach($user, ['joined' => true]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/domains/primary', ['domain_id' => $domain->id]);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'You do not have the required permissions to change primary domain.');
    }
    // =================================================================
    // Membership is what authorises a team action
    // =================================================================

    public function test_non_member_cannot_update_a_team(): void
    {
        [$outsider, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['name' => 'Untouched']);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/teams', [
                'team_id' => $team->id,
                'name' => 'Hijacked',
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'You cannot perform this action on this team.');

        $this->assertSame('Untouched', $team->refresh()->name);
    }

    public function test_non_member_cannot_list_a_teams_domains(): void
    {
        $this->enableDomainFederation();

        [$outsider, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/domains?team_id=' . $team->id)
            ->assertStatus(403)
            ->assertJsonPath('message', 'You cannot perform this action on this team.');
    }

    public function test_non_member_cannot_act_on_a_join_request(): void
    {
        [$outsider, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create();

        $requester = User::factory()->create();
        $team->allUsers()->attach($requester, [
            'joined' => false,
            'action' => 'request_from_user',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/teams/request', [
                'team_id' => $team->id,
                'user_id' => $requester->id,
                'action' => 'accept',
            ])
            ->assertStatus(403);

        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $requester->id,
            'joined' => false,
        ]);
    }

    public function test_non_member_cannot_remove_a_member(): void
    {
        [$outsider, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create();

        $member = User::factory()->create();
        $team->addMember($member);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/teams/leave', [
                'team_id' => $team->id,
                'user_id' => $member->id,
            ])
            ->assertStatus(403);

        $this->assertTrue($team->refresh()->hasMember($member));
    }

    /**
     * The authorisation check answers the same way for a team that does not
     * exist as for one that is not yours, so the endpoint never confirms
     * which team ids are real.
     */
    public function test_a_missing_team_is_refused_the_same_way_as_someone_elses(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $mine = TeamFactory::new()->create();

        $missing = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/teams', ['team_id' => 99999, 'name' => 'X']);

        $theirs = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/teams', ['team_id' => $mine->id, 'name' => 'X']);

        $missing->assertStatus(403);
        $theirs->assertStatus(403);
        $this->assertSame(
            $missing->json('message'),
            $theirs->json('message'),
            'The refusal must not distinguish a missing team from an inaccessible one.'
        );
    }

}
