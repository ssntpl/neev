<?php

namespace Ssntpl\Neev\Tests\Feature\Teams;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
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

/**
 * The Blade twins of the domain-federation API: the same rules have to hold
 * through the web controller, whose answers are redirects and flashed session
 * data rather than JSON.
 */
class DomainFederationWebTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The team routes are registered only when `neev.team` is on, and that
        // happens while the providers boot — before setUp() runs.
        $app['config']->set('neev.team', true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableDomainFederation();
        $this->loadMigrationsFrom(
            dirname(__DIR__, 3) . '/vendor/ssntpl/laravel-acl/database/migrations'
        );
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
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->addMember($owner);

        return [$team, $owner];
    }

    // -----------------------------------------------------------------
    // POST /teams/{team}/domain — federate
    // -----------------------------------------------------------------

    public function test_federating_flashes_the_token_and_the_record_name(): void
    {
        [$team, $owner] = $this->teamWithOwner();

        $response = $this->actingAs($owner)
            ->from(config('neev.home'))
            ->post(route('teams.domain', $team->id), ['domain' => 'acme.com']);

        $response->assertSessionHas('dns_record_name', '_neev-verification.acme.com');
        $this->assertSame(
            session('token'),
            Domain::where('domain', 'acme.com')->value('verification_token'),
        );
    }

    public function test_federating_another_spelling_of_a_held_domain_updates_the_same_row(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        DomainFactory::new()->forTeam($team)->create(['domain' => 'acme.com']);

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->post(route('teams.domain', $team->id), ['domain' => 'ACME.com.'])
            ->assertSessionHas('dns_record_name', '_neev-verification.acme.com');

        $this->assertSame(1, $team->domains()->count());
    }

    public function test_federating_requires_a_domain(): void
    {
        [$team, $owner] = $this->teamWithOwner();

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->post(route('teams.domain', $team->id), [])
            ->assertSessionHasErrors('domain');

        $this->assertSame(0, Domain::count());
    }

    public function test_federating_a_domain_that_is_only_dots_is_refused(): void
    {
        [$team, $owner] = $this->teamWithOwner();

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->post(route('teams.domain', $team->id), ['domain' => '...'])
            ->assertSessionHasErrors(['domain' => 'The domain must be a host name.']);

        $this->assertSame(0, Domain::count());
    }

    public function test_refederating_the_primary_domain_keeps_it_primary_and_unverifies_it(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        $domain = DomainFactory::new()->verified()->primary()->forTeam($team)->create(['domain' => 'acme.com']);

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->post(route('teams.domain', $team->id), ['domain' => 'acme.com'])
            ->assertSessionHasNoErrors();

        $domain->refresh();
        $this->assertTrue($domain->is_primary);
        $this->assertNull($domain->verified_at);
    }

    // -----------------------------------------------------------------
    // PUT /teams/{domain}/domain — token and verify
    // -----------------------------------------------------------------

    public function test_a_new_token_unverifies_the_domain_and_flashes_the_record_name(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        $domain = DomainFactory::new()->verified()->forTeam($team)->create([
            'domain' => 'acme.com',
            'verification_failed_at' => now(),
        ]);

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->put(route('teams.domain', $domain->id), ['token' => 'token'])
            ->assertSessionHas('dns_record_name', '_neev-verification.acme.com')
            ->assertSessionHas('token');

        $domain->refresh();
        $this->assertNull($domain->verified_at);
        $this->assertNull($domain->verification_failed_at);
    }

    public function test_verify_refuses_a_domain_another_team_already_verified(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        DomainFactory::new()->verified()->create(['domain' => 'acme.com']);
        $claim = DomainFactory::new()->forTeam($team)->create(['domain' => 'acme.com']);

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->put(route('teams.domain', $claim->id), ['verify' => 'verify'])
            ->assertSessionHasErrors(['message' => 'This domain is already verified by another team.']);

        $this->assertNull($claim->fresh()->verified_at);
    }

    public function test_verifying_again_after_a_new_token_keeps_the_rules(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        $domain = DomainFactory::new()->verified()->forTeam($team)->create(['domain' => 'acme.com']);
        $domain->rules()->create(['name' => 'mfa', 'value' => true]);

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->put(route('teams.domain', $domain->id), ['token' => 'token']);

        FakeDns::txt('_neev-verification.acme.com', (string) session('token'));

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->put(route('teams.domain', $domain->id), ['verify' => 'verify'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Domain verified successfully!');

        $this->assertNotNull($domain->fresh()->verified_at);
        $this->assertSame(1, $domain->rules()->where('name', 'mfa')->count());
        $this->assertTrue((bool) $domain->rules()->where('name', 'mfa')->value('value'));
    }

    // -----------------------------------------------------------------
    // DELETE /teams/{domain}/domain
    // -----------------------------------------------------------------

    public function test_deleting_the_primary_domain_promotes_a_verified_one(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        $primary = DomainFactory::new()->verified()->primary()->forTeam($team)->create();
        DomainFactory::new()->forTeam($team)->create();
        $verified = DomainFactory::new()->verified()->forTeam($team)->create();

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->delete(route('teams.domain', $primary->id))
            ->assertSessionHasNoErrors();

        $this->assertTrue($verified->fresh()->is_primary);
        $this->assertSame(1, $team->domains()->where('is_primary', true)->count());
    }

    public function test_deleting_the_last_primary_falls_back_to_an_unverified_domain(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        $primary = DomainFactory::new()->verified()->primary()->forTeam($team)->create();
        $pending = DomainFactory::new()->forTeam($team)->create();

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->delete(route('teams.domain', $primary->id))
            ->assertSessionHasNoErrors();

        $this->assertTrue($pending->fresh()->is_primary);
    }

    // -----------------------------------------------------------------
    // Invite and leave use every verified domain, not only the primary
    // -----------------------------------------------------------------

    public function test_invite_rejects_email_outside_an_enforced_non_primary_domain(): void
    {
        Mail::fake();
        [$team, $owner] = $this->teamWithOwner();
        DomainFactory::new()->verified()->primary()->forTeam($team)->create(['domain' => 'company.com']);
        DomainFactory::new()->verified()->forTeam($team)->create(['domain' => 'company.io', 'enforce' => true]);

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->put(route('teams.invite'), ['team_id' => $team->id, 'email' => 'outsider@gmail.com'])
            ->assertSessionHasErrors(['message' => 'You cannot invite member in this team.']);

        Mail::assertNothingSent();
    }

    public function test_invite_accepts_email_on_another_verified_domain_of_the_team(): void
    {
        Mail::fake();
        [$team, $owner] = $this->teamWithOwner();
        DomainFactory::new()->verified()->primary()->forTeam($team)->create(['domain' => 'company.com', 'enforce' => true]);
        DomainFactory::new()->verified()->forTeam($team)->create(['domain' => 'company.io']);

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->put(route('teams.invite'), ['team_id' => $team->id, 'email' => 'new.hire@company.io'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Invite link sent successfully.');
    }

    public function test_leave_deactivates_a_member_on_a_non_primary_verified_domain(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        DomainFactory::new()->verified()->primary()->forTeam($team)->create(['domain' => 'acme.com']);
        DomainFactory::new()->verified()->forTeam($team)->create(['domain' => 'acme.io']);
        $member = User::factory()->create(['active' => true, 'email' => 'employee@acme.io']);
        $team->addMember($member);

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->delete(route('teams.leave'), ['team_id' => $team->id, 'user_id' => $member->id])
            ->assertSessionHas('status', 'User Deactivated Successfully');

        $this->assertFalse($member->fresh()->active);
        $this->assertTrue($team->refresh()->hasMember($member));
    }

    // -----------------------------------------------------------------
    // The DNS dialog on the domain page
    // -----------------------------------------------------------------

    public function test_the_dns_dialog_shows_the_record_name_and_value(): void
    {
        [$team, $owner] = $this->teamWithOwner();

        $this->actingAs($owner)
            ->withSession([
                'token' => 'the-token-value',
                'dns_record_name' => '_neev-verification.acme.com',
            ])
            ->get(route('teams.domain', $team->id))
            ->assertOk()
            ->assertSee('_neev-verification.acme.com')
            ->assertSee('the-token-value');
    }
}
