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
            ->assertDontSee('Add Host');
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

    public function test_a_member_cannot_claim_a_host(): void
    {
        [$team] = $this->teamWithOwner();
        $member = User::factory()->create();
        $team->addMember($member);

        $this->actingAs($member)
            ->from(route('teams.hostnames', $team->id))
            ->post(route('teams.hostnames.store', $team->id), ['host' => 'app.acme.com'])
            ->assertSessionHasErrors('message');

        $this->assertDatabaseCount('hostnames', 0);
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
