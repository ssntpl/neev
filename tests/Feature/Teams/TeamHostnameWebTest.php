<?php

namespace Ssntpl\Neev\Tests\Feature\Teams;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Models\Hostname;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\Support\FakeDns;
use Ssntpl\Neev\Tests\TestCase;

// Must load before any test calls verify(); see the file for why.
require_once __DIR__ . '/../../Support/FakeDns.php';

/**
 * The Blade hostnames page: members see a team's hosts, the owner manages
 * them, and every host is proven by its `_neev-host` record.
 */
class TeamHostnameWebTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The team routes are registered while the providers boot.
        $app['config']->set('neev.team', true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['neev.platform_domain' => 'otper.com']);
        $this->loadMigrationsFrom(dirname(__DIR__, 3) . '/vendor/ssntpl/laravel-acl/database/migrations');
    }

    protected function tearDown(): void
    {
        FakeDns::reset();

        parent::tearDown();
    }

    /**
     * @return array{0: Team, 1: User}
     */
    protected function teamWithOwner(): array
    {
        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id, 'slug' => 'acme']);
        $team->addMember($owner);

        return [$team, $owner];
    }

    public function test_a_member_sees_the_hosts_and_the_platform_subdomain(): void
    {
        [$team] = $this->teamWithOwner();
        $member = User::factory()->create();
        $team->addMember($member);
        $this->verifiedHost($team, 'app.acme.com');

        $this->actingAs($member)
            ->get(route('teams.hostnames', $team->id))
            ->assertOk()
            ->assertSee('app.acme.com')
            ->assertSee('acme.otper.com')
            ->assertSee('Add Host');
    }

    public function test_the_owner_claims_a_host_and_gets_its_record(): void
    {
        [$team, $owner] = $this->teamWithOwner();

        $this->actingAs($owner)
            ->from(route('teams.hostnames', $team->id))
            ->post(route('teams.hostnames.store', $team->id), ['host' => 'App.Acme.com'])
            ->assertSessionHas('dns_record_name', '_neev-host.app.acme.com');

        $hostname = Hostname::forHost('app.acme.com')->sole();
        $this->assertTrue($hostname->isOwnedBy($team));
        $this->assertSame(session('token'), $hostname->verification_token);
        $this->assertFalse($hostname->isVerified());
    }

    public function test_a_host_under_the_platform_zone_or_held_by_another_team_is_refused(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        TeamFactory::new()->create()->claimHost('taken.acme.com');

        foreach (['other.otper.com', 'taken.acme.com'] as $host) {
            $this->actingAs($owner)
                ->from(route('teams.hostnames', $team->id))
                ->post(route('teams.hostnames.store', $team->id), ['host' => $host])
                ->assertSessionHasErrors('message');
        }

        $this->assertSame(0, $team->hostnames()->count());
    }

    /** As on the API, Neev checks membership only; the app's middleware may narrow it. */
    public function test_a_member_can_claim_a_host(): void
    {
        [$team] = $this->teamWithOwner();
        $member = User::factory()->create();
        $team->addMember($member);

        $this->actingAs($member)
            ->from(route('teams.hostnames', $team->id))
            ->post(route('teams.hostnames.store', $team->id), ['host' => 'app.acme.com'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('dns_record_name', '_neev-host.app.acme.com');

        $this->assertDatabaseCount('hostnames', 1);
    }

    public function test_an_outsider_cannot_claim_update_or_delete_a_host(): void
    {
        [$team] = $this->teamWithOwner();
        $outsider = User::factory()->create();
        $hostname = $this->verifiedHost($team, 'app.acme.com');

        $this->actingAs($outsider)
            ->from(route('teams.hostnames', $team->id))
            ->post(route('teams.hostnames.store', $team->id), ['host' => 'other.acme.com'])
            ->assertSessionHasErrors(['message' => 'You do not have the required permissions to add a host.']);
        $this->actingAs($outsider)
            ->from(route('teams.hostnames', $team->id))
            ->put(route('teams.hostnames.update', $hostname->id), ['primary' => 'primary'])
            ->assertSessionHasErrors(['message' => 'You do not have the required permissions to update this host.']);
        $this->actingAs($outsider)
            ->from(route('teams.hostnames', $team->id))
            ->delete(route('teams.hostnames.destroy', $hostname->id))
            ->assertSessionHasErrors(['message' => 'You do not have the required permissions to delete this host.']);

        $this->assertDatabaseCount('hostnames', 1);
        $this->assertNull($team->fresh()->primary_hostname_id);
    }

    public function test_the_owner_verifies_a_host_by_its_record(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        $hostname = $team->claimHost('app.acme.com');
        FakeDns::txt('_neev-host.app.acme.com', $hostname->verification_token);

        $this->actingAs($owner)
            ->from(route('teams.hostnames', $team->id))
            ->put(route('teams.hostnames.update', $hostname->id), ['verify' => 'verify'])
            ->assertSessionHas('status', 'Host verified successfully!');

        $this->assertTrue($hostname->fresh()->isVerified());
    }

    public function test_the_owner_makes_a_verified_host_primary_but_not_a_pending_one(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        $verified = $this->verifiedHost($team, 'app.acme.com');
        $pending = $team->claimHost('eu.acme.com');

        $this->actingAs($owner)
            ->from(route('teams.hostnames', $team->id))
            ->put(route('teams.hostnames.update', $pending->id), ['primary' => 'primary'])
            ->assertSessionHasErrors('message');
        $this->assertNull($team->fresh()->primary_hostname_id);

        $this->actingAs($owner)
            ->from(route('teams.hostnames', $team->id))
            ->put(route('teams.hostnames.update', $verified->id), ['primary' => 'primary'])
            ->assertSessionHas('status', 'Primary host has been changed.');
        $this->assertSame('app.acme.com', $team->fresh()->canonicalHost());
    }

    public function test_the_owner_deletes_a_host_and_falls_back_to_the_platform_subdomain(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        $hostname = $this->verifiedHost($team, 'app.acme.com', primary: true);

        $this->actingAs($owner)
            ->from(route('teams.hostnames', $team->id))
            ->delete(route('teams.hostnames.destroy', $hostname->id))
            ->assertSessionHas('status', 'Host has been deleted.');

        $this->assertNull(Hostname::find($hostname->id));
        $this->assertSame('acme.otper.com', $team->fresh()->canonicalHost());
    }

    public function test_another_teams_owner_cannot_touch_a_host(): void
    {
        [$team] = $this->teamWithOwner();
        $hostname = $this->verifiedHost($team, 'app.acme.com');
        [, $stranger] = $this->teamWithOwnerOf('globex');

        $this->actingAs($stranger)
            ->from(route('teams.hostnames', $team->id))
            ->delete(route('teams.hostnames.destroy', $hostname->id))
            ->assertSessionHasErrors('message');

        $this->assertNotNull(Hostname::find($hostname->id));
    }

    public function test_a_non_member_cannot_see_the_hosts_page(): void
    {
        [$team] = $this->teamWithOwner();
        $this->verifiedHost($team, 'app.acme.com');
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->from(config('neev.home'))
            ->get(route('teams.hostnames', $team->id))
            ->assertRedirect(config('neev.home'))
            ->assertSessionHasErrors(['message' => 'You cannot perform this action on this team.']);
    }

    public function test_claiming_a_host_the_team_already_holds_is_refused(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        $hostname = $team->claimHost('app.acme.com');

        $this->actingAs($owner)
            ->from(route('teams.hostnames', $team->id))
            ->post(route('teams.hostnames.store', $team->id), ['host' => 'APP.acme.com'])
            ->assertSessionHasErrors(['message' => 'This team has already added this host.'])
            ->assertSessionMissing('token');

        $this->assertSame(1, $team->hostnames()->count());
        $this->assertSame($hostname->verification_token, $hostname->fresh()->verification_token);
    }

    public function test_a_member_can_make_a_host_primary(): void
    {
        [$team] = $this->teamWithOwner();
        $member = User::factory()->create();
        $team->addMember($member);
        $hostname = $this->verifiedHost($team, 'app.acme.com');

        $this->actingAs($member)
            ->from(route('teams.hostnames', $team->id))
            ->put(route('teams.hostnames.update', $hostname->id), ['primary' => 'primary'])
            ->assertSessionHasNoErrors();

        $this->assertSame($hostname->id, $team->fresh()->primary_hostname_id);
    }

    public function test_the_owner_gets_a_new_token_and_the_host_keeps_serving(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        $hostname = $this->verifiedHost($team, 'app.acme.com');
        $oldToken = $hostname->verification_token;

        $this->actingAs($owner)
            ->from(route('teams.hostnames', $team->id))
            ->put(route('teams.hostnames.update', $hostname->id), ['token' => 'token'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('dns_record_name', '_neev-host.app.acme.com');

        $hostname->refresh();
        $this->assertNotSame($oldToken, $hostname->verification_token);
        $this->assertSame(session('token'), $hostname->verification_token);
        $this->assertTrue($hostname->isVerified());
    }

    public function test_verifying_a_disabled_host_says_it_is_disabled(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        $hostname = $team->claimHost('app.acme.com');
        $hostname->disable();

        $this->actingAs($owner)
            ->from(route('teams.hostnames', $team->id))
            ->put(route('teams.hostnames.update', $hostname->id), ['verify' => 'verify'])
            ->assertSessionHasErrors(['message' => 'This host is disabled.']);

        $this->assertSame(Hostname::STATUS_DISABLED, $hostname->fresh()->status);
    }

    public function test_a_new_token_for_a_disabled_host_is_refused(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        $hostname = $team->claimHost('app.acme.com');
        $hostname->forceFill(['status' => Hostname::STATUS_DISABLED])->save();
        $token = $hostname->verification_token;

        $this->actingAs($owner)
            ->from(route('teams.hostnames', $team->id))
            ->put(route('teams.hostnames.update', $hostname->id), ['token' => 'token'])
            ->assertSessionHasErrors(['message' => 'This host is disabled.'])
            ->assertSessionMissing('token');

        $this->assertSame($token, $hostname->fresh()->verification_token);
        $this->assertSame(Hostname::STATUS_DISABLED, $hostname->fresh()->status);
    }

    public function test_an_update_without_an_action_changes_nothing(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        $hostname = $this->verifiedHost($team, 'app.acme.com');
        $from = route('teams.hostnames', $team->id);

        $this->actingAs($owner)
            ->from($from)
            ->put(route('teams.hostnames.update', $hostname->id), [])
            ->assertRedirect($from)
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('status')
            ->assertSessionMissing('token');

        $this->assertTrue($hostname->fresh()->isVerified());
        $this->assertNull($team->fresh()->primary_hostname_id);
    }

    /**
     * @return array{0: Team, 1: User}
     */
    protected function teamWithOwnerOf(string $slug): array
    {
        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id, 'slug' => $slug]);
        $team->addMember($owner);

        return [$team, $owner];
    }
}
