<?php

namespace Ssntpl\Neev\Tests\Feature\Teams;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mockery;
use RuntimeException;
use Ssntpl\Neev\Database\Factories\EmailDomainFactory;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Models\EmailDomain;
use Ssntpl\Neev\Models\Membership;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\Support\FakeDns;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

// Must load before any test calls EmailDomain::verify(); see the file for why.
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
    // POST /teams/{team}/email-domains — federate
    // -----------------------------------------------------------------

    public function test_federating_flashes_the_token_and_the_record_name(): void
    {
        [$team, $owner] = $this->teamWithOwner();

        $response = $this->actingAs($owner)
            ->from(config('neev.home'))
            ->post(route('teams.email-domains.store', $team->id), ['domain' => 'acme.com']);

        $response->assertSessionHas('dns_record_name', '_neev-email.acme.com');
        $this->assertSame(
            session('token'),
            EmailDomain::where('domain', 'acme.com')->value('verification_token'),
        );
    }

    public function test_federating_another_spelling_of_a_held_domain_updates_the_same_row(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        EmailDomainFactory::new()->forOwner($team)->create(['domain' => 'acme.com']);

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->post(route('teams.email-domains.store', $team->id), ['domain' => 'ACME.com.'])
            ->assertSessionHas('dns_record_name', '_neev-email.acme.com');

        $this->assertSame(1, $team->emailDomains()->count());
    }

    public function test_federating_requires_a_domain(): void
    {
        [$team, $owner] = $this->teamWithOwner();

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->post(route('teams.email-domains.store', $team->id), [])
            ->assertSessionHasErrors('domain');

        $this->assertSame(0, EmailDomain::count());
    }

    public function test_federating_a_domain_that_is_only_dots_is_refused(): void
    {
        [$team, $owner] = $this->teamWithOwner();

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->post(route('teams.email-domains.store', $team->id), ['domain' => '...'])
            ->assertSessionHasErrors(['domain' => 'The domain must be a host name.']);

        $this->assertSame(0, EmailDomain::count());
    }

    public function test_refederating_a_verified_domain_keeps_it_verified(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        $domain = EmailDomainFactory::new()->verified()->forOwner($team)->create(['domain' => 'acme.com']);

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->post(route('teams.email-domains.store', $team->id), ['domain' => 'acme.com'])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($domain->fresh()->verified_at);
    }

    /** The add form's checkbox is ticked by default; re-adding a held domain must not act on it. */
    public function test_re_adding_a_held_domain_leaves_its_enforce_alone(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        $off = EmailDomainFactory::new()->verified()->forOwner($team)->create(['domain' => 'acme.com']);
        $on = EmailDomainFactory::new()->verified()->enforced()->forOwner($team)->create(['domain' => 'acme.io']);

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->post(route('teams.email-domains.store', $team->id), ['domain' => 'acme.com', 'enforce' => 'on'])
            ->assertSessionHasNoErrors();
        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->post(route('teams.email-domains.store', $team->id), ['domain' => 'acme.io'])
            ->assertSessionHasNoErrors();

        $this->assertFalse($off->fresh()->enforce);
        $this->assertTrue($on->fresh()->enforce);
    }

    /** As on the API, Neev checks membership only; the app's middleware may narrow it. */
    public function test_a_member_can_federate_a_domain(): void
    {
        [$team] = $this->teamWithOwner();
        $member = User::factory()->create();
        $team->addMember($member);

        $this->actingAs($member)
            ->from(config('neev.home'))
            ->post(route('teams.email-domains.store', $team->id), ['domain' => 'acme.com'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('dns_record_name', '_neev-email.acme.com');

        $this->assertSame(1, $team->emailDomains()->count());
    }

    public function test_an_outsider_cannot_federate_update_or_delete_a_domain(): void
    {
        [$team] = $this->teamWithOwner();
        $outsider = User::factory()->create();
        $domain = EmailDomainFactory::new()->forOwner($team)->create(['domain' => 'acme.com']);

        $this->actingAs($outsider)
            ->from(config('neev.home'))
            ->post(route('teams.email-domains.store', $team->id), ['domain' => 'other.com'])
            ->assertSessionHasErrors(['message' => 'You do not have the required permissions to federate domain.']);
        $this->actingAs($outsider)
            ->from(config('neev.home'))
            ->put(route('teams.email-domains.update', $domain->id), ['enforce' => 'on'])
            ->assertSessionHasErrors(['message' => 'You do not have the required permissions to update domain.']);
        $this->actingAs($outsider)
            ->from(config('neev.home'))
            ->delete(route('teams.email-domains.destroy', $domain->id))
            ->assertSessionHasErrors(['message' => 'You do not have the required permissions to delete domain.']);

        $this->assertSame(1, EmailDomain::count());
        $this->assertFalse($domain->fresh()->enforce);
    }

    public function test_federating_a_disabled_domain_flashes_that_it_is_disabled(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        $domain = EmailDomainFactory::new()->forOwner($team)->create([
            'domain' => 'acme.com',
            'status' => EmailDomain::STATUS_DISABLED,
        ]);
        $token = $domain->verification_token;

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->post(route('teams.email-domains.store', $team->id), ['domain' => 'acme.com'])
            ->assertSessionHasErrors(['message' => 'This domain is disabled.'])
            ->assertSessionMissing('token');

        $this->assertSame($token, $domain->fresh()->verification_token);
    }

    public function test_verify_warns_when_another_owner_already_enforces(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        $pending = $team->federateDomain('acme.com', true);
        EmailDomainFactory::new()->verified()->create(['domain' => 'acme.com', 'enforce' => true]);
        FakeDns::txt('_neev-email.acme.com', $pending->verification_token);

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->put(route('teams.email-domains.update', $pending->id), ['verify' => 'verify'])
            ->assertSessionHas('status', 'Domain verified. Another owner already enforces this domain, so enforce was turned off.');

        $this->assertTrue($pending->fresh()->isVerified());
        $this->assertFalse($pending->fresh()->enforce);
    }

    public function test_federating_with_enforce_a_domain_another_owner_enforces_is_refused(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        EmailDomainFactory::new()->verified()->create(['domain' => 'acme.com', 'enforce' => true]);

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->post(route('teams.email-domains.store', $team->id), ['domain' => 'acme.com', 'enforce' => 'on'])
            ->assertSessionHasErrors(['message' => 'Another owner already enforces this email domain.']);

        $this->assertSame(0, $team->emailDomains()->count());
    }

    public function test_an_unexpected_failure_while_federating_is_logged_and_flashed(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        Log::spy();
        EmailDomain::saving(fn () => throw new RuntimeException('database is down'));

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->post(route('teams.email-domains.store', $team->id), ['domain' => 'acme.com'])
            ->assertSessionHasErrors(['message' => 'Failed to federate domain.']);

        Log::shouldHaveReceived('error')->once()->with(Mockery::type(RuntimeException::class));
        $this->assertSame(0, EmailDomain::count());
    }

    // -----------------------------------------------------------------
    // PUT /teams/email-domains/{domain} — token and verify
    // -----------------------------------------------------------------

    public function test_a_new_token_keeps_the_domain_verified_and_flashes_the_record_name(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        $domain = EmailDomainFactory::new()->verified()->forOwner($team)->create([
            'domain' => 'acme.com',
            'verification_failed_at' => now(),
        ]);

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->put(route('teams.email-domains.update', $domain->id), ['token' => 'token'])
            ->assertSessionHas('dns_record_name', '_neev-email.acme.com')
            ->assertSessionHas('token');

        $domain->refresh();
        $this->assertNotNull($domain->verified_at);
        $this->assertNotNull($domain->verification_failed_at);
    }

    /**
     * Verification is not exclusive: another team having verified the domain
     * does not stop this one verifying it by its own record.
     */
    public function test_verify_succeeds_for_a_domain_another_team_already_verified(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        $other = EmailDomainFactory::new()->verified()->create(['domain' => 'acme.com']);
        $claim = $team->federateDomain('acme.com', false);

        FakeDns::txt('_neev-email.acme.com', $claim->verification_token);

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->put(route('teams.email-domains.update', $claim->id), ['verify' => 'verify'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Domain verified successfully!');

        $this->assertNotNull($claim->fresh()->verified_at);
        $this->assertNotNull($other->fresh()->verified_at);
    }

    public function test_verifying_again_after_a_new_token_reads_the_new_record(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        $domain = EmailDomainFactory::new()->verified()->forOwner($team)->create(['domain' => 'acme.com']);

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->put(route('teams.email-domains.update', $domain->id), ['token' => 'token']);

        FakeDns::txt('_neev-email.acme.com', (string) session('token'));

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->put(route('teams.email-domains.update', $domain->id), ['verify' => 'verify'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Domain verified successfully!');

        $this->assertNotNull($domain->fresh()->verified_at);
    }

    public function test_verify_without_the_record_flashes_that_it_was_not_found(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        $domain = EmailDomainFactory::new()->forOwner($team)->create(['domain' => 'acme.com']);
        FakeDns::txt('_neev-email.acme.com');

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->put(route('teams.email-domains.update', $domain->id), ['verify' => 'verify'])
            ->assertSessionHasErrors(['message' => 'DNS record not found. Please try again later.']);

        $this->assertNull($domain->fresh()->verified_at);
    }

    public function test_verifying_a_disabled_domain_says_it_is_disabled(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        $domain = EmailDomainFactory::new()->forOwner($team)->create([
            'domain' => 'acme.com',
            'status' => EmailDomain::STATUS_DISABLED,
        ]);

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->put(route('teams.email-domains.update', $domain->id), ['verify' => 'verify'])
            ->assertSessionHasErrors(['message' => 'This domain is disabled.']);

        $this->assertSame(EmailDomain::STATUS_DISABLED, $domain->fresh()->status);
    }

    public function test_a_new_token_for_a_disabled_domain_is_refused(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        $domain = EmailDomainFactory::new()->forOwner($team)->create([
            'domain' => 'acme.com',
            'status' => EmailDomain::STATUS_DISABLED,
        ]);
        $token = $domain->verification_token;

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->put(route('teams.email-domains.update', $domain->id), ['token' => 'token'])
            ->assertSessionHasErrors(['message' => 'This domain is disabled.'])
            ->assertSessionMissing('token');

        $this->assertSame($token, $domain->fresh()->verification_token);
        $this->assertSame(EmailDomain::STATUS_DISABLED, $domain->fresh()->status);
    }

    public function test_a_member_can_update_a_domain(): void
    {
        [$team] = $this->teamWithOwner();
        $member = User::factory()->create();
        $team->addMember($member);
        $domain = EmailDomainFactory::new()->forOwner($team)->create(['domain' => 'acme.com']);

        $this->actingAs($member)
            ->from(config('neev.home'))
            ->put(route('teams.email-domains.update', $domain->id), ['enforce' => 'on'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($domain->fresh()->enforce);
    }

    // -----------------------------------------------------------------
    // PUT /teams/email-domains/{domain} — enforce
    // -----------------------------------------------------------------

    public function test_the_owner_turns_enforce_on_and_an_unticked_box_turns_it_off(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        $domain = EmailDomainFactory::new()->verified()->forOwner($team)->create(['domain' => 'acme.com']);

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->put(route('teams.email-domains.update', $domain->id), ['enforce' => 'on'])
            ->assertSessionHas('status', 'domain has been updated.');
        $this->assertTrue($domain->fresh()->enforce);

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->put(route('teams.email-domains.update', $domain->id), [])
            ->assertSessionHas('status', 'domain has been updated.');
        $this->assertFalse($domain->fresh()->enforce);
    }

    public function test_enforcing_a_domain_another_owner_enforces_is_refused(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        EmailDomainFactory::new()->verified()->create(['domain' => 'acme.com', 'enforce' => true]);
        $domain = EmailDomainFactory::new()->verified()->forOwner($team)->create(['domain' => 'acme.com']);

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->put(route('teams.email-domains.update', $domain->id), ['enforce' => 'on'])
            ->assertSessionHasErrors(['message' => 'Another owner already enforces this email domain.']);

        $this->assertFalse($domain->fresh()->enforce);
    }

    public function test_an_unexpected_failure_while_updating_is_logged_and_flashed(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        $domain = EmailDomainFactory::new()->verified()->forOwner($team)->create(['domain' => 'acme.com']);
        Log::spy();
        EmailDomain::saving(fn () => throw new RuntimeException('database is down'));

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->put(route('teams.email-domains.update', $domain->id), ['enforce' => 'on'])
            ->assertSessionHasErrors(['message' => 'Failed to update domain.']);

        Log::shouldHaveReceived('error')->once()->with(Mockery::type(RuntimeException::class));
        $this->assertFalse($domain->fresh()->enforce);
    }

    // -----------------------------------------------------------------
    // DELETE /teams/email-domains/{domain}
    // -----------------------------------------------------------------

    public function test_a_member_can_delete_a_domain(): void
    {
        [$team] = $this->teamWithOwner();
        $member = User::factory()->create();
        $team->addMember($member);
        $domain = EmailDomainFactory::new()->forOwner($team)->create(['domain' => 'acme.com']);

        $this->actingAs($member)
            ->from(config('neev.home'))
            ->delete(route('teams.email-domains.destroy', $domain->id))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('email_domains', ['id' => $domain->id]);
    }

    public function test_an_unexpected_failure_while_deleting_is_logged_and_flashed(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        $domain = EmailDomainFactory::new()->forOwner($team)->create(['domain' => 'acme.com']);
        Log::spy();
        EmailDomain::deleting(fn () => throw new RuntimeException('database is down'));

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->delete(route('teams.email-domains.destroy', $domain->id))
            ->assertSessionHasErrors(['message' => 'Failed to delete domain.']);

        Log::shouldHaveReceived('error')->once()->with(Mockery::type(RuntimeException::class));
        $this->assertDatabaseHas('email_domains', ['id' => $domain->id]);
    }

    // -----------------------------------------------------------------
    // Invite and leave use every verified domain of the team
    // -----------------------------------------------------------------

    public function test_invite_rejects_email_outside_an_enforced_second_domain(): void
    {
        Mail::fake();
        [$team, $owner] = $this->teamWithOwner();
        EmailDomainFactory::new()->verified()->forOwner($team)->create(['domain' => 'company.com']);
        EmailDomainFactory::new()->verified()->forOwner($team)->create(['domain' => 'company.io', 'enforce' => true]);

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
        EmailDomainFactory::new()->verified()->forOwner($team)->create(['domain' => 'company.com', 'enforce' => true]);
        EmailDomainFactory::new()->verified()->forOwner($team)->create(['domain' => 'company.io']);

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->put(route('teams.invite'), ['team_id' => $team->id, 'email' => 'new.hire@company.io'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Invite link sent successfully.');
    }

    public function test_leave_deactivates_a_member_on_a_second_enforced_domain(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        EmailDomainFactory::new()->verified()->forOwner($team)->create(['domain' => 'acme.com']);
        EmailDomainFactory::new()->verified()->enforced()->forOwner($team)->create(['domain' => 'acme.io']);
        $member = User::factory()->create(['active' => true, 'email' => 'employee@acme.io']);
        $team->addMember($member);

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->delete(route('teams.leave'), ['team_id' => $team->id, 'user_id' => $member->id])
            ->assertSessionHas('status', 'User Deactivated Successfully');

        $this->assertFalse($member->fresh()->active);
        $this->assertTrue($team->refresh()->hasMember($member));
    }

    public function test_a_member_on_an_enforced_domain_cannot_leave_and_deactivate_themselves(): void
    {
        [$team] = $this->teamWithOwner();
        EmailDomainFactory::new()->forOwner($team)->create(['domain' => 'acme.com']);
        EmailDomainFactory::new()->verified()->enforced()->forOwner($team)->create(['domain' => 'acme.io']);
        $member = User::factory()->create(['active' => true, 'email' => 'employee@acme.io']);
        $team->addMember($member);

        $this->actingAs($member)
            ->from(config('neev.home'))
            ->delete(route('teams.leave'), ['team_id' => $team->id, 'user_id' => $member->id])
            ->assertSessionHasErrors(['message' => 'You cannot leave a team your email domain manages.']);

        $this->assertTrue($member->fresh()->active);
        $this->assertTrue($team->refresh()->hasMember($member));
    }

    public function test_leave_removes_a_member_on_a_verified_domain_that_is_not_enforced(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        EmailDomainFactory::new()->verified()->forOwner($team)->create(['domain' => 'acme.com']);
        $member = User::factory()->create(['active' => true, 'email' => 'employee@acme.com']);
        $team->addMember($member);

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->delete(route('teams.leave'), ['team_id' => $team->id, 'user_id' => $member->id])
            ->assertSessionHasNoErrors();

        $this->assertTrue($member->fresh()->active);
        $this->assertFalse($team->refresh()->hasMember($member));
    }

    public function test_a_member_on_a_verified_domain_that_is_not_enforced_may_leave(): void
    {
        [$team] = $this->teamWithOwner();
        EmailDomainFactory::new()->verified()->forOwner($team)->create(['domain' => 'acme.com']);
        $member = User::factory()->create(['active' => true, 'email' => 'employee@acme.com']);
        $team->addMember($member);

        $this->actingAs($member)
            ->from(config('neev.home'))
            ->delete(route('teams.leave'), ['team_id' => $team->id, 'user_id' => $member->id])
            ->assertSessionHasNoErrors();

        $this->assertTrue($member->fresh()->active);
        $this->assertFalse($team->refresh()->hasMember($member));
    }

    public function test_leave_refuses_to_deactivate_a_user_who_is_not_a_member(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        EmailDomainFactory::new()->verified()->forOwner($team)->create(['domain' => 'acme.com']);
        // On the team's verified domain, but never joined this team.
        $outsider = User::factory()->create(['active' => true, 'email' => 'someone@acme.com']);

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->delete(route('teams.leave'), ['team_id' => $team->id, 'user_id' => $outsider->id])
            ->assertSessionHasErrors(['message' => 'You cannot perform this action on this team.']);

        $this->assertTrue($outsider->fresh()->active);
    }

    public function test_leave_withdraws_a_pending_invitation_without_deactivating(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        EmailDomainFactory::new()->verified()->forOwner($team)->create(['domain' => 'acme.com']);
        // Invited, not yet joined, on the team's verified domain.
        $invitee = User::factory()->create(['active' => true, 'email' => 'invitee@acme.com']);
        $team->addMember($invitee, joined: false, action: Membership::REQUEST_TO_USER);

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->delete(route('teams.leave'), ['team_id' => $team->id, 'user_id' => $invitee->id])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Removed Successfully');

        $this->assertFalse($team->allUsers()->whereKey($invitee->id)->exists());
        $this->assertTrue($invitee->fresh()->active);
    }

    public function test_leave_lets_a_user_withdraw_their_own_join_request(): void
    {
        [$team] = $this->teamWithOwner();
        $requester = User::factory()->create();
        $team->addMember($requester, joined: false, action: Membership::REQUEST_FROM_USER);

        $this->actingAs($requester)
            ->from(config('neev.home'))
            ->delete(route('teams.leave'), ['team_id' => $team->id])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Removed Successfully');

        $this->assertFalse($team->allUsers()->whereKey($requester->id)->exists());
    }

    public function test_leave_refuses_to_withdraw_someone_elses_pending_membership_for_a_non_member(): void
    {
        [$team] = $this->teamWithOwner();
        $requester = User::factory()->create();
        $team->addMember($requester, joined: false, action: Membership::REQUEST_FROM_USER);

        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->from(config('neev.home'))
            ->delete(route('teams.leave'), ['team_id' => $team->id, 'user_id' => $requester->id])
            ->assertSessionHasErrors(['message' => 'You cannot perform this action on this team.']);

        $this->assertTrue($team->allUsers()->whereKey($requester->id)->exists());
    }

    public function test_account_teams_page_offers_leave_to_a_member_outside_the_verified_domains(): void
    {
        [$team] = $this->teamWithOwner();
        EmailDomainFactory::new()->verified()->forOwner($team)->create(['domain' => 'acme.com']);
        // The server lets this member leave, so the page must offer it.
        $member = User::factory()->create(['email' => 'contractor@elsewhere.com']);
        $team->addMember($member);

        $this->actingAs($member)
            ->get(route('account.teams'))
            ->assertOk()
            ->assertSee('Are you sure you want to leave the team?');
    }

    public function test_account_teams_page_does_not_offer_leave_to_a_member_on_an_enforced_domain(): void
    {
        [$team] = $this->teamWithOwner();
        EmailDomainFactory::new()->forOwner($team)->create(['domain' => 'acme.com']);
        EmailDomainFactory::new()->verified()->enforced()->forOwner($team)->create(['domain' => 'acme.io']);
        $member = User::factory()->create(['email' => 'employee@acme.io']);
        $team->addMember($member);

        $this->actingAs($member)
            ->get(route('account.teams'))
            ->assertOk()
            ->assertDontSee('Are you sure you want to leave the team?');
    }

    public function test_account_teams_page_offers_leave_on_a_verified_domain_that_is_not_enforced(): void
    {
        [$team] = $this->teamWithOwner();
        EmailDomainFactory::new()->verified()->forOwner($team)->create(['domain' => 'acme.io']);
        $member = User::factory()->create(['email' => 'employee@acme.io']);
        $team->addMember($member);

        $this->actingAs($member)
            ->get(route('account.teams'))
            ->assertOk()
            ->assertSee('Are you sure you want to leave the team?');
    }

    public function test_members_page_offers_deactivate_for_a_member_on_a_second_enforced_domain(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        EmailDomainFactory::new()->forOwner($team)->create(['domain' => 'acme.com']);
        EmailDomainFactory::new()->verified()->enforced()->forOwner($team)->create(['domain' => 'acme.io']);
        $member = User::factory()->create(['active' => true, 'email' => 'employee@acme.io']);
        $team->addMember($member);

        $this->actingAs($owner)
            ->get(route('teams.members', $team->id))
            ->assertOk()
            ->assertSee('Deactivate')
            ->assertDontSee('Remove');
    }

    public function test_members_page_offers_remove_for_a_member_on_a_verified_domain_that_is_not_enforced(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        EmailDomainFactory::new()->verified()->forOwner($team)->create(['domain' => 'acme.io']);
        $member = User::factory()->create(['active' => true, 'email' => 'employee@acme.io']);
        $team->addMember($member);

        $this->actingAs($owner)
            ->get(route('teams.members', $team->id))
            ->assertOk()
            ->assertSee('Remove')
            ->assertDontSee('Deactivate');
    }

    public function test_join_request_is_refused_when_a_second_verified_domain_is_enforced(): void
    {
        Mail::fake();
        [$team] = $this->teamWithOwner();
        $team->update(['is_public' => true]);
        EmailDomainFactory::new()->forOwner($team)->create(['domain' => 'acme.com']);
        EmailDomainFactory::new()->verified()->forOwner($team)->create(['domain' => 'acme.io', 'enforce' => true]);
        $requester = User::factory()->create();

        $this->actingAs($requester)
            ->from(config('neev.home'))
            ->post(route('teams.request'), ['team_id' => $team->id])
            ->assertSessionHasErrors();

        $this->assertFalse($team->allUsers()->whereKey($requester->id)->exists());
        Mail::assertNothingSent();
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
                'dns_record_name' => '_neev-email.acme.com',
            ])
            ->get(route('teams.email-domains', $team->id))
            ->assertOk()
            ->assertSee('_neev-email.acme.com')
            ->assertSee('the-token-value');
    }

    /**
     * Members outside the team's domains are counted against the enforced,
     * verified domain only, not against a domain that does not enforce.
     */
    public function test_the_page_warns_about_outside_members_only_on_the_enforced_verified_domain(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        EmailDomainFactory::new()->verified()->forOwner($team)->create(['domain' => 'acme.com', 'enforce' => true]);
        EmailDomainFactory::new()->forOwner($team)->create(['domain' => 'acme.io']);

        $this->actingAs($owner)
            ->get(route('teams.email-domains', $team->id))
            ->assertOk()
            ->assertSee('acme.io')
            ->assertSee('outside your verified domain (@acme.com)', false)
            ->assertDontSee('outside your verified domain (@acme.io)', false);
    }

    public function test_federating_a_domain_that_is_not_a_string_is_refused(): void
    {
        [$team, $owner] = $this->teamWithOwner();

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->post(route('teams.email-domains.store', $team->id), ['domain' => ['acme.com']])
            ->assertSessionHasErrors('domain');

        $this->assertSame(0, EmailDomain::count());
    }

    public function test_rejecting_an_invitation_does_not_remove_a_joined_member(): void
    {
        [$team] = $this->teamWithOwner();
        EmailDomainFactory::new()->verified()->forOwner($team)->create(['domain' => 'acme.com']);
        $member = User::factory()->create(['email' => 'employee@acme.com']);
        $team->addMember($member);

        $this->actingAs($member)
            ->from(config('neev.home'))
            ->put(route('teams.invite.action'), ['team_id' => $team->id, 'action' => 'reject'])
            ->assertSessionHasErrors(['message' => 'Invitation not found.']);

        $this->assertTrue($team->refresh()->hasMember($member));
    }

    public function test_rejecting_a_request_does_not_remove_a_joined_member(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        EmailDomainFactory::new()->verified()->forOwner($team)->create(['domain' => 'acme.com']);
        $member = User::factory()->create(['active' => true, 'email' => 'employee@acme.com']);
        $team->addMember($member);

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->put(route('teams.request.action'), [
                'team_id' => $team->id,
                'user_id' => $member->id,
                'action' => 'reject',
            ])
            ->assertSessionHasErrors(['message' => 'Join request not found.']);

        $this->assertTrue($team->refresh()->hasMember($member));
        $this->assertTrue($member->fresh()->active);
    }

    public function test_removing_a_member_on_an_unverified_domain_detaches_and_reactivates_them(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        EmailDomainFactory::new()->forOwner($team)->create(['domain' => 'acme.com']);
        $member = User::factory()->create(['active' => false, 'email' => 'employee@acme.com']);
        $team->addMember($member);

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->delete(route('teams.leave'), ['team_id' => $team->id, 'user_id' => $member->id])
            ->assertSessionHas('status', 'Removed Successfully');

        $this->assertTrue($member->fresh()->active);
        $this->assertFalse($team->refresh()->hasMember($member));
    }

    /**
     * A member deactivated through a domain the team does not hold was
     * deactivated by someone else; removing them must not undo that.
     */
    public function test_removing_a_deactivated_member_off_the_teams_domains_keeps_them_deactivated(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        EmailDomainFactory::new()->verified()->forOwner($team)->create(['domain' => 'acme.com']);
        $member = User::factory()->create(['active' => false, 'email' => 'someone@other.com']);
        $team->addMember($member);

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->delete(route('teams.leave'), ['team_id' => $team->id, 'user_id' => $member->id])
            ->assertSessionHas('status', 'Removed Successfully');

        $this->assertFalse($member->fresh()->active);
        $this->assertFalse($team->refresh()->hasMember($member));
    }

    public function test_members_page_offers_remove_not_activate_for_a_member_on_an_unverified_domain(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        EmailDomainFactory::new()->forOwner($team)->create(['domain' => 'acme.com']);
        $member = User::factory()->create(['active' => false, 'email' => 'employee@acme.com']);
        $team->addMember($member);

        $this->actingAs($owner)
            ->get(route('teams.members', $team->id))
            ->assertOk()
            ->assertDontSee('Activate');
    }

    /**
     * The Blade delete route goes through the same rule as the API: deleting
     * one team's claim does not undo another team's deactivation.
     */
    public function test_deleting_a_domain_leaves_a_member_another_of_their_teams_claims_deactivated(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        [$other] = $this->teamWithOwner();
        $pending = EmailDomainFactory::new()->forOwner($team)->create(['domain' => 'acme.com']);
        EmailDomainFactory::new()->verified()->forOwner($other)->create(['domain' => 'acme.com']);

        $member = User::factory()->create(['active' => true, 'email' => 'bob@acme.com']);
        $team->addMember($member);
        $other->addMember($member);
        $member->deactivate();

        $this->actingAs($owner)
            ->from(config('neev.home'))
            ->delete(route('teams.email-domains.destroy', $pending->id))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('email_domains', ['id' => $pending->id]);
        $this->assertFalse($member->fresh()->active);
    }

}
