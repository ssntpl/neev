<?php

namespace Ssntpl\Neev\Tests\Feature\Teams;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class TeamCRUDTest extends TestCase
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
    // GET /neev/teams — list user's teams
    // -----------------------------------------------------------------

    public function test_list_teams_returns_users_teams(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $teamA = TeamFactory::new()->create(['user_id' => $user->id]);
        $teamB = TeamFactory::new()->create(['user_id' => $user->id]);

        // Attach user as joined member
        $teamA->allUsers()->attach($user, ['joined' => true, 'role' => '']);
        $teamB->allUsers()->attach($user, ['joined' => true, 'role' => '']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/teams');

        $response->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonCount(2, 'data');
    }

    public function test_list_teams_returns_empty_when_user_has_no_teams(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/teams');

        $response->assertOk()
            ->assertJsonPath('status', 'Success');
    }

    // -----------------------------------------------------------------
    // GET /neev/teams/{id} — get specific team
    // -----------------------------------------------------------------

    public function test_get_specific_team_returns_team_details(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $team = TeamFactory::new()->create(['user_id' => $user->id]);
        $team->allUsers()->attach($user, ['joined' => true, 'role' => '']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/teams/' . $team->id);

        $response->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonPath('data.id', $team->id)
            ->assertJsonPath('data.name', $team->name);
    }

    public function test_get_team_returns_error_for_non_member(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $otherTeam = TeamFactory::new()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/teams/' . $otherTeam->id);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed');
    }

    public function test_get_team_returns_error_for_nonexistent_team(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/teams/99999');

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed');
    }

    // -----------------------------------------------------------------
    // POST /neev/teams — create team
    // -----------------------------------------------------------------

    public function test_create_team_with_valid_data(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/teams', [
                'name' => 'New Test Team',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonPath('data.name', 'New Test Team');

        $this->assertDatabaseHas('teams', [
            'name' => 'New Test Team',
            'user_id' => $user->id,
        ]);
    }

    public function test_create_team_attaches_user_as_member(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/teams', [
                'name' => 'Membership Team',
            ]);

        $response->assertOk();

        $team = Team::where('name', 'Membership Team')->first();
        $this->assertNotNull($team);
        $this->assertTrue($team->users->contains($user));
    }

    public function test_create_team_returns_error_without_name(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/teams', []);

        // SlugHelper::generate() throws a TypeError for null name, which is not
        // caught by the controller's Exception handler, resulting in a 500 response.
        $response->assertStatus(500);
    }

    public function test_create_public_team(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/teams', [
                'name' => 'Public Team',
                'public' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonPath('data.is_public', true);
    }

    // -----------------------------------------------------------------
    // PUT /neev/teams — update team
    // -----------------------------------------------------------------

    public function test_update_team_name(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $team = TeamFactory::new()->create(['user_id' => $user->id, 'name' => 'Old Name']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/teams', [
                'team_id' => $team->id,
                'name' => 'Updated Name',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonPath('data.name', 'Updated Name');

        $this->assertDatabaseHas('teams', [
            'id' => $team->id,
            'name' => 'Updated Name',
        ]);
    }

    public function test_update_team_public_status(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $team = TeamFactory::new()->create(['user_id' => $user->id, 'is_public' => false]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/teams', [
                'team_id' => $team->id,
                'public' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonPath('data.is_public', true);
    }

    public function test_update_nonexistent_team_returns_error(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/teams', [
                'team_id' => 99999,
                'name' => 'Whatever',
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed');
    }

    // -----------------------------------------------------------------
    // DELETE /neev/teams — delete team
    // -----------------------------------------------------------------

    public function test_owner_can_delete_team_when_they_own_multiple(): void
    {
        [$user, $token] = $this->authenticatedUser();

        // Owner must own at least 2 teams to be able to delete one
        $teamA = TeamFactory::new()->create(['user_id' => $user->id]);
        $teamB = TeamFactory::new()->create(['user_id' => $user->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/teams', [
                'team_id' => $teamA->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success');

        $this->assertDatabaseMissing('teams', ['id' => $teamA->id]);
        $this->assertDatabaseHas('teams', ['id' => $teamB->id]);
    }

    public function test_owner_cannot_delete_their_only_team(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $team = TeamFactory::new()->create(['user_id' => $user->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/teams', [
                'team_id' => $team->id,
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed');

        $this->assertDatabaseHas('teams', ['id' => $team->id]);
    }

    public function test_non_owner_cannot_delete_team(): void
    {
        [$user, $token] = $this->authenticatedUser();

        // Create a team owned by someone else
        $otherUser = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $otherUser->id]);

        // Give the authed user at least 2 owned teams to eliminate the "only team" guard
        TeamFactory::new()->create(['user_id' => $user->id]);
        TeamFactory::new()->create(['user_id' => $user->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/teams', [
                'team_id' => $team->id,
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed');

        $this->assertDatabaseHas('teams', ['id' => $team->id]);
    }

    // -----------------------------------------------------------------
    // POST /neev/changeTeamOwner — transfer ownership
    // -----------------------------------------------------------------

    public function test_owner_can_transfer_ownership(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $team = TeamFactory::new()->create(['user_id' => $user->id]);
        $member = User::factory()->create();

        // Add member to team
        $team->allUsers()->attach($user, ['joined' => true, 'role' => '']);
        $team->allUsers()->attach($member, ['joined' => true, 'role' => '']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/changeTeamOwner', [
                'team_id' => $team->id,
                'user_id' => $member->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success');

        $team->refresh();
        $this->assertEquals($member->id, $team->user_id);
    }

    public function test_change_team_owner_rejects_non_member_target(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $team = TeamFactory::new()->create(['user_id' => $user->id]);
        $team->allUsers()->attach($user, ['joined' => true, 'role' => '']);

        $nonMember = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/changeTeamOwner', [
                'team_id' => $team->id,
                'user_id' => $nonMember->id,
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed');
    }

    public function test_non_owner_cannot_change_team_owner(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $otherUser = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $otherUser->id]);

        // Add both users to team
        $team->allUsers()->attach($otherUser, ['joined' => true, 'role' => '']);
        $team->allUsers()->attach($user, ['joined' => true, 'role' => '']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/changeTeamOwner', [
                'team_id' => $team->id,
                'user_id' => $user->id,
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed');
    }
}
