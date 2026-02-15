<?php

namespace Ssntpl\Neev\Tests\Unit\Traits;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class HasTeamsTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    // -----------------------------------------------------------------
    // currentTeam()
    // -----------------------------------------------------------------

    public function test_current_team_returns_team_when_current_team_id_is_set(): void
    {
        $user = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);

        $team->allUsers()->attach($user, ['joined' => true, 'role' => null, 'action' => 'request_to_user']);

        $user->forceFill(['current_team_id' => $team->id])->save();
        $user->refresh();

        $this->assertNotNull($user->currentTeam);
        $this->assertTrue($user->currentTeam->is($team));
    }

    public function test_current_team_returns_null_when_current_team_id_is_not_set(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->currentTeam);
    }

    // -----------------------------------------------------------------
    // belongsToTeam()
    // -----------------------------------------------------------------

    public function test_belongs_to_team_returns_true_for_joined_member(): void
    {
        $user = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);

        $team->allUsers()->attach($user, ['joined' => true, 'role' => null, 'action' => 'request_to_user']);

        $this->assertTrue($user->belongsToTeam($team));
    }

    public function test_belongs_to_team_returns_false_for_non_member(): void
    {
        $user = User::factory()->create();
        $team = TeamFactory::new()->create();

        $this->assertFalse($user->belongsToTeam($team));
    }

    public function test_belongs_to_team_returns_false_for_null_team(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->belongsToTeam(null));
    }

    public function test_belongs_to_team_returns_false_for_non_joined_member(): void
    {
        $user = User::factory()->create();
        $team = TeamFactory::new()->create();

        $team->allUsers()->attach($user, ['joined' => false, 'role' => null, 'action' => 'request_to_user']);

        $this->assertFalse($user->belongsToTeam($team));
    }

    // -----------------------------------------------------------------
    // switchTeam()
    // -----------------------------------------------------------------

    public function test_switch_team_sets_current_team_id_and_returns_true_for_member(): void
    {
        $user = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);

        $team->allUsers()->attach($user, ['joined' => true, 'role' => null, 'action' => 'request_to_user']);

        $result = $user->switchTeam($team);

        $this->assertTrue($result);
        $this->assertEquals($team->id, $user->fresh()->current_team_id);
        $this->assertTrue($user->currentTeam->is($team));
    }

    public function test_switch_team_returns_false_for_non_member(): void
    {
        $user = User::factory()->create();
        $team = TeamFactory::new()->create();

        $result = $user->switchTeam($team);

        $this->assertFalse($result);
        $this->assertNull($user->fresh()->current_team_id);
    }

    public function test_switch_team_can_switch_between_multiple_teams(): void
    {
        $user = User::factory()->create();
        $teamA = TeamFactory::new()->create(['user_id' => $user->id]);
        $teamB = TeamFactory::new()->create(['user_id' => $user->id]);

        $teamA->allUsers()->attach($user, ['joined' => true, 'role' => null, 'action' => 'request_to_user']);
        $teamB->allUsers()->attach($user, ['joined' => true, 'role' => null, 'action' => 'request_to_user']);

        $user->switchTeam($teamA);
        $this->assertEquals($teamA->id, $user->fresh()->current_team_id);

        $user->switchTeam($teamB);
        $this->assertEquals($teamB->id, $user->fresh()->current_team_id);
    }

    // -----------------------------------------------------------------
    // ownedTeams()
    // -----------------------------------------------------------------

    public function test_owned_teams_returns_teams_owned_by_user(): void
    {
        $user = User::factory()->create();
        $ownedTeamA = TeamFactory::new()->create(['user_id' => $user->id]);
        $ownedTeamB = TeamFactory::new()->create(['user_id' => $user->id]);
        $otherTeam = TeamFactory::new()->create();

        $ownedTeams = $user->ownedTeams;

        $this->assertCount(2, $ownedTeams);
        $this->assertTrue($ownedTeams->contains($ownedTeamA));
        $this->assertTrue($ownedTeams->contains($ownedTeamB));
        $this->assertFalse($ownedTeams->contains($otherTeam));
    }

    public function test_owned_teams_returns_empty_collection_when_user_owns_no_teams(): void
    {
        $user = User::factory()->create();

        $this->assertCount(0, $user->ownedTeams);
    }

    // -----------------------------------------------------------------
    // teams()
    // -----------------------------------------------------------------

    public function test_teams_only_includes_joined_members(): void
    {
        $user = User::factory()->create();
        $joinedTeam = TeamFactory::new()->create();
        $pendingTeam = TeamFactory::new()->create();

        $joinedTeam->allUsers()->attach($user, ['joined' => true, 'role' => null, 'action' => 'request_to_user']);
        $pendingTeam->allUsers()->attach($user, ['joined' => false, 'role' => null, 'action' => 'request_to_user']);

        $teams = $user->teams;

        $this->assertCount(1, $teams);
        $this->assertTrue($teams->contains($joinedTeam));
        $this->assertFalse($teams->contains($pendingTeam));
    }

    public function test_teams_includes_pivot_data(): void
    {
        $user = User::factory()->create();
        $team = TeamFactory::new()->create();

        $team->allUsers()->attach($user, ['joined' => true, 'role' => 'admin', 'action' => 'request_to_user']);

        $teams = $user->teams;
        $membership = $teams->first()->membership;

        $this->assertEquals('admin', $membership->role);
        $this->assertTrue($membership->joined);
    }

    // -----------------------------------------------------------------
    // allTeams()
    // -----------------------------------------------------------------

    public function test_all_teams_includes_both_joined_and_non_joined(): void
    {
        $user = User::factory()->create();
        $joinedTeam = TeamFactory::new()->create();
        $pendingTeam = TeamFactory::new()->create();

        $joinedTeam->allUsers()->attach($user, ['joined' => true, 'role' => null, 'action' => 'request_to_user']);
        $pendingTeam->allUsers()->attach($user, ['joined' => false, 'role' => null, 'action' => 'request_to_user']);

        $allTeams = $user->allTeams;

        $this->assertCount(2, $allTeams);
        $this->assertTrue($allTeams->contains($joinedTeam));
        $this->assertTrue($allTeams->contains($pendingTeam));
    }

    // -----------------------------------------------------------------
    // teamRequests()
    // -----------------------------------------------------------------

    public function test_team_requests_returns_invitations_sent_to_user(): void
    {
        $user = User::factory()->create();
        $invitedTeam = TeamFactory::new()->create();
        $requestedTeam = TeamFactory::new()->create();
        $joinedTeam = TeamFactory::new()->create();

        // Team invites user (request_to_user)
        $invitedTeam->allUsers()->attach($user, ['joined' => false, 'role' => null, 'action' => 'request_to_user']);
        // User requests to join team (request_from_user)
        $requestedTeam->allUsers()->attach($user, ['joined' => false, 'role' => null, 'action' => 'request_from_user']);
        // User has already joined a team
        $joinedTeam->allUsers()->attach($user, ['joined' => true, 'role' => null, 'action' => 'request_to_user']);

        $teamRequests = $user->teamRequests;

        $this->assertCount(1, $teamRequests);
        $this->assertTrue($teamRequests->contains($invitedTeam));
        $this->assertFalse($teamRequests->contains($requestedTeam));
        $this->assertFalse($teamRequests->contains($joinedTeam));
    }

    public function test_team_requests_returns_empty_when_no_invitations(): void
    {
        $user = User::factory()->create();

        $this->assertCount(0, $user->teamRequests);
    }

    // -----------------------------------------------------------------
    // sendRequests()
    // -----------------------------------------------------------------

    public function test_send_requests_returns_user_sent_requests(): void
    {
        $user = User::factory()->create();
        $requestedTeam = TeamFactory::new()->create();
        $invitedTeam = TeamFactory::new()->create();
        $joinedTeam = TeamFactory::new()->create();

        // User requests to join team (request_from_user)
        $requestedTeam->allUsers()->attach($user, ['joined' => false, 'role' => null, 'action' => 'request_from_user']);
        // Team invites user (request_to_user)
        $invitedTeam->allUsers()->attach($user, ['joined' => false, 'role' => null, 'action' => 'request_to_user']);
        // User has already joined a team
        $joinedTeam->allUsers()->attach($user, ['joined' => true, 'role' => null, 'action' => 'request_from_user']);

        $sendRequests = $user->sendRequests;

        $this->assertCount(1, $sendRequests);
        $this->assertTrue($sendRequests->contains($requestedTeam));
        $this->assertFalse($sendRequests->contains($invitedTeam));
        $this->assertFalse($sendRequests->contains($joinedTeam));
    }

    public function test_send_requests_returns_empty_when_no_outgoing_requests(): void
    {
        $user = User::factory()->create();

        $this->assertCount(0, $user->sendRequests);
    }
}
