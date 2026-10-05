<?php

namespace Ssntpl\Neev\Tests\Feature\Hostnames;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Database\Factories\EmailDomainFactory;
use Ssntpl\Neev\Database\Factories\HostnameFactory;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Models\Hostname;
use Ssntpl\Neev\Models\Tenant;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\TenantResolver;
use Ssntpl\Neev\Tests\Support\FakeDns;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;
use Mockery;

// Must load before any test calls verify(); see the file for why.
require_once __DIR__ . '/../../Support/FakeDns.php';

class HostnameApiTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The team hostname routes are only registered when teams are on.
        $app['config']->set('neev.team', true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableTenantIsolation();
    }

    protected function tearDown(): void
    {
        FakeDns::reset();

        parent::tearDown();
    }

    protected function authenticatedUser(): array
    {
        // Create a team to serve as the tenant context
        $team = TeamFactory::new()->create();

        // Set tenant context so TenantScope can resolve queries
        $resolver = app(TenantResolver::class);
        $resolver->setCurrentTenant($team);

        // Create user with proper tenant_id
        $user = User::factory()->create(['tenant_id' => $team->id]);

        // Add the user as a member of the tenant team (required by EnsureTenantMembership)
        $team->allUsers()->attach($user, ['joined' => true]);

        $token = $user->createLoginToken(60);

        return [$user, $token->plainTextToken];
    }

    // -----------------------------------------------------------------
    // GET /neev/teams/{team}/hostnames — list a team's hosts
    // -----------------------------------------------------------------

    public function test_list_hostnames(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);
        $team->allUsers()->attach($user, ['joined' => true]);

        $host = $this->verifiedHost($team, 'myteam.test.com', primary: true);
        // An email domain is not a host the team is served at.
        EmailDomainFactory::new()->forOwner($team)->verified()->create(['domain' => 'test.com']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/teams/' . $team->id . '/hostnames');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.host', 'myteam.test.com')
            ->assertJsonMissingPath('data.0.verification_token')
            ->assertJsonPath('primary_hostname_id', $host->id)
            // Under tenant isolation a team has no platform subdomain of its own.
            ->assertJsonPath('platform_host', null);
    }

    public function test_list_hostnames_returns_404_for_missing_team(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/teams/99999/hostnames')
            ->assertNotFound();
    }

    public function test_list_hostnames_rejects_non_member(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $otherTeam = TeamFactory::new()->create();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/teams/' . $otherTeam->id . '/hostnames')
            ->assertForbidden()
            ->assertJsonPath('message', 'You do not have permission to do this.');
    }

    // -----------------------------------------------------------------
    // POST /neev/teams/{team}/hostnames — add a host
    // -----------------------------------------------------------------

    /**
     * A team's platform subdomain follows its slug and is never stored, so
     * there is nothing to add: even its own is refused.
     */
    public function test_a_teams_own_subdomain_cannot_be_added(): void
    {
        config(['neev.platform_domain' => 'test.com']);

        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id, 'slug' => 'myteam']);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/teams/' . $team->id . '/hostnames', ['host' => 'myteam.test.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['host' => 'A host under the platform domain follows the slug and cannot be added.']);

        $this->assertSame(0, Hostname::count());
    }

    /**
     * The installation's own operational hosts sit inside the platform zone but
     * are nobody's tenant subdomain. Letting a team claim `app.otper.com` would
     * make their team the resolved context for every request to that host and
     * — through the uniqueness rule — lock the operator out of it.
     */
    public function test_a_team_cannot_claim_an_operational_host_in_the_platform_zone(): void
    {
        config(['neev.platform_domain' => 'test.com']);

        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id, 'slug' => 'myteam']);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/teams/' . $team->id . '/hostnames', ['host' => 'APP.test.com.'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('host');

        $this->assertSame(0, Hostname::count());
    }

    /** Nor may a team take the subdomain that belongs to another team's slug. */
    public function test_a_team_cannot_claim_another_teams_subdomain(): void
    {
        config(['neev.platform_domain' => 'test.com']);

        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id, 'slug' => 'mine']);
        TeamFactory::new()->create(['slug' => 'theirs']);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/teams/' . $team->id . '/hostnames', ['host' => 'theirs.test.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('host');

        $this->assertSame(0, Hostname::count());
    }

    /**
     * The reported bug: `type` was a client-supplied switch, so a team owner
     * could mark any domain verified. A verified claim reserves the host
     * installation-wide, so this was a takeover of somebody else's domain for
     * the price of one field. The field is now ignored.
     */
    public function test_a_host_outside_the_platform_zones_is_not_auto_verified_however_it_is_labelled(): void
    {
        config(['neev.platform_domain' => 'otper.com']);

        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/teams/' . $team->id . '/hostnames', [
                'host' => 'ssntpl.in',
                'type' => 'subdomain',
            ])
            ->assertCreated()
            ->assertJsonStructure(['data', 'dns_record']);

        $this->assertNull(
            Hostname::forHost('ssntpl.in')->first()?->verified_at,
            'A host the platform does not own must prove ownership by DNS.',
        );
    }

    /** With no platform zones declared, nothing is ours to vouch for. */
    public function test_nothing_is_auto_verified_when_no_platform_domain_is_configured(): void
    {
        config(['neev.platform_domain' => null]);

        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id, 'slug' => 'myteam']);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/teams/' . $team->id . '/hostnames', [
                'host' => 'myteam.test.com',
                'type' => 'subdomain',
            ])->assertCreated();

        $hostname = Hostname::forHost('myteam.test.com')->first();
        $this->assertNotNull($hostname);
        $this->assertNull($hostname->verified_at);
    }

    public function test_add_host_returns_the_dns_record_to_publish(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/teams/' . $team->id . '/hostnames', ['host' => 'custom.example.com']);

        $hostname = Hostname::forHost('custom.example.com')->first();
        $this->assertNotNull($hostname);
        $this->assertTrue($hostname->isOwnedBy($team));
        $this->assertNull($hostname->verified_at);
        $this->assertSame(Hostname::STATUS_PENDING, $hostname->status);

        $response->assertCreated()
            ->assertJsonPath('message', 'Host added.')
            ->assertJsonPath('data.id', $hostname->id)
            ->assertJsonMissingPath('data.verification_token')
            ->assertJsonPath('dns_record', [
                'type' => 'TXT',
                'name' => '_neev-host.custom.example.com',
                'value' => $hostname->verification_token,
            ]);
        $this->assertDatabaseCount('email_domains', 0);
    }

    public function test_add_host_stores_it_canonically(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/teams/' . $team->id . '/hostnames', ['host' => 'APP.acme.com.'])
            ->assertCreated()
            ->assertJsonPath('data.host', 'app.acme.com')
            ->assertJsonPath('dns_record.name', '_neev-host.app.acme.com');

        $this->assertSame('app.acme.com', Hostname::sole()->host);
    }

    public function test_add_host_that_is_only_dots_is_refused(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/teams/' . $team->id . '/hostnames', ['host' => ' . . '])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['host' => 'The domain must be a host name.']);

        $this->assertSame(0, Hostname::count());
    }

    public function test_add_host_refuses_another_spelling_of_one_the_team_holds(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);
        HostnameFactory::new()->forOwner($team)->create(['host' => 'acme.com']);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/teams/' . $team->id . '/hostnames', ['host' => 'ACME.com.'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['host' => 'This team has already added this host.']);

        $this->assertSame(1, Hostname::forHost('acme.com')->count());
    }

    public function test_add_host_rejects_non_owner(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $otherUser = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $otherUser->id]);
        $team->allUsers()->attach($user, ['joined' => true]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/teams/' . $team->id . '/hostnames', ['host' => 'notmine.example.com'])
            ->assertForbidden()
            ->assertJsonPath('message', 'You do not have permission to do this.');

        $this->assertSame(0, Hostname::count());
    }

    public function test_add_duplicate_host_fails(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);

        HostnameFactory::new()->forOwner($team)->create(['host' => 'taken.example.com']);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/teams/' . $team->id . '/hostnames', ['host' => 'taken.example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('host');
    }

    /**
     * A host is unique across every owner: while another team holds it, even
     * by a claim not yet proven, nobody else may add it.
     */
    public function test_add_refuses_a_host_another_team_holds(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);
        $holder = TeamFactory::new()->create();
        $held = $holder->claimHost('acme.com');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/teams/' . $team->id . '/hostnames', ['host' => 'acme.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['host' => 'This host cannot be added.']);

        $this->assertTrue(Hostname::forHost('acme.com')->sole()->is($held));
    }

    /**
     * A host is unique, so another team's claim holds it however long it has
     * gone unproven; its holder has to release it.
     */
    public function test_add_refuses_a_host_another_team_left_unproven(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);
        $stale = HostnameFactory::new()->create([
            'host' => 'acme.com',
            'verification_token' => 'stale',
            'created_at' => now()->subDays(90),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/teams/' . $team->id . '/hostnames', ['host' => 'acme.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['host' => 'This host cannot be added.']);

        $this->assertTrue(Hostname::forHost('acme.com')->sole()->is($stale));
    }

    public function test_add_host_returns_404_for_missing_team(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/teams/99999/hostnames', ['host' => 'missing-team.example.com'])
            ->assertNotFound();
    }

    public function test_adding_a_host_that_is_not_a_string_is_refused(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/teams/' . $team->id . '/hostnames', ['host' => ['acme.com']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('host');

        $this->assertSame(0, Hostname::count());
    }

    public function test_adding_something_that_is_not_a_host_name_is_refused(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);

        foreach (['', 'https://acme.com/x', 'ac me.com', str_repeat('a', 250) . '.com'] as $value) {
            $this->withHeader('Authorization', 'Bearer ' . $token)
                ->postJson('/neev/teams/' . $team->id . '/hostnames', ['host' => $value])
                ->assertStatus(422)
                ->assertJsonValidationErrors('host');
        }

        $this->assertSame(0, Hostname::count());
    }

    // -----------------------------------------------------------------
    // GET /neev/hostnames/{hostname} — show a host
    // -----------------------------------------------------------------

    public function test_show_hostname(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);
        $team->allUsers()->attach($user, ['joined' => true]);

        $hostname = HostnameFactory::new()->forOwner($team)->verified()->create();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/hostnames/' . $hostname->id)
            ->assertOk()
            ->assertJsonPath('data.id', $hostname->id)
            ->assertJsonPath('data.host', $hostname->host)
            ->assertJsonMissingPath('data.verification_token');
    }

    public function test_show_hostname_for_team_member(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->allUsers()->attach($user, ['joined' => true]);

        $hostname = HostnameFactory::new()->forOwner($team)->verified()->create();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/hostnames/' . $hostname->id)
            ->assertOk();
    }

    public function test_show_hostname_returns_404_for_nonexistent(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/hostnames/99999')
            ->assertNotFound();
    }

    public function test_show_hostname_rejects_non_member(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $otherTeam = TeamFactory::new()->create();
        $hostname = HostnameFactory::new()->forOwner($otherTeam)->create();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/hostnames/' . $hostname->id)
            ->assertForbidden();
    }

    /** The API manages team hosts; a tenant's host is not reachable through it. */
    public function test_show_hostname_rejects_a_host_not_owned_by_a_team(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme-' . uniqid()]);
        $hostname = HostnameFactory::new()->forOwner($tenant)->create();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/hostnames/' . $hostname->id)
            ->assertForbidden();
    }

    // -----------------------------------------------------------------
    // DELETE /neev/hostnames/{hostname} — delete a host
    // -----------------------------------------------------------------

    public function test_delete_hostname(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);

        HostnameFactory::new()->forOwner($team)->verified()->create();
        $hostname = HostnameFactory::new()->forOwner($team)->verified()->create();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/hostnames/' . $hostname->id)
            ->assertOk()
            ->assertJsonPath('message', 'Host deleted.');

        $this->assertDatabaseMissing('hostnames', ['id' => $hostname->id]);
        $this->assertSame(1, Hostname::count());
    }

    /**
     * A team is always served at its platform subdomain, so its last custom
     * host may go too.
     */
    public function test_can_delete_the_only_host(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);

        $hostname = $this->verifiedHost($team, 'only.example.com', primary: true);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/hostnames/' . $hostname->id)
            ->assertOk();

        $this->assertSame(0, Hostname::count());
        $this->assertNull($team->fresh()->primary_hostname_id);
    }

    /**
     * Deleting the primary unpoints the team from it rather than promoting
     * another row: canonicalHost() falls back to the next host by itself.
     */
    public function test_delete_primary_host_clears_the_primary_and_falls_back(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);

        $primary = $this->verifiedHost($team, 'primary.example.com', primary: true);
        // Older than the verified one, but a pending claim serves nothing.
        $team->claimHost('pending.example.com');
        $this->verifiedHost($team, 'secondary.example.com');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/hostnames/' . $primary->id)
            ->assertOk();

        $team->refresh();
        $this->assertNull($team->primary_hostname_id);
        $this->assertSame('secondary.example.com', $team->canonicalHost());
    }

    public function test_delete_hostname_rejects_non_owner(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $otherUser = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $otherUser->id]);
        $team->allUsers()->attach($user, ['joined' => true]);
        $hostname = HostnameFactory::new()->forOwner($team)->create();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/hostnames/' . $hostname->id)
            ->assertForbidden();

        $this->assertDatabaseHas('hostnames', ['id' => $hostname->id]);
    }

    public function test_delete_returns_404_for_nonexistent_hostname(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/hostnames/99999')
            ->assertNotFound();
    }

    /**
     * A host is transport, not membership: deleting one leaves alone the
     * members the team's email domain on the same name deactivated. Only
     * removing the email domain gives their accounts back.
     */
    public function test_delete_host_leaves_members_an_email_domain_deactivated(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);

        $host = $this->verifiedHost($team, 'acme.com');
        EmailDomainFactory::new()->forOwner($team)->verified()->create(['domain' => 'acme.com']);

        $member = User::factory()->create(['active' => true, 'email' => 'alice@acme.com', 'tenant_id' => $user->tenant_id]);
        $team->allUsers()->attach($member, ['joined' => true]);
        $member->deactivate();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/hostnames/' . $host->id)
            ->assertOk();

        $this->assertFalse($member->fresh()->active);
        $this->assertTrue($team->emailDomains()->forHost('acme.com')->exists());
    }

    // -----------------------------------------------------------------
    // POST /neev/hostnames/{hostname}/token — issue a new token
    // -----------------------------------------------------------------

    /**
     * A host copied from `domains` with no token is issued one like any other;
     * it goes back to pending until the new record is checked.
     */
    public function test_token_is_issued_for_a_host_with_no_token(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);

        // A row copied from `domains` that never had a token.
        $hostname = HostnameFactory::new()->forOwner($team)->verified()->create(['host' => 'copied.example.com']);
        $this->assertNull($hostname->verification_token);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/hostnames/' . $hostname->id . '/token')
            ->assertOk()
            ->assertJsonPath('message', 'Verification token issued.');

        $hostname->refresh();
        $this->assertNotNull($hostname->verification_token);
        $this->assertNull($hostname->verified_at);
        $response->assertJsonPath('dns_record.value', $hostname->verification_token);
    }

    public function test_token_restarts_the_claim(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);
        $hostname = HostnameFactory::new()->forOwner($team)->verified()->create([
            'host' => 'app.acme.com',
            'verification_token' => 'old',
            'verification_failed_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/hostnames/' . $hostname->id . '/token')
            ->assertOk();

        $hostname->refresh();
        $this->assertNull($hostname->verified_at);
        $this->assertNull($hostname->verification_failed_at);
        $this->assertSame(Hostname::STATUS_PENDING, $hostname->status);
        $this->assertNotSame('old', $hostname->verification_token);
        $response->assertJsonPath('data.id', $hostname->id)
            ->assertJsonPath('dns_record', [
                'type' => 'TXT',
                'name' => '_neev-host.app.acme.com',
                'value' => $hostname->verification_token,
            ]);
    }

    public function test_token_rejects_non_owner(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $otherUser = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $otherUser->id]);
        $hostname = HostnameFactory::new()->forOwner($team)->create(['verification_token' => 'kept']);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/hostnames/' . $hostname->id . '/token')
            ->assertForbidden();

        $this->assertSame('kept', $hostname->fresh()->verification_token);
    }

    public function test_token_returns_404_for_nonexistent(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/hostnames/99999/token')
            ->assertNotFound();
    }

    // -----------------------------------------------------------------
    // POST /neev/hostnames/{hostname}/primary — set the primary host
    // -----------------------------------------------------------------

    public function test_set_verified_host_as_primary(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);

        $this->verifiedHost($team, 'primary.example.com', primary: true);
        $secondary = $this->verifiedHost($team, 'secondary.example.com');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/hostnames/' . $secondary->id . '/primary')
            ->assertOk()
            ->assertJsonPath('message', 'Primary host set.');

        $team->refresh();
        $this->assertSame($secondary->id, $team->primary_hostname_id);
        $this->assertSame('secondary.example.com', $team->canonicalHost());
    }

    public function test_set_primary_rejects_unverified_host(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);

        $unverified = $team->claimHost('pending.example.com');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/hostnames/' . $unverified->id . '/primary')
            ->assertStatus(400)
            ->assertJsonPath('message', 'Only a verified host can be primary.');

        $this->assertNull($team->fresh()->primary_hostname_id);
    }

    public function test_a_disabled_host_gets_no_new_token(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);
        $hostname = $team->claimHost('app.example.com');
        $hostname->disable();
        $old = $hostname->verification_token;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/hostnames/' . $hostname->id . '/token')
            ->assertStatus(400)
            ->assertJsonPath('message', 'This host is disabled.');

        $this->assertSame($old, $hostname->fresh()->verification_token);
        $this->assertSame(Hostname::STATUS_DISABLED, $hostname->fresh()->status);
    }

    public function test_dns_actions_are_rate_limited(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);
        $hostname = $team->claimHost('app.example.com');

        for ($i = 0; $i < 10; $i++) {
            $this->withHeader('Authorization', 'Bearer ' . $token)
                ->postJson('/neev/hostnames/' . $hostname->id . '/verify')
                ->assertStatus(400);
        }

        // The limit is shared: a token request now counts against the same bucket.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/hostnames/' . $hostname->id . '/token')
            ->assertStatus(429);
    }

    public function test_set_primary_rejects_non_owner(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $otherUser = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $otherUser->id]);
        $hostname = HostnameFactory::new()->forOwner($team)->verified()->create();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/hostnames/' . $hostname->id . '/primary')
            ->assertForbidden();

        $this->assertNull($team->fresh()->primary_hostname_id);
    }

    public function test_set_primary_returns_404_for_nonexistent_host(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/hostnames/99999/primary')
            ->assertNotFound();
    }

    // -----------------------------------------------------------------
    // GET /neev/hostnames/current — current tenant context
    // -----------------------------------------------------------------

    public function test_current_returns_the_team_context(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);
        // The resolved context is the one EnsureTenantMembership checks against.
        $team->allUsers()->attach($user, ['joined' => true]);

        $resolver = Mockery::mock(TenantResolver::class)->shouldIgnoreMissing();
        $resolver->shouldReceive('resolvedContext')->andReturn($team);
        $resolver->shouldReceive('currentHostname')->andReturn(null);
        // TenantMiddleware resolves through this instance before the
        // controller runs; shouldIgnoreMissing() would hand it a truthy stub
        // with an unverified domain and turn the request into a 403.
        $resolver->shouldReceive('resolve')->andReturn($team);
        $resolver->shouldReceive('isResolvedDomainVerified')->andReturn(true);
        $this->app->instance(TenantResolver::class, $resolver);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/hostnames/current')
            ->assertOk()
            ->assertJsonPath('data.type', 'team')
            ->assertJsonPath('data.context.id', $team->id)
            ->assertJsonPath('data.hostname', null);
    }

    public function test_current_returns_the_resolved_hostname(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);
        $team->allUsers()->attach($user, ['joined' => true]);
        $hostname = $this->verifiedHost($team, 'app.acme.com');

        $resolver = Mockery::mock(TenantResolver::class)->shouldIgnoreMissing();
        $resolver->shouldReceive('resolvedContext')->andReturn($team);
        $resolver->shouldReceive('currentHostname')->andReturn($hostname);
        $resolver->shouldReceive('resolve')->andReturn($team);
        $resolver->shouldReceive('isResolvedDomainVerified')->andReturn(true);
        $this->app->instance(TenantResolver::class, $resolver);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/hostnames/current')
            ->assertOk()
            ->assertJsonPath('data.hostname.id', $hostname->id)
            ->assertJsonPath('data.hostname.host', 'app.acme.com')
            ->assertJsonMissingPath('data.hostname.verification_token');
    }

    public function test_current_returns_a_tenant_context(): void
    {
        // Under isolation the resolved context is a Tenant, not a Team.
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme-' . uniqid()]);

        $resolver = Mockery::mock(TenantResolver::class)->shouldIgnoreMissing();
        $resolver->shouldReceive('resolvedContext')->andReturn($tenant);
        $resolver->shouldReceive('currentHostname')->andReturn(null);
        $resolver->shouldReceive('resolve')->andReturn($tenant);
        $resolver->shouldReceive('isResolvedDomainVerified')->andReturn(true);
        $this->app->instance(TenantResolver::class, $resolver);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $token = $user->createLoginToken(60)->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/hostnames/current')
            ->assertOk()
            ->assertJsonPath('data.type', 'tenant')
            ->assertJsonPath('data.context.id', $tenant->id)
            ->assertJsonPath('data.hostname', null);
    }

    public function test_current_returns_error_when_no_context(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $resolver = Mockery::mock(TenantResolver::class)->shouldIgnoreMissing();
        $resolver->shouldReceive('resolvedContext')->andReturn(null);
        $resolver->shouldReceive('currentHostname')->andReturn(null);
        $resolver->shouldReceive('resolve')->andReturn(null);
        $this->app->instance(TenantResolver::class, $resolver);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/hostnames/current')
            ->assertStatus(400)
            ->assertJsonPath('message', 'No tenant context.');
    }

    // -----------------------------------------------------------------
    // POST /neev/hostnames/{hostname}/verify — check the DNS record
    // -----------------------------------------------------------------

    public function test_verify_returns_404_for_nonexistent_host(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/hostnames/99999/verify')
            ->assertNotFound();
    }

    public function test_verify_rejects_non_owner(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $otherUser = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $otherUser->id]);
        $hostname = $team->claimHost('app.acme.com');
        FakeDns::txt('_neev-host.app.acme.com', $hostname->verification_token);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/hostnames/' . $hostname->id . '/verify')
            ->assertForbidden();

        $this->assertNull($hostname->fresh()->verified_at);
    }

    public function test_verify_succeeds_when_the_host_record_is_published(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);
        $claim = $team->claimHost('app.acme.com');
        FakeDns::txt('_neev-host.app.acme.com', $claim->verification_token);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/hostnames/' . $claim->id . '/verify')
            ->assertOk()
            ->assertJsonPath('message', 'Host verified.')
            ->assertJsonPath('data.id', $claim->id);

        $claim->refresh();
        $this->assertNotNull($claim->verified_at);
        $this->assertSame(Hostname::STATUS_VERIFIED, $claim->status);
    }

    /**
     * A verified host is not taken on trust: verifying it checks its record
     * again, and a record that has gone marks it failing.
     */
    public function test_verify_rechecks_an_already_verified_host(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);
        $hostname = $this->verifiedHost($team, 'app.acme.com');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/hostnames/' . $hostname->id . '/verify')
            ->assertStatus(400)
            ->assertJsonPath('message', 'DNS verification failed. Please check your DNS record.');

        $hostname->refresh();
        $this->assertSame(Hostname::STATUS_FAILED, $hostname->status);
        $this->assertNotNull($hostname->verification_failed_at);

        FakeDns::txt('_neev-host.app.acme.com', $hostname->verification_token);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/hostnames/' . $hostname->id . '/verify')
            ->assertOk()
            ->assertJsonPath('message', 'Host verified.');

        $hostname->refresh();
        $this->assertSame(Hostname::STATUS_VERIFIED, $hostname->status);
        $this->assertNull($hostname->verification_failed_at);
    }

    /**
     * A host is proven under `_neev-host.`; an email-domain record for the
     * same name proves something else and does not count.
     */
    public function test_verify_fails_on_a_record_under_another_name(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);
        $claim = $team->claimHost('acme.com');
        FakeDns::txt('_neev-host.acme.com');
        FakeDns::txt('_neev-email.acme.com', $claim->verification_token);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/hostnames/' . $claim->id . '/verify')
            ->assertStatus(400)
            ->assertJsonPath('message', 'DNS verification failed. Please check your DNS record.');

        $this->assertNull($claim->fresh()->verified_at);
    }
}
