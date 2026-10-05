<?php

namespace Ssntpl\Neev\Tests\Feature\Teams;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Database\Factories\EmailDomainFactory;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Models\EmailDomain;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\Support\FakeDns;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

// Must load before any test calls EmailDomain::verify(); see the file for why.
require_once __DIR__ . '/../../Support/FakeDns.php';

class DomainFederationTest extends TestCase
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
        $this->enableDomainFederation();
    }

    protected function tearDown(): void
    {
        FakeDns::reset();

        parent::tearDown();
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

    protected function bearer(string $token): static
    {
        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    // -----------------------------------------------------------------
    // POST /neev/teams/{team}/email-domains — federate a domain
    // -----------------------------------------------------------------

    /**
     * An email domain is not exclusive: another team having verified it does
     * not stop this one claiming it with its own record.
     */
    public function test_federating_a_domain_another_team_verified_is_allowed(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $other = EmailDomainFactory::new()->verified()->create(['domain' => 'acme.com']);

        $this->bearer($token)
            ->postJson("/neev/teams/{$team->id}/email-domains", ['domain' => 'ACME.com.'])
            ->assertCreated()
            ->assertJsonPath('dns_record.name', '_neev-email.acme.com');

        $this->assertSame(1, $team->emailDomains()->where('domain', 'acme.com')->whereNull('verified_at')->count());
        $this->assertNotNull($other->fresh()->verified_at);
    }

    public function test_owner_can_add_an_email_domain_to_the_team(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $response = $this->bearer($token)
            ->postJson("/neev/teams/{$team->id}/email-domains", ['domain' => 'example.com']);

        $response->assertCreated()
            ->assertJsonPath('message', 'Email domain added.')
            ->assertJsonPath('data.domain', 'example.com')
            ->assertJsonPath('dns_record.type', 'TXT')
            ->assertJsonPath('dns_record.name', '_neev-email.example.com');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $response->json('dns_record.value'));
        $this->assertSame(
            $response->json('dns_record.value'),
            EmailDomain::where('domain', 'example.com')->sole()->verification_token,
        );

        $this->assertDatabaseHas('email_domains', [
            'owner_type' => 'team',
            'owner_id' => $team->id,
            'domain' => 'example.com',
        ]);
    }

    public function test_federating_another_spelling_of_a_held_domain_updates_the_same_row(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        EmailDomainFactory::new()->forOwner($team)->create(['domain' => 'acme.com']);

        $this->bearer($token)
            ->postJson("/neev/teams/{$team->id}/email-domains", ['domain' => 'ACME.com.'])
            ->assertOk()
            ->assertJsonPath('message', 'Verification token issued.')
            ->assertJsonPath('dns_record.name', '_neev-email.acme.com');

        $this->assertSame(1, EmailDomain::where('owner_type', 'team')->where('owner_id', $team->id)->count());
    }

    public function test_federating_requires_a_domain(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $this->bearer($token)
            ->postJson("/neev/teams/{$team->id}/email-domains", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('domain');

        $this->assertSame(0, EmailDomain::count());
    }

    public function test_federating_a_domain_that_is_only_dots_is_refused(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $this->bearer($token)
            ->postJson("/neev/teams/{$team->id}/email-domains", ['domain' => '...'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['domain' => 'The domain must be a host name.']);

        $this->assertSame(0, EmailDomain::count());
    }

    public function test_federating_with_an_enforce_flag_that_is_not_a_boolean_is_refused(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $this->bearer($token)
            ->postJson("/neev/teams/{$team->id}/email-domains", ['domain' => 'acme.com', 'enforce' => 'sometimes'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('enforce');

        $this->assertSame(0, EmailDomain::count());
    }

    public function test_non_owner_cannot_add_an_email_domain(): void
    {
        [$member, $token] = $this->authenticatedUser();

        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->addMember($member);

        $this->bearer($token)
            ->postJson("/neev/teams/{$team->id}/email-domains", ['domain' => 'forbidden.com'])
            ->assertForbidden()
            ->assertExactJson(['message' => 'You do not have permission to do this.']);

        $this->assertDatabaseMissing('email_domains', ['domain' => 'forbidden.com']);
    }

    // -----------------------------------------------------------------
    // GET /neev/teams/{team}/email-domains — list a team's email domains
    // -----------------------------------------------------------------

    public function test_can_list_team_email_domains(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->addMember($owner);

        $alpha = EmailDomainFactory::new()->forOwner($team)->create(['domain' => 'alpha.com']);
        $beta = EmailDomainFactory::new()->forOwner($team)->create(['domain' => 'beta.com']);
        EmailDomainFactory::new()->create(['domain' => 'elsewhere.com']);

        $this->bearer($token)
            ->getJson("/neev/teams/{$team->id}/email-domains")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $alpha->id)
            ->assertJsonPath('data.1.id', $beta->id);
    }

    public function test_non_member_cannot_list_a_teams_email_domains(): void
    {
        [$outsider, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => User::factory()->create()->id]);
        EmailDomainFactory::new()->forOwner($team)->create();

        $this->bearer($token)
            ->getJson("/neev/teams/{$team->id}/email-domains")
            ->assertForbidden();
    }

    public function test_listing_email_domains_of_a_missing_team_is_not_found(): void
    {
        [, $token] = $this->authenticatedUser();

        $this->bearer($token)
            ->getJson('/neev/teams/99999/email-domains')
            ->assertNotFound();
    }

    // -----------------------------------------------------------------
    // GET /neev/email-domains/{emailDomain} — one email domain
    // -----------------------------------------------------------------

    public function test_member_can_view_an_email_domain(): void
    {
        [$member, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => User::factory()->create()->id]);
        $team->addMember($member);
        $domain = EmailDomainFactory::new()->forOwner($team)->create(['domain' => 'acme.com']);

        $this->bearer($token)
            ->getJson('/neev/email-domains/' . $domain->id)
            ->assertOk()
            ->assertJsonPath('data.domain', 'acme.com');
    }

    public function test_non_member_cannot_view_an_email_domain(): void
    {
        [, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => User::factory()->create()->id]);
        $domain = EmailDomainFactory::new()->forOwner($team)->create();

        $this->bearer($token)
            ->getJson('/neev/email-domains/' . $domain->id)
            ->assertForbidden();
    }

    // -----------------------------------------------------------------
    // PATCH /neev/email-domains/{emailDomain} — the enforce flag
    // -----------------------------------------------------------------

    public function test_owner_can_update_the_enforce_flag(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $domain = EmailDomainFactory::new()->forOwner($team)->create(['enforce' => false]);

        $this->bearer($token)
            ->patchJson('/neev/email-domains/' . $domain->id, ['enforce' => true])
            ->assertOk()
            ->assertJsonPath('message', 'Email domain updated.')
            ->assertJsonPath('data.enforce', true);

        $this->assertTrue($domain->fresh()->enforce);
    }

    public function test_updating_requires_the_enforce_flag(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $domain = EmailDomainFactory::new()->forOwner($team)->create();

        $this->bearer($token)
            ->patchJson('/neev/email-domains/' . $domain->id, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('enforce');
    }

    public function test_non_owner_cannot_update_an_email_domain(): void
    {
        [$member, $token] = $this->authenticatedUser();

        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->addMember($member);
        $domain = EmailDomainFactory::new()->forOwner($team)->create(['enforce' => false]);

        $this->bearer($token)
            ->patchJson('/neev/email-domains/' . $domain->id, ['enforce' => true])
            ->assertForbidden();

        $this->assertFalse($domain->fresh()->enforce);
    }

    // -----------------------------------------------------------------
    // POST /neev/email-domains/{emailDomain}/token — a new token
    // -----------------------------------------------------------------

    public function test_owner_can_regenerate_the_verification_token(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $domain = EmailDomainFactory::new()->forOwner($team)->create();
        $old = $domain->verification_token;

        $response = $this->bearer($token)
            ->postJson("/neev/email-domains/{$domain->id}/token")
            ->assertOk()
            ->assertJsonPath('message', 'Verification token issued.')
            ->assertJsonPath('dns_record.type', 'TXT')
            ->assertJsonPath('dns_record.name', '_neev-email.' . $domain->domain);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $response->json('dns_record.value'));
        $this->assertNotSame($old, $response->json('dns_record.value'));
        $this->assertSame($response->json('dns_record.value'), $domain->fresh()->verification_token);
    }

    public function test_regenerating_the_token_unverifies_the_domain(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $domain = EmailDomainFactory::new()->verified()->forOwner($team)->create([
            'verification_failed_at' => now(),
        ]);

        $this->bearer($token)
            ->postJson("/neev/email-domains/{$domain->id}/token")
            ->assertOk();

        $domain->refresh();
        $this->assertNull($domain->verified_at);
        $this->assertNull($domain->verification_failed_at);
    }

    public function test_non_owner_cannot_regenerate_the_token(): void
    {
        [$member, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => User::factory()->create()->id]);
        $team->addMember($member);
        $domain = EmailDomainFactory::new()->verified()->forOwner($team)->create();

        $this->bearer($token)
            ->postJson("/neev/email-domains/{$domain->id}/token")
            ->assertForbidden();

        $this->assertNotNull($domain->fresh()->verified_at);
    }

    public function test_refederating_a_verified_domain_unverifies_it(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $domain = EmailDomainFactory::new()->verified()->forOwner($team)->create(['domain' => 'acme.com']);

        $this->bearer($token)
            ->postJson("/neev/teams/{$team->id}/email-domains", ['domain' => 'acme.com'])
            ->assertOk();

        $this->assertNull($domain->fresh()->verified_at);
    }

    public function test_a_disabled_domain_cannot_be_added_again(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        EmailDomainFactory::new()->forOwner($team)->create(['domain' => 'acme.com', 'status' => EmailDomain::STATUS_DISABLED]);

        $this->bearer($token)
            ->postJson("/neev/teams/{$team->id}/email-domains", ['domain' => 'acme.com', 'enforce' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['domain' => 'This domain is disabled.']);

        $this->assertFalse($team->emailDomains()->sole()->enforce);
    }

    /**
     * Verifying an email domain is not exclusive, enforcing it is (RFC 006
     * §4.2): one owner decides who at the domain may join.
     */
    public function test_only_one_team_may_enforce_an_email_domain(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        EmailDomainFactory::new()->verified()->create(['domain' => 'acme.com', 'enforce' => true]);

        $this->bearer($token)
            ->postJson("/neev/teams/{$team->id}/email-domains", ['domain' => 'acme.com', 'enforce' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['enforce' => 'Another owner already enforces this email domain.']);

        $this->bearer($token)
            ->postJson("/neev/teams/{$team->id}/email-domains", ['domain' => 'acme.com'])
            ->assertCreated();
        $domain = $team->emailDomains()->sole();

        $this->bearer($token)
            ->patchJson("/neev/email-domains/{$domain->id}", ['enforce' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('enforce');

        $this->assertFalse($domain->fresh()->enforce);
    }

    public function test_a_pending_claim_that_enforces_stops_enforcing_if_another_owner_enforces_when_it_verifies(): void
    {
        $team = TeamFactory::new()->create();
        $pending = $team->federateDomain('acme.com', true);
        EmailDomainFactory::new()->verified()->create(['domain' => 'acme.com', 'enforce' => true]);
        FakeDns::txt('_neev-email.acme.com', $pending->verification_token);

        $this->assertTrue($pending->verify());

        $this->assertTrue($pending->fresh()->isVerified());
        $this->assertFalse($pending->fresh()->enforce);
        $this->assertSame(1, EmailDomain::forHost('acme.com')->verified()->where('enforce', true)->count());
    }

    // -----------------------------------------------------------------
    // DELETE /neev/email-domains/{emailDomain}
    // -----------------------------------------------------------------

    public function test_owner_can_delete_an_email_domain(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $domain = EmailDomainFactory::new()->forOwner($team)->create();

        $this->bearer($token)
            ->deleteJson('/neev/email-domains/' . $domain->id)
            ->assertOk()
            ->assertJsonPath('message', 'Email domain deleted.');

        $this->assertDatabaseMissing('email_domains', ['id' => $domain->id]);
    }

    public function test_deleting_an_email_domain_keeps_the_teams_other_domains(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $kept = EmailDomainFactory::new()->verified()->forOwner($team)->create();
        $other = EmailDomainFactory::new()->verified()->forOwner($team)->create();

        $this->bearer($token)
            ->deleteJson('/neev/email-domains/' . $other->id)
            ->assertOk();

        $this->assertNull($other->fresh());
        $this->assertNotNull($kept->fresh()->verified_at);
    }

    public function test_non_owner_cannot_delete_an_email_domain(): void
    {
        [$member, $token] = $this->authenticatedUser();

        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->addMember($member);
        $domain = EmailDomainFactory::new()->forOwner($team)->create();

        $this->bearer($token)
            ->deleteJson('/neev/email-domains/' . $domain->id)
            ->assertForbidden();

        $this->assertDatabaseHas('email_domains', ['id' => $domain->id]);
    }

    // -----------------------------------------------------------------
    // PUT /neev/teams/{team}/rules — the team's rules, on its auth settings
    // -----------------------------------------------------------------

    public function test_owner_can_update_the_team_rules(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $this->bearer($token)
            ->putJson("/neev/teams/{$team->id}/rules", ['mfa' => true])
            ->assertOk()
            ->assertJsonPath('message', 'Domain Rules have been updated.')
            ->assertJsonPath('data', [['name' => 'mfa', 'value' => true]]);

        $this->assertTrue($team->authSettings()->sole()->require_mfa);
    }

    public function test_a_rule_left_out_of_the_request_keeps_its_value(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->authSettings()->create(['require_mfa' => true]);

        $this->bearer($token)
            ->putJson("/neev/teams/{$team->id}/rules", [])
            ->assertOk()
            ->assertJsonPath('data', [['name' => 'mfa', 'value' => true]]);

        $this->assertTrue($team->authSettings()->sole()->require_mfa);
    }

    public function test_non_owner_cannot_update_the_team_rules(): void
    {
        [$member, $token] = $this->authenticatedUser();

        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->addMember($member);

        $this->bearer($token)
            ->putJson("/neev/teams/{$team->id}/rules", ['mfa' => true])
            ->assertForbidden()
            ->assertJsonPath('message', 'You do not have the required permissions to update domain rules.');

        $this->assertNull($team->authSettings()->first());
    }

    public function test_updating_the_rules_of_a_missing_team_is_not_found(): void
    {
        [, $token] = $this->authenticatedUser();

        $this->bearer($token)
            ->putJson('/neev/teams/99999/rules', ['mfa' => true])
            ->assertNotFound();
    }

    // -----------------------------------------------------------------
    // GET /neev/teams/{team}/rules — get the team's rules
    // -----------------------------------------------------------------

    public function test_member_can_get_the_team_rules(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->allUsers()->attach($owner, ['joined' => true]);
        $team->authSettings()->create(['require_mfa' => true]);

        $this->bearer($token)
            ->getJson("/neev/teams/{$team->id}/rules")
            ->assertOk()
            ->assertJsonPath('data', [['name' => 'mfa', 'value' => true]]);
    }

    public function test_a_team_without_settings_has_every_rule_off(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->allUsers()->attach($owner, ['joined' => true]);

        $this->bearer($token)
            ->getJson("/neev/teams/{$team->id}/rules")
            ->assertOk()
            ->assertJsonPath('data', [['name' => 'mfa', 'value' => false]]);
    }

    public function test_non_member_cannot_get_the_team_rules(): void
    {
        [, $token] = $this->authenticatedUser();

        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $this->bearer($token)
            ->getJson("/neev/teams/{$team->id}/rules")
            ->assertForbidden()
            ->assertJsonPath('message', 'You do not have the required permissions to get domain rules.');
    }

    public function test_getting_the_rules_of_a_missing_team_is_not_found(): void
    {
        [, $token] = $this->authenticatedUser();

        $this->bearer($token)
            ->getJson('/neev/teams/99999/rules')
            ->assertNotFound();
    }

    // -----------------------------------------------------------------
    // GET /neev/teams/{team}/email-domains — outside member count
    // -----------------------------------------------------------------

    public function test_listing_shows_the_outside_member_count_for_an_enforced_verified_domain(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $owner->forceFill(['email' => 'owner@company.com'])->save();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->allUsers()->attach($owner, ['joined' => true]);

        $enforced = EmailDomainFactory::new()->verified()->forOwner($team)->create([
            'domain' => 'company.com',
            'enforce' => true,
        ]);
        $relaxed = EmailDomainFactory::new()->verified()->forOwner($team)->create([
            'domain' => 'company.io',
            'enforce' => false,
        ]);
        $pending = EmailDomainFactory::new()->forOwner($team)->create([
            'domain' => 'company.net',
            'enforce' => true,
        ]);

        // A member with an email outside the domain.
        $outsideMember = User::factory()->create(['email' => 'someone@gmail.com']);
        $team->allUsers()->attach($outsideMember, ['joined' => true]);

        $data = collect($this->bearer($token)
            ->getJson("/neev/teams/{$team->id}/email-domains")
            ->assertOk()
            ->json('data'))->keyBy('id');

        $this->assertSame(1, $data[$enforced->id]['outside_members']);
        $this->assertArrayNotHasKey('outside_members', $data[$relaxed->id]);
        $this->assertArrayNotHasKey('outside_members', $data[$pending->id]);
    }

    public function test_listing_does_not_flag_a_member_on_a_second_federated_domain(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $owner->forceFill(['email' => 'owner@acme.com'])->save();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->allUsers()->attach($owner, ['joined' => true]);

        foreach (['acme.com', 'acme.io'] as $domain) {
            EmailDomainFactory::new()->verified()->forOwner($team)->create([
                'domain' => $domain,
                'enforce' => true,
            ]);
        }

        $member = User::factory()->create(['email' => 'member@acme.io']);
        $team->allUsers()->attach($member, ['joined' => true]);

        $response = $this->bearer($token)
            ->getJson("/neev/teams/{$team->id}/email-domains")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        foreach ($response->json('data') as $domain) {
            $this->assertSame(0, $domain['outside_members']);
        }
    }

    // -----------------------------------------------------------------
    // GET /teams/{team}/email-domains — the web page's "outside members"
    // warning counts a member against every federated domain at once
    // -----------------------------------------------------------------

    public function test_a_member_on_a_second_federated_domain_is_not_flagged_as_outside(): void
    {
        $owner = User::factory()->create(['email' => 'owner@acme.com']);
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->allUsers()->attach($owner, ['joined' => true]);

        foreach (['acme.com', 'acme.io'] as $domain) {
            EmailDomainFactory::new()->verified()->create([
                'owner_type' => 'team', 'owner_id' => $team->id,
                'domain' => $domain,
                'enforce' => true,
            ]);
        }

        // On acme.io — inside the team's boundary, just not on acme.com.
        $member = User::factory()->create(['email' => 'member@acme.io']);
        $team->allUsers()->attach($member, ['joined' => true]);

        $response = $this->actingAs($owner)->get(route('teams.email-domains', ['team' => $team->id]));

        $response->assertOk();

        foreach ($response->viewData('outsideMembers') as $count) {
            $this->assertSame(0, $count);
        }
    }

    public function test_a_member_outside_every_federated_domain_is_flagged(): void
    {
        $owner = User::factory()->create(['email' => 'owner@acme.com']);
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->allUsers()->attach($owner, ['joined' => true]);

        EmailDomainFactory::new()->verified()->create([
            'owner_type' => 'team', 'owner_id' => $team->id,
            'domain' => 'acme.com',
            'enforce' => true,
        ]);

        $outsider = User::factory()->create(['email' => 'someone@gmail.com']);
        $team->allUsers()->attach($outsider, ['joined' => true]);

        $response = $this->actingAs($owner)->get(route('teams.email-domains', ['team' => $team->id]));

        $response->assertOk();
        $this->assertSame([1], array_values($response->viewData('outsideMembers')));
    }

    // -----------------------------------------------------------------
    // POST /neev/teams/{team}/email-domains — with enforcement
    // -----------------------------------------------------------------

    public function test_add_email_domain_with_enforcement(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $this->bearer($token)
            ->postJson("/neev/teams/{$team->id}/email-domains", [
                'domain' => 'enforced-domain.com',
                'enforce' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.enforce', true);

        $domain = EmailDomain::where('domain', 'enforced-domain.com')->first();
        $this->assertNotNull($domain);
        $this->assertTrue($domain->enforce);
    }

    // -----------------------------------------------------------------
    // POST /neev/email-domains/{emailDomain}/verify — DNS lookup
    // -----------------------------------------------------------------

    public function test_verify_fails_when_the_dns_record_is_not_found(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $domain = EmailDomainFactory::new()->forOwner($team)->create([
            'domain' => 'nonexistent-test-domain-' . uniqid() . '.invalid',
            'verification_token' => 'test-verification-token',
            'verified_at' => null,
        ]);

        $this->bearer($token)
            ->postJson("/neev/email-domains/{$domain->id}/verify")
            ->assertStatus(400)
            ->assertJsonPath('message', 'DNS verification failed. Please check your DNS record.');

        $this->assertNull($domain->fresh()->verified_at);
    }

    /**
     * The proof lives under the email record name; a TXT record under the
     * host record name does not verify an email domain.
     */
    public function test_verify_ignores_a_record_under_the_host_name(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $domain = $team->federateDomain('acme.com', false);

        FakeDns::txt('_neev-host.acme.com', $domain->verification_token);

        $this->bearer($token)
            ->postJson("/neev/email-domains/{$domain->id}/verify")
            ->assertStatus(400);

        $this->assertNull($domain->fresh()->verified_at);
    }

    public function test_non_owner_cannot_verify_an_email_domain(): void
    {
        [$member, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => User::factory()->create()->id]);
        $team->addMember($member);
        $domain = $team->federateDomain('acme.com', false);

        FakeDns::txt('_neev-email.acme.com', $domain->verification_token);

        $this->bearer($token)
            ->postJson("/neev/email-domains/{$domain->id}/verify")
            ->assertForbidden();

        $this->assertNull($domain->fresh()->verified_at);
    }

    /**
     * A new token unverifies the domain; publishing it under the email record
     * name verifies it again.
     */
    public function test_verifying_again_after_a_new_token_reads_the_new_record(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $domain = EmailDomainFactory::new()->verified()->forOwner($team)->create(['domain' => 'acme.com']);

        $newToken = $this->bearer($token)
            ->postJson("/neev/email-domains/{$domain->id}/token")
            ->assertOk()
            ->json('dns_record.value');

        $this->assertNull($domain->fresh()->verified_at);

        FakeDns::txt('_neev-email.acme.com', $newToken);

        $this->bearer($token)
            ->postJson("/neev/email-domains/{$domain->id}/verify")
            ->assertOk()
            ->assertJsonPath('message', 'Email domain verified.')
            ->assertJsonPath('data.id', $domain->id);

        $this->assertNotNull($domain->fresh()->verified_at);
    }

    /**
     * Verification is not exclusive: another team having verified the domain
     * does not stop this one verifying it by its own record.
     */
    public function test_verify_succeeds_for_a_domain_another_team_already_verified(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $other = EmailDomainFactory::new()->verified()->create(['domain' => 'acme.com']);
        $claim = $team->federateDomain('acme.com', false);

        FakeDns::txt('_neev-email.acme.com', $claim->verification_token);

        $this->bearer($token)
            ->postJson("/neev/email-domains/{$claim->id}/verify")
            ->assertOk()
            ->assertJsonPath('message', 'Email domain verified.');

        $this->assertNotNull($claim->fresh()->verified_at);
        $this->assertNotNull($other->fresh()->verified_at);
    }

    // -----------------------------------------------------------------
    // Unknown ids — route model binding answers 404
    // -----------------------------------------------------------------

    public function test_unknown_email_domain_ids_are_not_found(): void
    {
        [, $token] = $this->authenticatedUser();

        $this->bearer($token)->getJson('/neev/email-domains/99999')->assertNotFound();
        $this->bearer($token)->patchJson('/neev/email-domains/99999', ['enforce' => true])->assertNotFound();
        $this->bearer($token)->deleteJson('/neev/email-domains/99999')->assertNotFound();
        $this->bearer($token)->postJson('/neev/email-domains/99999/verify')->assertNotFound();
        $this->bearer($token)->postJson('/neev/email-domains/99999/token')->assertNotFound();
    }

    public function test_add_email_domain_to_a_missing_team_is_not_found(): void
    {
        [, $token] = $this->authenticatedUser();

        $this->bearer($token)
            ->postJson('/neev/teams/99999/email-domains', ['domain' => 'orphan.com'])
            ->assertNotFound();

        $this->assertSame(0, EmailDomain::count());
    }

    // -----------------------------------------------------------------
    // Malformed input
    // -----------------------------------------------------------------

    public function test_federating_a_domain_that_is_not_a_string_is_refused(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $this->bearer($token)
            ->postJson("/neev/teams/{$team->id}/email-domains", ['domain' => ['acme.com']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('domain');

        $this->assertSame(0, EmailDomain::count());
    }

    public function test_federating_something_that_is_not_a_host_name_is_refused(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        foreach (['https://acme.com/x', 'ac me.com', 'acme.com:8080', str_repeat('a', 250) . '.com'] as $value) {
            $this->bearer($token)
                ->postJson("/neev/teams/{$team->id}/email-domains", ['domain' => $value])
                ->assertStatus(422)
                ->assertJsonValidationErrors('domain');
        }

        $this->assertSame(0, EmailDomain::count());
    }

    // -----------------------------------------------------------------
    // Deleting a domain gives back the accounts it deactivated
    // -----------------------------------------------------------------

    /**
     * A verified domain deactivates members without detaching them. Once it
     * is deleted nothing manages them, so their accounts come back rather than
     * staying locked with nothing left to undo it.
     */
    public function test_deleting_a_verified_domain_reactivates_the_members_it_deactivated(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->addMember($owner);
        $domain = EmailDomainFactory::new()->verified()->forOwner($team)->create(['domain' => 'acme.com']);
        $deactivated = User::factory()->create(['active' => true, 'email' => 'bob@ACME.com']);
        $team->addMember($deactivated);
        $elsewhere = User::factory()->create(['active' => true, 'email' => 'eve@other.com']);
        $team->addMember($elsewhere);
        $deactivated->deactivate();
        $elsewhere->deactivate();

        $this->bearer($token)
            ->deleteJson('/neev/email-domains/' . $domain->id)
            ->assertOk();

        $this->assertTrue($deactivated->fresh()->active);
        $this->assertFalse($elsewhere->fresh()->active, 'Only members on the deleted host are reactivated.');
        $this->assertTrue($team->refresh()->hasMember($deactivated));
    }

    /**
     * A new token unverifies the domain but leaves the members it deactivated
     * as they were. Deleting it then must still give their accounts back, or
     * nothing is left that could.
     */
    public function test_deleting_a_domain_a_new_token_unverified_reactivates_the_members_it_deactivated(): void
    {
        [$owner, $token] = $this->authenticatedUser();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->addMember($owner);
        $domain = EmailDomainFactory::new()->verified()->forOwner($team)->create(['domain' => 'acme.com']);
        $member = User::factory()->create(['active' => true, 'email' => 'bob@acme.com']);
        $team->addMember($member);

        $this->bearer($token)
            ->putJson('/neev/teams/leave', ['team_id' => $team->id, 'user_id' => $member->id])
            ->assertJsonPath('message', 'User Deactivated Successfully');

        $this->bearer($token)
            ->postJson("/neev/email-domains/{$domain->id}/token")
            ->assertOk();

        $this->bearer($token)
            ->deleteJson('/neev/email-domains/' . $domain->id)
            ->assertOk();

        $this->assertTrue($member->fresh()->active);
        $this->assertTrue($team->refresh()->hasMember($member));
    }

    // -----------------------------------------------------------------
    // Removing a deactivated member another team may answer for
    // -----------------------------------------------------------------

    /**
     * Team A verified acme.com and deactivated Bob. Team B holds a pending
     * claim on the same host and also has Bob as a member. Removing Bob from
     * team B must not undo team A's deactivation.
     */
    public function test_removing_a_deactivated_member_leaves_them_deactivated_when_another_of_their_teams_claims_the_host(): void
    {
        $ownerA = User::factory()->create();
        $teamA = TeamFactory::new()->create(['user_id' => $ownerA->id]);
        $teamA->addMember($ownerA);

        [$ownerB, $tokenB] = $this->authenticatedUser();
        $teamB = TeamFactory::new()->create(['user_id' => $ownerB->id]);
        $teamB->addMember($ownerB);

        EmailDomainFactory::new()->create([
            'owner_type' => 'team', 'owner_id' => $teamB->id,
            'domain' => 'acme.com',
        ]);
        EmailDomainFactory::new()->verified()->create([
            'owner_type' => 'team', 'owner_id' => $teamA->id,
            'domain' => 'acme.com',
        ]);

        $bob = User::factory()->create(['active' => true, 'email' => 'bob@acme.com']);
        $teamA->addMember($bob);
        $teamB->addMember($bob);
        $bob->deactivate();

        $this->bearer($tokenB)
            ->putJson('/neev/teams/leave', ['team_id' => $teamB->id, 'user_id' => $bob->id])
            ->assertOk()
            ->assertJsonPath('message', 'Removed Successfully');

        $this->assertFalse($bob->fresh()->active);
        $this->assertFalse($teamB->refresh()->hasMember($bob));
        $this->assertTrue($teamA->refresh()->hasMember($bob));
    }

    /**
     * Team A verified acme.com and deactivated Bob. Team B, which Bob also
     * belongs to, deletes its own pending claim on the host. That must not
     * undo team A's deactivation; team A deleting its domain later does.
     */
    public function test_deleting_a_domain_leaves_members_deactivated_when_another_of_their_teams_claims_the_host(): void
    {
        [$ownerA, $tokenA] = $this->authenticatedUser();
        $teamA = TeamFactory::new()->create(['user_id' => $ownerA->id]);
        $teamA->addMember($ownerA);

        [$ownerB, $tokenB] = $this->authenticatedUser();
        $teamB = TeamFactory::new()->create(['user_id' => $ownerB->id]);
        $teamB->addMember($ownerB);

        $pending = EmailDomainFactory::new()->create([
            'owner_type' => 'team', 'owner_id' => $teamB->id,
            'domain' => 'acme.com',
        ]);
        $verified = EmailDomainFactory::new()->verified()->create([
            'owner_type' => 'team', 'owner_id' => $teamA->id,
            'domain' => 'acme.com',
        ]);

        $bob = User::factory()->create(['active' => true, 'email' => 'bob@acme.com']);
        $teamA->addMember($bob);
        $teamB->addMember($bob);
        $bob->deactivate();

        $this->bearer($tokenB)
            ->deleteJson('/neev/email-domains/' . $pending->id)
            ->assertOk();

        $this->assertFalse($bob->fresh()->active, 'Team B deleting its claim must not undo team A.');

        $this->bearer($tokenA)
            ->deleteJson('/neev/email-domains/' . $verified->id)
            ->assertOk();

        $this->assertTrue($bob->fresh()->active, 'With no other claim left, team A deleting its domain reactivates Bob.');
    }
}
