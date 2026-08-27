<?php

namespace Ssntpl\Neev\Tests\Feature\Teams;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Models\Membership;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

/**
 * The Blade team pages and actions gate on membership: belonging to the team
 * is what lets you see or change it, and being signed in is not enough. These
 * cover both sides of each gate.
 */
class TeamWebAuthorizationTest extends TestCase
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

    /** A team whose owner is also attached as a member, as every create path does. */
    protected function teamWithOwner(): array
    {
        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->addMember($owner);

        return [$team, $owner];
    }

    // -----------------------------------------------------------------
    // Pages
    // -----------------------------------------------------------------

    /** The pages a member may open, and an outsider may not. */
    protected function assertPageIsMemberOnly(string $route): void
    {
        $this->enableDomainFederation();

        [$team, $owner] = $this->teamWithOwner();
        $outsider = User::factory()->create();

        $this->actingAs($owner)
            ->get(route($route, ['team' => $team->id]))
            ->assertOk()
            ->assertSessionHasNoErrors();

        $this->actingAs($outsider)
            ->from(config('neev.home'))
            ->get(route($route, ['team' => $team->id]))
            ->assertRedirect(config('neev.home'))
            ->assertSessionHasErrors('message');
    }

    public function test_the_members_page_is_member_only(): void
    {
        $this->assertPageIsMemberOnly('teams.members');
    }

    public function test_the_settings_page_is_member_only(): void
    {
        $this->assertPageIsMemberOnly('teams.settings');
    }

    public function test_the_domain_federation_page_is_member_only(): void
    {
        $this->assertPageIsMemberOnly('teams.domain');
    }

    // -----------------------------------------------------------------
    // PUT /neev/teams/update
    // -----------------------------------------------------------------

    public function test_a_member_can_rename_the_team(): void
    {
        [$team, $owner] = $this->teamWithOwner();

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->put(route('teams.update'), [
                'team_id' => $team->id,
                'name' => 'Renamed',
                'public' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $team->refresh()->name);
    }

    public function test_an_outsider_cannot_rename_the_team(): void
    {
        [$team] = $this->teamWithOwner();
        $original = $team->name;
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->from(config('neev.home'))
            ->put(route('teams.update'), [
                'team_id' => $team->id,
                'name' => 'Hijacked',
            ])
            ->assertSessionHasErrors('message');

        $this->assertSame($original, $team->refresh()->name);
    }

    /**
     * The guard answers the same way for a team that does not exist, so the
     * form never confirms which team ids are real.
     */
    public function test_updating_a_missing_team_is_refused_without_an_error_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(config('neev.home'))
            ->put(route('teams.update'), ['team_id' => 99999, 'name' => 'X'])
            ->assertRedirect(config('neev.home'))
            ->assertSessionHasErrors('message');
    }

    // -----------------------------------------------------------------
    // PUT /neev/teams/members/request/action
    // -----------------------------------------------------------------

    public function test_a_member_can_accept_a_join_request(): void
    {
        [$team, $owner] = $this->teamWithOwner();

        $requester = User::factory()->create();
        $team->allUsers()->attach($requester, ['joined' => false, 'action' => 'request_from_user']);

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->put(route('teams.request.action'), [
                'team_id' => $team->id,
                'user_id' => $requester->id,
                'action' => 'accept',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue($team->refresh()->hasMember($requester));
    }

    public function test_an_outsider_cannot_accept_a_join_request(): void
    {
        [$team] = $this->teamWithOwner();
        $outsider = User::factory()->create();

        $requester = User::factory()->create();
        $team->allUsers()->attach($requester, ['joined' => false, 'action' => 'request_from_user']);

        $this->actingAs($outsider)
            ->from(config('neev.home'))
            ->put(route('teams.request.action'), [
                'team_id' => $team->id,
                'user_id' => $requester->id,
                'action' => 'accept',
            ])
            ->assertSessionHasErrors('message');

        $this->assertFalse($team->refresh()->hasMember($requester));
    }

    // -----------------------------------------------------------------
    // PUT /neev/teams/members/invite/action
    // -----------------------------------------------------------------

    /**
     * An invitation is addressed to one inbox, so only the account holding
     * that address may accept or reject it.
     */
    public function test_only_the_invited_address_can_act_on_an_invitation(): void
    {
        [$team] = $this->teamWithOwner();

        $invitee = User::factory()->create();
        $stranger = User::factory()->create();

        $invitation = $team->invitations()->create([
            'email' => $invitee->email,
            'role' => 'member',
            'expires_at' => now()->addDays(7),
        ]);

        $this->actingAs($stranger)
            ->from(config('neev.home'))
            ->put(route('teams.invite.action'), [
                'invitation_id' => $invitation->id,
                'action' => 'reject',
            ])
            ->assertSessionHasErrors('message');

        $this->assertDatabaseHas('team_invitations', ['id' => $invitation->id]);

        $this->actingAs($invitee)
            ->from(config('neev.home'))
            ->put(route('teams.invite.action'), [
                'invitation_id' => $invitation->id,
                'action' => 'reject',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('team_invitations', ['id' => $invitation->id]);
    }

    // -----------------------------------------------------------------
    // PUT /neev/teams/owner/change
    // -----------------------------------------------------------------

    public function test_ownership_can_only_be_handed_to_an_existing_member(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        $outsider = User::factory()->create();

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->put(route('teams.owner.change'), [
                'team_id' => $team->id,
                'user_id' => $outsider->id,
            ])
            ->assertSessionHasErrors('message');

        $this->assertSame($owner->id, $team->refresh()->user_id);
    }

    public function test_ownership_transfers_to_a_member(): void
    {
        [$team, $owner] = $this->teamWithOwner();

        $member = User::factory()->create();
        $team->addMember($member);

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->put(route('teams.owner.change'), [
                'team_id' => $team->id,
                'user_id' => $member->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($member->id, $team->refresh()->user_id);
    }

    // -----------------------------------------------------------------
    // Creating a team makes the owner a member
    // -----------------------------------------------------------------

    /**
     * Owning a team and belonging to it are separate records, and the gates
     * above ask about membership — so creating a team has to do both.
     */
    public function test_creating_a_team_attaches_the_creator_as_a_member(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(config('neev.home'))
            ->post(route('teams.store'), ['name' => 'Fresh Team', 'public' => false])
            ->assertSessionHasNoErrors();

        $team = Team::where('name', 'Fresh Team')->firstOrFail();

        $this->assertSame($user->id, $team->user_id);
        $this->assertTrue($team->hasMember($user));
    }

    // -----------------------------------------------------------------
    // Acting on a join request is the owner's call
    // -----------------------------------------------------------------

    /**
     * Accepting a request admits someone to the team and may hand them a
     * role, which is exactly what inviting does — so it is held to the same
     * bar. Being a member is no longer enough.
     */
    public function test_a_plain_member_cannot_accept_a_join_request(): void
    {
        [$team, $owner] = $this->teamWithOwner();

        $member = User::factory()->create();
        $team->addMember($member);

        $requester = User::factory()->create();
        $team->allUsers()->attach($requester, [
            'joined' => false,
            'action' => Membership::REQUEST_FROM_USER,
        ]);

        $this->actingAs($member)
            ->from(config('neev.home'))
            ->put(route('teams.request.action'), [
                'team_id' => $team->id,
                'user_id' => $requester->id,
                'action' => 'accept',
            ])
            ->assertSessionHasErrors('message');

        $this->assertFalse($team->refresh()->hasMember($requester));
    }

    public function test_a_plain_member_cannot_reject_a_join_request(): void
    {
        [$team, $owner] = $this->teamWithOwner();

        $member = User::factory()->create();
        $team->addMember($member);

        $requester = User::factory()->create();
        $team->allUsers()->attach($requester, [
            'joined' => false,
            'action' => Membership::REQUEST_FROM_USER,
        ]);

        $this->actingAs($member)
            ->from(config('neev.home'))
            ->put(route('teams.request.action'), [
                'team_id' => $team->id,
                'user_id' => $requester->id,
                'action' => 'reject',
            ])
            ->assertSessionHasErrors('message');

        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $requester->id,
            'joined' => false,
        ]);
    }

    /** A team id that does not exist is refused rather than blowing up. */
    public function test_acting_on_a_request_for_a_missing_team_is_refused(): void
    {
        $user = User::factory()->create();
        $requester = User::factory()->create();

        $this->actingAs($user)
            ->from(config('neev.home'))
            ->put(route('teams.request.action'), [
                'team_id' => 99999,
                'user_id' => $requester->id,
                'action' => 'accept',
            ])
            ->assertRedirect(config('neev.home'))
            ->assertSessionHasErrors('message');
    }

    // -----------------------------------------------------------------
    // POST /neev/teams/members/request — naming the team
    // -----------------------------------------------------------------

    public function test_a_join_request_can_name_the_team_by_id(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->from(config('neev.home'))
            ->post(route('teams.request'), ['team_id' => $team->id])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $outsider->id,
            'joined' => false,
            'action' => Membership::REQUEST_FROM_USER,
        ]);
    }

    public function test_a_join_request_can_name_the_team_by_slug(): void
    {
        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id, 'slug' => 'open-team']);
        $team->addMember($owner);
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->from(config('neev.home'))
            ->post(route('teams.request'), ['slug' => 'open-team'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $outsider->id,
            'joined' => false,
            'action' => Membership::REQUEST_FROM_USER,
        ]);
    }

    /**
     * The owner-email plus team-name pair is still accepted, so forms built
     * against the older shape keep working.
     */
    public function test_a_join_request_can_still_name_the_owner_and_team_name(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->from(config('neev.home'))
            ->post(route('teams.request'), [
                'email' => $owner->email,
                'team' => $team->name,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $outsider->id,
            'joined' => false,
        ]);
    }

    public function test_a_join_request_naming_no_team_is_refused(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->from(config('neev.home'))
            ->post(route('teams.request'), [])
            ->assertSessionHasErrors('message');

        $this->assertDatabaseCount('team_user', 0);
    }

    // -----------------------------------------------------------------
    // PUT /neev/teams/members/invite — role failures roll back
    // -----------------------------------------------------------------

    /**
     * Attaching the member and granting the role are one unit of work, so a
     * role name that does not resolve leaves no half-built membership behind
     * and sends no "you're in" mail.
     */
    public function test_inviting_with_an_unknown_role_leaves_no_membership(): void
    {
        Mail::fake();

        [$team, $owner] = $this->teamWithOwner();
        $invitee = User::factory()->create();

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->put(route('teams.invite'), [
                'team_id' => $team->id,
                'email' => $invitee->email,
                'role' => 'no-such-role',
            ])
            ->assertSessionHasErrors('message');

        $this->assertDatabaseMissing('team_user', [
            'team_id' => $team->id,
            'user_id' => $invitee->id,
        ]);
        Mail::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // The team profile page offers a way in
    // -----------------------------------------------------------------

    /**
     * The profile page is the one team page an outsider can open, so it is
     * where the request-to-join action belongs.
     */
    public function test_the_profile_page_offers_an_outsider_a_way_to_request_to_join(): void
    {
        [$team] = $this->teamWithOwner();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->get(route('teams.profile', ['team' => $team->id]))
            ->assertOk()
            ->assertSee('Request to join');
    }

    public function test_the_profile_page_reports_a_request_already_sent(): void
    {
        [$team] = $this->teamWithOwner();
        $requester = User::factory()->create();
        $team->allUsers()->attach($requester, [
            'joined' => false,
            'action' => Membership::REQUEST_FROM_USER,
        ]);

        $response = $this->actingAs($requester)
            ->get(route('teams.profile', ['team' => $team->id]))
            ->assertOk();

        $response->assertSee('Request pending');
        $response->assertDontSee('Request to join');
    }

    /** A member is already in, so neither the button nor the notice appears. */
    public function test_the_profile_page_shows_no_join_action_to_a_member(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        $member = User::factory()->create();
        $team->addMember($member);

        $response = $this->actingAs($member)
            ->get(route('teams.profile', ['team' => $team->id]))
            ->assertOk();

        $response->assertDontSee('Request to join');
        $response->assertDontSee('Request pending');
    }
}
