<?php

namespace Ssntpl\Neev\Tests\Feature\Teams;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Ssntpl\Neev\Database\Factories\DomainFactory;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Mail\TeamInvitation;
use Ssntpl\Neev\Mail\TeamJoinRequest;
use Ssntpl\Neev\Models\Membership;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class MembershipTest extends TestCase
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
        $memberEmail = $member->email;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/teams/inviteUser', [
                'team_id' => $team->id,
                'email' => $memberEmail,
            ]);

        $response->assertOk();

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

        $response->assertOk();

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

        $response->assertStatus(400);

        Mail::assertNothingSent();
    }

    public function test_cannot_invite_user_already_on_team(): void
    {
        Mail::fake();

        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $member = User::factory()->create();
        $team->allUsers()->attach($member, ['joined' => true]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/teams/inviteUser', [
                'team_id' => $team->id,
                'email' => $member->email,
            ]);

        $response->assertStatus(400);
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
            'action' => 'request_to_user',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $memberToken)
            ->putJson('/neev/teams/inviteUser', [
                'team_id' => $team->id,
                'action' => 'accept',
            ]);

        $response->assertOk();

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
            'action' => 'request_to_user',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $memberToken)
            ->putJson('/neev/teams/inviteUser', [
                'team_id' => $team->id,
                'action' => 'reject',
            ]);

        $response->assertOk();

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

        $team->allUsers()->attach($member, ['joined' => true]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $memberToken)
            ->putJson('/neev/teams/leave', [
                'team_id' => $team->id,
            ]);

        $response->assertOk();

        $team->refresh();
        $this->assertFalse($team->users->contains($member));
    }

    public function test_owner_cannot_leave_team(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->allUsers()->attach($owner, ['joined' => true]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/teams/leave', [
                'team_id' => $team->id,
            ]);

        $response->assertStatus(403);

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

        $response->assertOk();

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
        $team->allUsers()->attach($requester, ['joined' => true]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/teams/request', [
                'team_id' => $team->id,
            ]);

        $response->assertStatus(400);
    }

    // -----------------------------------------------------------------
    // PUT /neev/teams/request — accept/decline join request
    // -----------------------------------------------------------------

    public function test_owner_can_accept_join_request(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->addMember($owner);

        $requester = User::factory()->create();

        // Simulate a pending join request
        $team->allUsers()->attach($requester, [
            'joined' => false,
            'action' => 'request_from_user',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/teams/request', [
                'team_id' => $team->id,
                'user_id' => $requester->id,
                'action' => 'accept',
            ]);

        $response->assertOk();

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
        $team->addMember($owner);

        $requester = User::factory()->create();

        $team->allUsers()->attach($requester, [
            'joined' => false,
            'action' => 'request_from_user',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/teams/request', [
                'team_id' => $team->id,
                'user_id' => $requester->id,
                'action' => 'reject',
            ]);

        $response->assertOk();

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
        $response->assertStatus(400);
    }

    /**
     * The attack the invitation secret exists to stop, by its other door.
     * Registration hands out a login token for an address straight away, and
     * nothing about the address is proven yet — so accepting an invitation in
     * its name has to require the same proof the emailed link carries. A
     * verified address is that proof; an unverified one is a string somebody
     * typed.
     */
    public function test_an_unverified_address_cannot_accept_its_invitation(): void
    {
        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $invited = 'hr@victimcorp.test';
        $invitation = $team->invitations()->create([
            'email' => $invited,
            'role' => 'member',
            'token' => \Ssntpl\Neev\Models\TeamInvitation::generateToken(),
            'expires_at' => now()->addDays(7),
        ]);

        // Anyone may register an address they do not control.
        $impostor = User::factory()->unverified()->create(['email' => $invited]);
        $impostorToken = $impostor->createLoginToken(60)->plainTextToken;

        // They are not even told what it was invited to.
        $this->withHeader('Authorization', 'Bearer ' . $impostorToken)
            ->getJson('/neev/teams/invitations')
            ->assertOk()
            ->assertJsonCount(0, 'data.invitations');

        $this->withHeader('Authorization', 'Bearer ' . $impostorToken)
            ->putJson('/neev/teams/inviteUser', [
                'invitation_id' => $invitation->id,
                'action' => 'accept',
            ])
            ->assertStatus(400);

        $this->assertFalse($team->fresh()->allUsers->contains($impostor));
        $this->assertDatabaseHas('team_invitations', ['id' => $invitation->id]);
    }

    /** Nor delete it out from under the real invitee. */
    public function test_an_unverified_address_cannot_reject_its_invitation(): void
    {
        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $invitation = $team->invitations()->create([
            'email' => 'hr@victimcorp.test',
            'token' => \Ssntpl\Neev\Models\TeamInvitation::generateToken(),
            'expires_at' => now()->addDays(7),
        ]);

        $impostor = User::factory()->unverified()->create(['email' => 'hr@victimcorp.test']);
        $token = $impostor->createLoginToken(60)->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/teams/inviteUser', [
                'invitation_id' => $invitation->id,
                'action' => 'reject',
            ])
            ->assertStatus(400);

        $this->assertDatabaseHas('team_invitations', ['id' => $invitation->id]);
    }

    /** A verified invitee accepts from their own teams page, no link needed. */
    public function test_a_verified_invitee_accepts_their_invitation(): void
    {
        [$invitee, $token] = $this->authenticatedUser();
        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $invitation = $team->invitations()->create([
            'email' => $invitee->email,
            'token' => \Ssntpl\Neev\Models\TeamInvitation::generateToken(),
            'expires_at' => now()->addDays(7),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/teams/invitations')
            ->assertOk()
            ->assertJsonCount(1, 'data.invitations');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/teams/inviteUser', [
                'invitation_id' => $invitation->id,
                'action' => 'accept',
            ])
            ->assertOk();

        $this->assertTrue($team->fresh()->allUsers->contains($invitee));
    }

    /** The deadline the mail promises binds this path too. */
    public function test_an_expired_invitation_cannot_be_accepted_and_is_not_listed(): void
    {
        [$invitee, $token] = $this->authenticatedUser();
        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $invitation = $team->invitations()->create([
            'email' => $invitee->email,
            'token' => \Ssntpl\Neev\Models\TeamInvitation::generateToken(),
            'expires_at' => now()->subMinute(),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/teams/invitations')
            ->assertOk()
            ->assertJsonCount(0, 'data.invitations');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/teams/inviteUser', [
                'invitation_id' => $invitation->id,
                'action' => 'accept',
            ])
            ->assertStatus(400)
            ->assertJsonPath('message', 'This invitation has expired.');

        $this->assertFalse($team->fresh()->allUsers->contains($invitee));
    }

    /**
     * The whole invitation round trip through the controller: what is mailed
     * redeems, what is stored is only a hash, and neither the plaintext nor
     * the hash comes back in the response.
     */
    public function test_the_mailed_invitation_link_is_what_redeems(): void
    {
        Mail::fake();

        [$owner, $ownerToken] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $this->withHeader('Authorization', 'Bearer ' . $ownerToken)
            ->postJson('/neev/teams/inviteUser', [
                'team_id' => $team->id,
                'email' => 'newcomer@example.com',
            ])
            ->assertOk()
            ->assertJsonMissingPath('data.token');

        $mailedUrl = null;
        Mail::assertSent(TeamInvitation::class, function (TeamInvitation $mail) use (&$mailedUrl) {
            $mailedUrl = $mail->url;

            return $mail->hasTo('newcomer@example.com');
        });
        $this->assertNotNull($mailedUrl);

        parse_str((string) parse_url($mailedUrl, PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('token', $query, 'The link carries the secret.');
        $plainToken = $query['token'];

        $row = \Ssntpl\Neev\Models\TeamInvitation::where('email', 'newcomer@example.com')->firstOrFail();
        $this->assertNotSame($plainToken, $row->getAttributes()['token'], 'Only the hash is stored.');
        $this->assertTrue($row->tokenMatches($plainToken));

        $this->postJson('/neev/register', [
            'name' => 'Newcomer',
            'email' => 'newcomer@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'invitation_id' => $row->id,
            'token' => $plainToken,
        ])->assertOk();

        $this->assertTrue($team->fresh()->users->contains(User::where('email', 'newcomer@example.com')->first()));
        $this->assertDatabaseMissing('team_invitations', ['id' => $row->id]);
    }

    /** Re-inviting replaces the secret, so the earlier link stops working. */
    public function test_re_inviting_invalidates_the_previous_link(): void
    {
        Mail::fake();

        [$owner, $ownerToken] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $urls = [];
        foreach ([1, 2] as $round) {
            $this->withHeader('Authorization', 'Bearer ' . $ownerToken)
                ->postJson('/neev/teams/inviteUser', [
                    'team_id' => $team->id,
                    'email' => 'twice@example.com',
                ])->assertOk();
        }

        Mail::assertSent(TeamInvitation::class, function (TeamInvitation $mail) use (&$urls) {
            $urls[] = $mail->url;

            return true;
        });
        $this->assertCount(2, $urls);

        parse_str((string) parse_url($urls[0], PHP_URL_QUERY), $first);
        $row = \Ssntpl\Neev\Models\TeamInvitation::where('email', 'twice@example.com')->firstOrFail();

        $this->assertFalse($row->tokenMatches($first['token']), 'The first secret is dead.');

        $this->postJson('/neev/register', [
            'name' => 'Twice',
            'email' => 'twice@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'invitation_id' => $row->id,
            'token' => $first['token'],
        ])->assertStatus(400);
    }

    // -----------------------------------------------------------------
    // PUT /neev/teams/leave — revoke invitation
    // -----------------------------------------------------------------

    public function test_member_can_revoke_invitation(): void
    {
        [$member, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create();
        $team->addMember($member);

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
            ->assertJsonPath('message', 'Invitation Revoked Successfully');

        $this->assertDatabaseMissing('team_invitations', ['id' => $invitation->id]);
    }

    /**
     * The invitee has not joined, so membership cannot be the test for them
     * declining their own invitation.
     */
    public function test_invitee_can_decline_their_own_invitation(): void
    {
        [$invitee, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create();

        $invitation = $team->invitations()->create([
            'email' => $invitee->email,
            'role' => 'member',
            'expires_at' => now()->addDays(7),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/teams/leave', [
                'team_id' => $team->id,
                'invitation_id' => $invitation->id,
            ])
            ->assertOk();

        $this->assertDatabaseMissing('team_invitations', ['id' => $invitation->id]);
    }

    /**
     * Revoking an invitation is its own action — the subject is the
     * invitation, not a member — so the "the owner cannot be removed" rule
     * must not reach it.
     */
    public function test_owner_can_revoke_an_invitation(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->addMember($owner);

        $invitation = $team->invitations()->create([
            'email' => 'invitee@example.com',
            'role' => 'member',
            'expires_at' => now()->addDays(7),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/teams/leave', [
                'team_id' => $team->id,
                'invitation_id' => $invitation->id,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Invitation Revoked Successfully');

        $this->assertDatabaseMissing('team_invitations', ['id' => $invitation->id]);
    }

    /**
     * Knowing an invitation id is not authority to cancel it. Only a member of
     * the team, or the person it was addressed to, may.
     */
    public function test_outsider_cannot_revoke_an_invitation(): void
    {
        [$outsider, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create();

        $invitation = $team->invitations()->create([
            'email' => 'invitee@example.com',
            'role' => 'member',
            'expires_at' => now()->addDays(7),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/teams/leave', [
                'team_id' => $team->id,
                'invitation_id' => $invitation->id,
            ])
            ->assertStatus(403);

        $this->assertDatabaseHas('team_invitations', ['id' => $invitation->id]);
    }

    public function test_revoke_nonexistent_invitation_returns_error(): void
    {
        [$member, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create();
        $team->addMember($member);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/teams/leave', [
                'team_id' => $team->id,
                'invitation_id' => 99999,
            ]);

        $response->assertStatus(400);
    }

    // -----------------------------------------------------------------
    // PUT /neev/teams/request — invalid action
    // -----------------------------------------------------------------

    public function test_request_action_with_invalid_action_returns_error(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->addMember($owner);

        $requester = User::factory()->create();
        $team->allUsers()->attach($requester, [
            'joined' => false,
            'action' => 'request_from_user',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/teams/request', [
                'team_id' => $team->id,
                'user_id' => $requester->id,
                'action' => 'invalid',
            ]);

        $response->assertStatus(400)
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
            'action' => 'request_to_user',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $memberToken)
            ->putJson('/neev/teams/inviteUser', [
                'team_id' => $team->id,
                'action' => 'accept',
            ]);

        $response->assertOk();

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
        $team->addMember($owner);

        $requester = User::factory()->create();
        $team->allUsers()->attach($requester, [
            'joined' => false,
            'action' => 'request_from_user',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/teams/request', [
                'team_id' => $team->id,
                'user_id' => $requester->id,
                'action' => 'accept',
            ]);

        $response->assertOk()
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
        $team->addMember($owner);

        // Create a verified domain for the team
        DomainFactory::new()->verified()->create([
            'owner_type' => 'team', 'owner_id' => $team->id,
            'domain' => 'acme.com',
            'is_primary' => true,
        ]);

        // Create a member with an email matching the domain
        $member = User::factory()->create(['active' => true, 'email' => 'employee@acme.com']);
        $team->allUsers()->attach($member, ['joined' => true]);

        // Owner triggers leave for the member
        $response = $this->withHeader('Authorization', 'Bearer ' . $ownerToken)
            ->putJson('/neev/teams/leave', [
                'team_id' => $team->id,
                'user_id' => $member->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'User Deactivated Successfully');

        $member->refresh();
        $this->assertFalse($member->active);
    }

    public function test_leave_deactivates_user_on_a_non_primary_verified_domain(): void
    {
        $this->enableDomainFederation();

        [$owner, $ownerToken] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->addMember($owner);

        DomainFactory::new()->verified()->create([
            'owner_type' => 'team', 'owner_id' => $team->id,
            'domain' => 'acme.com',
            'is_primary' => true,
        ]);
        DomainFactory::new()->verified()->create([
            'owner_type' => 'team', 'owner_id' => $team->id,
            'domain' => 'acme.io',
            'is_primary' => false,
        ]);

        $member = User::factory()->create(['active' => true, 'email' => 'employee@acme.io']);
        $team->allUsers()->attach($member, ['joined' => true]);

        $this->withHeader('Authorization', 'Bearer ' . $ownerToken)
            ->putJson('/neev/teams/leave', [
                'team_id' => $team->id,
                'user_id' => $member->id,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'User Deactivated Successfully');

        $this->assertFalse($member->fresh()->active);
        $this->assertTrue($team->allUsers()->whereKey($member->id)->exists());
    }

    public function test_member_on_a_verified_domain_cannot_leave_and_deactivate_themselves(): void
    {
        $this->enableDomainFederation();

        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        DomainFactory::new()->create([
            'owner_type' => 'team', 'owner_id' => $team->id,
            'domain' => 'acme.com',
            'is_primary' => true,
        ]);
        DomainFactory::new()->verified()->create([
            'owner_type' => 'team', 'owner_id' => $team->id,
            'domain' => 'acme.io',
            'is_primary' => false,
        ]);

        [$member, $token] = $this->authenticatedUser();
        $member->update(['active' => true, 'email' => 'employee@acme.io']);
        $team->allUsers()->attach($member, ['joined' => true]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/teams/leave', [
                'team_id' => $team->id,
                'user_id' => $member->id,
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'You cannot leave a team your email domain manages.');

        $this->assertTrue($member->fresh()->active);
        $this->assertTrue($team->allUsers()->whereKey($member->id)->exists());
    }

    public function test_leave_refuses_to_deactivate_a_user_who_is_not_a_member(): void
    {
        $this->enableDomainFederation();

        [$owner, $ownerToken] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->addMember($owner);

        DomainFactory::new()->verified()->create([
            'owner_type' => 'team', 'owner_id' => $team->id,
            'domain' => 'acme.com',
            'is_primary' => true,
        ]);

        // On the team's verified domain, but never joined this team.
        $outsider = User::factory()->create(['active' => true, 'email' => 'someone@acme.com']);

        $this->withHeader('Authorization', 'Bearer ' . $ownerToken)
            ->putJson('/neev/teams/leave', [
                'team_id' => $team->id,
                'user_id' => $outsider->id,
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'You cannot perform this action on this team.');

        $this->assertTrue($outsider->fresh()->active);
    }

    public function test_leave_withdraws_a_pending_invitation_without_deactivating(): void
    {
        $this->enableDomainFederation();

        [$owner, $ownerToken] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->addMember($owner);

        DomainFactory::new()->verified()->create([
            'owner_type' => 'team', 'owner_id' => $team->id,
            'domain' => 'acme.com',
            'is_primary' => true,
        ]);

        // Invited, not yet joined, on the team's verified domain.
        $invitee = User::factory()->create(['active' => true, 'email' => 'invitee@acme.com']);
        $team->addMember($invitee, joined: false, action: Membership::REQUEST_TO_USER);

        $this->withHeader('Authorization', 'Bearer ' . $ownerToken)
            ->putJson('/neev/teams/leave', [
                'team_id' => $team->id,
                'user_id' => $invitee->id,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Removed Successfully');

        $this->assertFalse($team->allUsers()->whereKey($invitee->id)->exists());
        $this->assertTrue($invitee->fresh()->active);
    }

    public function test_leave_lets_a_user_withdraw_their_own_join_request(): void
    {
        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        [$requester, $token] = $this->authenticatedUser();
        $team->addMember($requester, joined: false, action: Membership::REQUEST_FROM_USER);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/teams/leave', ['team_id' => $team->id])
            ->assertOk()
            ->assertJsonPath('message', 'Removed Successfully');

        $this->assertFalse($team->allUsers()->whereKey($requester->id)->exists());
    }

    public function test_leave_refuses_to_withdraw_someone_elses_pending_membership_for_a_non_member(): void
    {
        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $requester = User::factory()->create();
        $team->addMember($requester, joined: false, action: Membership::REQUEST_FROM_USER);

        [, $strangerToken] = $this->authenticatedUser();

        $this->withHeader('Authorization', 'Bearer ' . $strangerToken)
            ->putJson('/neev/teams/leave', [
                'team_id' => $team->id,
                'user_id' => $requester->id,
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'You cannot perform this action on this team.');

        $this->assertTrue($team->allUsers()->whereKey($requester->id)->exists());
    }

    public function test_join_request_is_refused_when_a_non_primary_verified_domain_is_enforced(): void
    {
        Mail::fake();
        $this->enableDomainFederation();

        [$requester, $token] = $this->authenticatedUser();
        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id, 'is_public' => true]);

        DomainFactory::new()->create([
            'owner_type' => 'team', 'owner_id' => $team->id,
            'domain' => 'acme.com',
            'is_primary' => true,
        ]);
        DomainFactory::new()->verified()->create([
            'owner_type' => 'team', 'owner_id' => $team->id,
            'domain' => 'acme.io',
            'is_primary' => false,
            'enforce' => true,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/teams/request', ['team_id' => $team->id])
            ->assertStatus(400);

        $this->assertFalse($team->allUsers()->whereKey($requester->id)->exists());
        Mail::assertNothingSent();
    }

    public function test_leave_removes_user_on_an_unverified_domain(): void
    {
        $this->enableDomainFederation();

        [$owner, $ownerToken] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->addMember($owner);

        DomainFactory::new()->verified()->create([
            'owner_type' => 'team', 'owner_id' => $team->id,
            'domain' => 'acme.com',
            'is_primary' => true,
        ]);
        DomainFactory::new()->create([
            'owner_type' => 'team', 'owner_id' => $team->id,
            'domain' => 'acme.io',
        ]);

        $member = User::factory()->create(['active' => true, 'email' => 'employee@acme.io']);
        $team->allUsers()->attach($member, ['joined' => true]);

        $this->withHeader('Authorization', 'Bearer ' . $ownerToken)
            ->putJson('/neev/teams/leave', [
                'team_id' => $team->id,
                'user_id' => $member->id,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Removed Successfully');

        $this->assertTrue($member->fresh()->active);
        $this->assertFalse($team->allUsers()->whereKey($member->id)->exists());
    }

    public function test_leave_activates_inactive_user_when_email_matches_verified_domain(): void
    {
        $this->enableDomainFederation();

        [$owner, $ownerToken] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->addMember($owner);

        DomainFactory::new()->verified()->create([
            'owner_type' => 'team', 'owner_id' => $team->id,
            'domain' => 'acme.com',
            'is_primary' => true,
        ]);

        // Create an inactive member with matching domain email
        $member = User::factory()->create(['active' => false, 'email' => 'inactive@acme.com']);
        $team->allUsers()->attach($member, ['joined' => true]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $ownerToken)
            ->putJson('/neev/teams/leave', [
                'team_id' => $team->id,
                'user_id' => $member->id,
            ]);

        $response->assertOk()
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
            'owner_type' => 'team', 'owner_id' => $team->id,
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
            ->assertJsonPath('message', 'You cannot invite member in this team.');
    }

    public function test_invite_rejects_email_outside_an_enforced_non_primary_domain(): void
    {
        $this->enableDomainFederation();

        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        DomainFactory::new()->verified()->create([
            'owner_type' => 'team', 'owner_id' => $team->id,
            'domain' => 'company.com',
            'enforce' => false,
            'is_primary' => true,
        ]);
        DomainFactory::new()->verified()->create([
            'owner_type' => 'team', 'owner_id' => $team->id,
            'domain' => 'company.io',
            'enforce' => true,
            'is_primary' => false,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/teams/inviteUser', [
                'team_id' => $team->id,
                'email' => 'outsider@gmail.com',
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'You cannot invite member in this team.');
    }

    public function test_invite_accepts_email_on_another_verified_domain_of_the_team(): void
    {
        Mail::fake();
        $this->enableDomainFederation();

        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        DomainFactory::new()->verified()->create([
            'owner_type' => 'team', 'owner_id' => $team->id,
            'domain' => 'company.com',
            'enforce' => true,
            'is_primary' => true,
        ]);
        DomainFactory::new()->verified()->create([
            'owner_type' => 'team', 'owner_id' => $team->id,
            'domain' => 'company.io',
            'enforce' => false,
            'is_primary' => false,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/teams/inviteUser', [
                'team_id' => $team->id,
                'email' => 'new.hire@company.io',
            ]);

        $response->assertOk();
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
            'owner_type' => 'team', 'owner_id' => $team->id,
            'domain' => 'enforced.com',
            'enforce' => true,
            'is_primary' => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/teams/request', [
                'team_id' => $team->id,
            ]);

        $response->assertStatus(400);
    }

    public function test_request_to_join_is_accepted_when_the_enforced_domain_is_unverified(): void
    {
        Mail::fake();

        [$requester, $token] = $this->authenticatedUser();
        $this->enableDomainFederation();

        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        // An unverified claim proves nothing, so its enforce flag closes nothing.
        DomainFactory::new()->create([
            'owner_type' => 'team', 'owner_id' => $team->id,
            'domain' => 'enforced.com',
            'enforce' => true,
            'is_primary' => true,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/teams/request', [
                'team_id' => $team->id,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Request sent successfully.');

        $this->assertTrue($team->joinRequests()->whereKey($requester->id)->exists());
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
            'action' => 'request_to_user',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $memberToken)
            ->putJson('/neev/teams/inviteUser', [
                'team_id' => $team->id,
                'action' => 'unknown',
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Invalid Action.');
    }

    // -----------------------------------------------------------------
    // Rejecting reaches pending memberships only
    // -----------------------------------------------------------------

    /**
     * Rejecting an invitation by team_id is for one not yet accepted. A joined
     * member on a verified domain, refused by leave(), must not get out this way.
     */
    public function test_rejecting_an_invitation_does_not_remove_a_joined_member(): void
    {
        $this->enableDomainFederation();

        [$owner] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->addMember($owner);
        DomainFactory::new()->verified()->primary()->create([
            'owner_type' => 'team', 'owner_id' => $team->id,
            'domain' => 'acme.com',
        ]);
        $member = User::factory()->create(['email' => 'employee@acme.com']);
        $team->addMember($member);
        $token = $member->createLoginToken(60)->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/teams/inviteUser', ['team_id' => $team->id, 'action' => 'reject'])
            ->assertStatus(400)
            ->assertJsonPath('message', 'Invitation not found');

        $this->assertTrue($team->refresh()->hasMember($member));
    }

    public function test_rejecting_an_invitation_for_a_missing_team_is_refused(): void
    {
        [, $token] = $this->authenticatedUser();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/teams/inviteUser', ['team_id' => 999999, 'action' => 'reject'])
            ->assertStatus(400)
            ->assertJsonPath('message', 'Invitation not found');
    }

    /**
     * Rejecting a join request must not remove a joined member: that is
     * leave()'s job, which keeps the owner and deactivates a member the
     * team's domain manages.
     */
    public function test_rejecting_a_request_does_not_remove_a_joined_member_or_the_owner(): void
    {
        $this->enableDomainFederation();

        [$owner] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->addMember($owner);
        DomainFactory::new()->verified()->primary()->create([
            'owner_type' => 'team', 'owner_id' => $team->id,
            'domain' => 'acme.com',
        ]);
        [$member, $token] = $this->authenticatedUser();
        $team->addMember($member);
        $colleague = User::factory()->create(['email' => 'colleague@acme.com']);
        $team->addMember($colleague);

        foreach ([$colleague, $owner] as $subject) {
            $this->withHeader('Authorization', 'Bearer ' . $token)
                ->putJson('/neev/teams/request', [
                    'team_id' => $team->id,
                    'user_id' => $subject->id,
                    'action' => 'reject',
                ])
                ->assertStatus(400)
                ->assertJsonPath('message', 'Request not found');
        }

        $team->refresh();
        $this->assertTrue($team->hasMember($colleague));
        $this->assertTrue($team->hasMember($owner));
    }

    /**
     * Once a new token unverifies the domain it manages nobody, so removing a
     * member deactivated through it removes them like anyone else — and gives
     * their account back, rather than leaving it locked application-wide.
     */
    public function test_removing_a_member_after_a_new_token_unverifies_the_domain_detaches_and_reactivates_them(): void
    {
        $this->enableDomainFederation();

        [$owner, $ownerToken] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->addMember($owner);
        $domain = DomainFactory::new()->verified()->primary()->create([
            'owner_type' => 'team', 'owner_id' => $team->id,
            'domain' => 'acme.com',
        ]);
        $member = User::factory()->create(['active' => true, 'email' => 'employee@acme.com']);
        $team->addMember($member);

        $this->withHeader('Authorization', 'Bearer ' . $ownerToken)
            ->putJson('/neev/teams/leave', ['team_id' => $team->id, 'user_id' => $member->id])
            ->assertJsonPath('message', 'User Deactivated Successfully');

        $this->withHeader('Authorization', 'Bearer ' . $ownerToken)
            ->putJson('/neev/domains', ['domain_id' => $domain->id, 'token' => true])
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer ' . $ownerToken)
            ->putJson('/neev/teams/leave', ['team_id' => $team->id, 'user_id' => $member->id])
            ->assertOk()
            ->assertJsonPath('message', 'Removed Successfully');

        $this->assertTrue($member->fresh()->active);
        $this->assertFalse($team->refresh()->hasMember($member));
    }
}
