<?php

namespace Ssntpl\Neev\Tests\Unit\Models;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Models\Membership;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

/**
 * The two directions a pending membership can face used to be bare strings
 * repeated across the models, traits and controllers. They are constants now,
 * and these pin them to the values the `team_user.action` column accepts —
 * a typo in either place would otherwise only surface as a relation that
 * silently returns nothing.
 */
class MembershipTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    public function test_request_to_user_constant(): void
    {
        $this->assertSame('request_to_user', Membership::REQUEST_TO_USER);
    }

    public function test_request_from_user_constant(): void
    {
        $this->assertSame('request_from_user', Membership::REQUEST_FROM_USER);
    }

    public function test_it_uses_the_team_user_table(): void
    {
        $this->assertSame('team_user', (new Membership())->getTable());
    }

    /**
     * `invitedUsers` and `joinRequests` filter on `action`, so a constant that
     * drifted from the stored value would quietly empty both relations.
     */
    public function test_the_constants_are_the_values_the_relations_filter_on(): void
    {
        $this->enableTeams();

        $team = TeamFactory::new()->create();
        $invited = User::factory()->create();
        $requester = User::factory()->create();

        $team->allUsers()->attach($invited, [
            'joined' => false,
            'action' => Membership::REQUEST_TO_USER,
        ]);
        $team->allUsers()->attach($requester, [
            'joined' => false,
            'action' => Membership::REQUEST_FROM_USER,
        ]);

        $this->assertTrue($team->invitedUsers()->where('users.id', $invited->id)->exists());
        $this->assertFalse($team->invitedUsers()->where('users.id', $requester->id)->exists());

        $this->assertTrue($team->joinRequests()->where('users.id', $requester->id)->exists());
        $this->assertFalse($team->joinRequests()->where('users.id', $invited->id)->exists());
    }

    /** The same two values drive the user-side relations. */
    public function test_the_constants_drive_the_user_side_relations(): void
    {
        $this->enableTeams();

        $invitedTo = TeamFactory::new()->create();
        $askedToJoin = TeamFactory::new()->create();
        $user = User::factory()->create();

        $invitedTo->allUsers()->attach($user, [
            'joined' => false,
            'action' => Membership::REQUEST_TO_USER,
        ]);
        $askedToJoin->allUsers()->attach($user, [
            'joined' => false,
            'action' => Membership::REQUEST_FROM_USER,
        ]);

        $this->assertSame([$invitedTo->id], $user->teamRequests()->pluck('teams.id')->all());
        $this->assertSame([$askedToJoin->id], $user->sendRequests()->pluck('teams.id')->all());
    }
}
