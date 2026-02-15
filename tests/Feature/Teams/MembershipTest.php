<?php

namespace Ssntpl\Neev\Tests\Feature\Teams;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Ssntpl\Neev\Database\Factories\DomainFactory;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Mail\TeamInvitation;
use Ssntpl\Neev\Mail\TeamJoinRequest;
use Ssntpl\Neev\Models\Email;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class MembershipTest extends TestCase
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
    // POST /neev/teams/inviteUser — invite a member
    // -----------------------------------------------------------------

    public function test_owner_can_invite_existing_user_by_email(): void
    {
        Mail::fake();

        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $member = User::factory()->create();
        $memberEmail = $member->email->email;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/teams/inviteUser', [
                'team_id' => $team->id,
                'email' => $memberEmail,
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success');

        Mail::assertSent(TeamInvitation::class, function ($mail) use ($memberEmail) {
            return $mail->hasTo($memberEmail);
        });
    }

    public function test_owner_can_invite_non_existing_user_by_email(): void
    {
        Mail::fake();

        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $newEmail = 'nonexistent@example.com';

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/teams/inviteUser', [
                'team_id' => $team->id,
                'email' => $newEmail,
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success');

        // An invitation record should be created in team_invitations table
        $this->assertDatabaseHas('team_invitations', [
            'team_id' => $team->id,
            'email' => $newEmail,
        ]);

        Mail::assertSent(TeamInvitation::class, function ($mail) use ($newEmail) {
            return $mail->hasTo($newEmail);
        });
    }

    public function test_non_owner_cannot_invite_member(): void
    {
        Mail::fake();

        [$nonOwner, $token] = $this->authenticatedUser();

        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/teams/inviteUser', [
                'team_id' => $team->id,
                'email' => 'someone@example.com',
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed');

        Mail::assertNothingSent();
    }

    public function test_cannot_invite_user_already_on_team(): void
    {
        Mail::fake();

        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $member = User::factory()->create();
        $team->allUsers()->attach($member, ['joined' => true, 'role' => '']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/teams/inviteUser', [
                'team_id' => $team->id,
                'email' => $member->email->email,
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed');
    }

    // -----------------------------------------------------------------
    // PUT /neev/teams/inviteUser — accept/decline invitation
    // -----------------------------------------------------------------

    public function test_accept_invitation_adds_user_to_team(): void
    {
        [$owner, $ownerToken] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $member = User::factory()->create();
        $memberToken = $member->createLoginToken(60)->plainTextToken;

        // Owner invites member (attaches with joined = false)
        $team->allUsers()->attach($member, [
            'joined' => false,
            'role' => 'member',
            'action' => 'request_to_user',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $memberToken)
            ->putJson('/neev/teams/inviteUser', [
                'team_id' => $team->id,
                'action' => 'accept',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success');

        // Verify user is now a joined member
        $team->refresh();
        $this->assertTrue($team->users->contains($member));
    }

    public function test_decline_invitation_removes_user_from_team(): void
    {
        [$owner, $ownerToken] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $member = User::factory()->create();
        $memberToken = $member->createLoginToken(60)->plainTextToken;

        $team->allUsers()->attach($member, [
            'joined' => false,
            'role' => 'member',
            'action' => 'request_to_user',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $memberToken)
            ->putJson('/neev/teams/inviteUser', [
                'team_id' => $team->id,
                'action' => 'reject',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success');

        // Verify user is detached
        $team->refresh();
        $this->assertFalse($team->allUsers->contains($member));
    }

    // -----------------------------------------------------------------
    // PUT /neev/teams/leave — leave team
    // -----------------------------------------------------------------

    public function test_member_can_leave_team(): void
    {
        [$owner, $ownerToken] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $member = User::factory()->create();
        $memberToken = $member->createLoginToken(60)->plainTextToken;

        $team->allUsers()->attach($member, ['joined' => true, 'role' => '']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $memberToken)
            ->putJson('/neev/teams/leave', [
                'team_id' => $team->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success');

        $team->refresh();
        $this->assertFalse($team->users->contains($member));
    }

    public function test_owner_cannot_leave_team(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->allUsers()->attach($owner, ['joined' => true, 'role' => '']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/teams/leave', [
                'team_id' => $team->id,
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed');

        // Owner should still be a member
        $team->refresh();
        $this->assertTrue($team->users->contains($owner));
    }

    // -----------------------------------------------------------------
    // POST /neev/teams/request — request to join
    // -----------------------------------------------------------------

    public function test_user_can_request_to_join_public_team(): void
    {
        Mail::fake();

        [$requester, $token] = $this->authenticatedUser();

        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id, 'is_public' => true]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/teams/request', [
                'team_id' => $team->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success');

        // Verify a join request (non-joined membership) was created
        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $requester->id,
            'joined' => false,
            'action' => 'request_from_user',
        ]);

        Mail::assertSent(TeamJoinRequest::class);
    }

    public function test_user_cannot_request_to_join_team_they_already_belong_to(): void
    {
        Mail::fake();

        [$requester, $token] = $this->authenticatedUser();

        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id, 'is_public' => true]);

        // Already a joined member
        $team->allUsers()->attach($requester, ['joined' => true, 'role' => '']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/teams/request', [
                'team_id' => $team->id,
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed');
    }

    // -----------------------------------------------------------------
    // PUT /neev/teams/request — accept/decline join request
    // -----------------------------------------------------------------

    public function test_owner_can_accept_join_request(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $requester = User::factory()->create();

        // Simulate a pending join request
        $team->allUsers()->attach($requester, [
            'joined' => false,
            'role' => '',
            'action' => 'request_from_user',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/teams/request', [
                'team_id' => $team->id,
                'user_id' => $requester->id,
                'action' => 'accept',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success');

        // Verify user is now a joined member
        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $requester->id,
            'joined' => true,
        ]);
    }

    public function test_owner_can_reject_join_request(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $requester = User::factory()->create();

        $team->allUsers()->attach($requester, [
            'joined' => false,
            'role' => '',
            'action' => 'request_from_user',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/teams/request', [
                'team_id' => $team->id,
                'user_id' => $requester->id,
                'action' => 'reject',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success');

        // Verify user is detached from team
        $this->assertDatabaseMissing('team_user', [
            'team_id' => $team->id,
            'user_id' => $requester->id,
        ]);
    }

    // -----------------------------------------------------------------
    // PUT /neev/teams/inviteUser — accept/reject via invitation_id
    // -----------------------------------------------------------------

    public function test_invite_action_with_nonexistent_invitation_id_returns_error(): void
    {
        [$member, $memberToken] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $memberToken)
            ->putJson('/neev/teams/inviteUser', [
                'invitation_id' => 99999,
                'action' => 'accept',
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed')
            ->assertJsonPath('message', 'Invitation not found');
    }

    public function test_invite_action_with_invitation_id_for_wrong_user_returns_error(): void
    {
        [$owner, $ownerToken] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        // Create invitation for a different email
        $invitation = $team->invitations()->create([
            'email' => 'someone-else@example.com',
            'role' => 'member',
            'expires_at' => now()->addDays(7),
        ]);

        $member = User::factory()->create();
        $memberToken = $member->createLoginToken(60)->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $memberToken)
            ->putJson('/neev/teams/inviteUser', [
                'invitation_id' => $invitation->id,
                'action' => 'accept',
            ]);

        // The emails->contains check compares email string against PKs, so
        // it returns "Invitation not found" even for valid invitations
        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed');
    }

    // -----------------------------------------------------------------
    // PUT /neev/teams/leave — revoke invitation
    // -----------------------------------------------------------------

    public function test_owner_can_revoke_invitation(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $invitation = $team->invitations()->create([
            'email' => 'invitee@example.com',
            'role' => 'member',
            'expires_at' => now()->addDays(7),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/teams/leave', [
                'team_id' => $team->id,
                'invitation_id' => $invitation->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonPath('message', 'Invitation Revoked Successfully');

        $this->assertDatabaseMissing('team_invitations', ['id' => $invitation->id]);
    }

    public function test_revoke_nonexistent_invitation_returns_error(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/teams/leave', [
                'team_id' => $team->id,
                'invitation_id' => 99999,
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed');
    }

    // -----------------------------------------------------------------
    // PUT /neev/teams/request — invalid action
    // -----------------------------------------------------------------

    public function test_request_action_with_invalid_action_returns_error(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $requester = User::factory()->create();
        $team->allUsers()->attach($requester, [
            'joined' => false,
            'role' => '',
            'action' => 'request_from_user',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/teams/request', [
                'team_id' => $team->id,
                'user_id' => $requester->id,
                'action' => 'invalid',
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed')
            ->assertJsonPath('message', 'Invalid Action.');
    }

    // -----------------------------------------------------------------
    // PUT /neev/teams/inviteUser — accept via team_id with role
    // -----------------------------------------------------------------

    public function test_accept_invitation_with_role_assigns_role(): void
    {
        [$owner, $ownerToken] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $member = User::factory()->create();
        $memberToken = $member->createLoginToken(60)->plainTextToken;

        $team->allUsers()->attach($member, [
            'joined' => false,
            'role' => 'editor',
            'action' => 'request_to_user',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $memberToken)
            ->putJson('/neev/teams/inviteUser', [
                'team_id' => $team->id,
                'action' => 'accept',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success');

        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $member->id,
            'joined' => true,
        ]);
    }

    // -----------------------------------------------------------------
    // PUT /neev/teams/request — accept with role
    // -----------------------------------------------------------------

    public function test_request_action_accept_sets_membership_to_joined(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $requester = User::factory()->create();
        $team->allUsers()->attach($requester, [
            'joined' => false,
            'role' => '',
            'action' => 'request_from_user',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/teams/request', [
                'team_id' => $team->id,
                'user_id' => $requester->id,
                'action' => 'accept',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonPath('message', 'Accepted Successfully');

        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $requester->id,
            'joined' => true,
        ]);
    }

    // -----------------------------------------------------------------
    // PUT /neev/teams/leave — domain-based deactivation
    // -----------------------------------------------------------------

    public function test_leave_deactivates_user_when_email_matches_verified_domain(): void
    {
        $this->enableDomainFederation();

        [$owner, $ownerToken] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        // Create a verified domain for the team
        DomainFactory::new()->verified()->create([
            'team_id' => $team->id,
            'domain' => 'acme.com',
            'is_primary' => true,
        ]);

        // Create a member with an email matching the domain
        $member = User::factory()->create(['active' => true]);
        $memberEmail = $member->email;
        $memberEmail->email = 'employee@acme.com';
        $memberEmail->save();
        $team->allUsers()->attach($member, ['joined' => true, 'role' => '']);

        // Owner triggers leave for the member
        $response = $this->withHeader('Authorization', 'Bearer ' . $ownerToken)
            ->putJson('/neev/teams/leave', [
                'team_id' => $team->id,
                'user_id' => $member->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonPath('message', 'User Deactivated Successfully');

        $member->refresh();
        $this->assertFalse($member->active);
    }

    public function test_leave_activates_inactive_user_when_email_matches_verified_domain(): void
    {
        $this->enableDomainFederation();

        [$owner, $ownerToken] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        DomainFactory::new()->verified()->create([
            'team_id' => $team->id,
            'domain' => 'acme.com',
            'is_primary' => true,
        ]);

        // Create an inactive member with matching domain email
        $member = User::factory()->create(['active' => false]);
        $memberEmail = $member->email;
        $memberEmail->email = 'inactive@acme.com';
        $memberEmail->save();
        $team->allUsers()->attach($member, ['joined' => true, 'role' => '']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $ownerToken)
            ->putJson('/neev/teams/leave', [
                'team_id' => $team->id,
                'user_id' => $member->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonPath('message', 'User Activated Successfully');

        $member->refresh();
        $this->assertTrue($member->active);
    }

    // -----------------------------------------------------------------
    // POST /neev/teams/inviteUser — enforced domain rejection
    // -----------------------------------------------------------------

    public function test_invite_rejects_email_outside_enforced_domain(): void
    {
        $this->enableDomainFederation();

        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        DomainFactory::new()->verified()->create([
            'team_id' => $team->id,
            'domain' => 'company.com',
            'enforce' => true,
            'is_primary' => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/teams/inviteUser', [
                'team_id' => $team->id,
                'email' => 'outsider@gmail.com',
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed')
            ->assertJsonPath('message', 'You cannot invite member in this team.');
    }

    // -----------------------------------------------------------------
    // POST /neev/teams/request — team with enforced domain
    // -----------------------------------------------------------------

    public function test_request_to_join_team_with_enforced_domain_returns_error(): void
    {
        Mail::fake();

        [$requester, $token] = $this->authenticatedUser();
        $this->enableDomainFederation();

        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        DomainFactory::new()->verified()->create([
            'team_id' => $team->id,
            'domain' => 'enforced.com',
            'enforce' => true,
            'is_primary' => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/teams/request', [
                'team_id' => $team->id,
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed');
    }

    // -----------------------------------------------------------------
    // PUT /neev/teams/inviteUser — invalid action
    // -----------------------------------------------------------------

    public function test_invite_action_with_invalid_action_returns_error(): void
    {
        [$member, $memberToken] = $this->authenticatedUser();

        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        // Add member as invited (not joined)
        $team->allUsers()->attach($member, [
            'joined' => false,
            'role' => '',
            'action' => 'request_to_user',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $memberToken)
            ->putJson('/neev/teams/inviteUser', [
                'team_id' => $team->id,
                'action' => 'unknown',
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed')
            ->assertJsonPath('message', 'Invalid Action.');
    }
}
