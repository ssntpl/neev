<?php

namespace Ssntpl\Neev\Tests\Unit\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Ssntpl\Neev\Database\Factories\EmailDomainFactory;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Database\Factories\TenantFactory;
use Ssntpl\Neev\Events\DomainReverified;
use Ssntpl\Neev\Events\DomainVerified;
use Ssntpl\Neev\Models\EmailDomain;
use Ssntpl\Neev\Models\Tenant;
use Ssntpl\Neev\Tests\Support\FakeDns;
use Ssntpl\Neev\Tests\TestCase;

// Must load before any test calls verify(); see the file for why.
require_once __DIR__ . '/../../Support/FakeDns.php';

class EmailDomainCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        FakeDns::reset();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // neev:email-domain:add
    // -----------------------------------------------------------------

    public function test_add_claims_a_pending_email_domain(): void
    {
        $team = TeamFactory::new()->create();

        $this->artisan('neev:email-domain:add', ['domain' => 'ACME.com', '--owner-type' => 'team', '--owner-id' => (string) $team->id])
            ->expectsOutputToContain('Domain added: acme.com')
            ->expectsOutputToContain('_neev-email.acme.com')
            ->expectsOutputToContain('neev:email-domain:verify acme.com')
            ->assertSuccessful();

        $domain = $team->emailDomains()->sole();
        $this->assertSame('acme.com', $domain->domain);
        $this->assertFalse($domain->isVerified());
        $this->assertFalse($domain->enforce);
        $this->assertNotNull($domain->verification_token);
        $this->assertDatabaseCount('hostnames', 0);
    }

    public function test_add_with_enforce_and_skip_verification(): void
    {
        $team = TeamFactory::new()->create();

        $this->artisan('neev:email-domain:add', [
            'domain' => 'acme.com',
            '--owner-type' => 'team',
            '--owner-id' => $team->slug,
            '--enforce' => true,
            '--skip-verification' => true,
        ])
            ->expectsOutputToContain('Domain added and verified: acme.com')
            ->assertSuccessful();

        $domain = $team->emailDomains()->sole();
        $this->assertSame(EmailDomain::STATUS_VERIFIED, $domain->status);
        $this->assertSame(EmailDomain::STRATEGY_MANUAL, $domain->verification_strategy);
        $this->assertTrue($domain->enforce);
    }

    /**
     * An email domain is not exclusive: two teams may both add, and both
     * verify, one domain.
     */
    public function test_two_teams_may_add_the_same_domain(): void
    {
        $teams = [TeamFactory::new()->create(), TeamFactory::new()->create()];

        foreach ($teams as $team) {
            $this->artisan('neev:email-domain:add', [
                'domain' => 'acme.com',
                '--owner-type' => 'team',
                '--owner-id' => (string) $team->id,
                '--skip-verification' => true,
            ])->assertSuccessful();
        }

        $this->assertSame(2, EmailDomain::forHost('acme.com')->verified()->count());
    }

    public function test_add_refuses_a_domain_the_owner_already_has(): void
    {
        $team = TeamFactory::new()->create();
        $team->emailDomains()->create(['domain' => 'acme.com']);

        $this->artisan('neev:email-domain:add', ['domain' => 'acme.com', '--owner-type' => 'team', '--owner-id' => (string) $team->id])
            ->expectsOutputToContain('Domain already added for this team: acme.com')
            ->assertFailed();

        $this->assertSame(1, $team->emailDomains()->count());
    }

    public function test_add_rejects_an_unknown_owner_type(): void
    {
        $this->artisan('neev:email-domain:add', ['domain' => 'acme.com', '--owner-type' => 'group', '--owner-id' => '1'])
            ->expectsOutputToContain('--owner-type must be "team" or "tenant".')
            ->assertFailed();
    }

    // -----------------------------------------------------------------
    // neev:email-domain:list
    // -----------------------------------------------------------------

    public function test_list_shows_domains(): void
    {
        $team = TeamFactory::new()->create();
        $domain = EmailDomainFactory::new()->forOwner($team)->verified()->create(['domain' => 'acme.com', 'enforce' => true]);

        $this->artisan('neev:email-domain:list')
            ->expectsTable(
                ['ID', 'Domain', 'Owner Type', 'Owner ID', 'Enforce', 'Status'],
                [[$domain->id, 'acme.com', 'team', $team->id, 'Yes', 'Verified']],
            )
            ->assertSuccessful();
    }

    public function test_list_filters_by_owner(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        EmailDomainFactory::new()->forOwner($tenant)->create(['domain' => 'mine.com']);
        EmailDomainFactory::new()->create(['domain' => 'other.com']);

        $this->artisan('neev:email-domain:list', ['--owner-type' => 'tenant', '--owner-id' => 'acme'])
            ->expectsOutputToContain('mine.com')
            ->doesntExpectOutputToContain('other.com')
            ->assertSuccessful();
    }

    public function test_list_hides_verified_rows_with_unverified(): void
    {
        EmailDomainFactory::new()->verified()->create(['domain' => 'proven.com']);
        EmailDomainFactory::new()->create(['domain' => 'pending.com']);

        $this->artisan('neev:email-domain:list', ['--unverified' => true])
            ->expectsOutputToContain('pending.com')
            ->doesntExpectOutputToContain('proven.com')
            ->assertSuccessful();
    }

    public function test_list_reports_an_unknown_slug(): void
    {
        $this->artisan('neev:email-domain:list', ['--owner-type' => 'team', '--owner-id' => 'no-such-team'])
            ->expectsOutputToContain('Team not found: no-such-team')
            ->assertFailed();
    }

    public function test_list_needs_the_id_for_a_team_slug_held_in_several_tenants(): void
    {
        config(['neev.tenant' => true]);
        foreach ([TenantFactory::new()->create(), TenantFactory::new()->create()] as $tenant) {
            TeamFactory::new()->create(['slug' => 'engineering', 'tenant_id' => $tenant->id]);
        }

        $this->artisan('neev:email-domain:list', ['--owner-type' => 'team', '--owner-id' => 'engineering'])
            ->expectsOutputToContain('Team slug engineering is used in more than one tenant; pass the team ID instead.')
            ->assertFailed();
    }

    // -----------------------------------------------------------------
    // neev:email-domain:verify
    // -----------------------------------------------------------------

    public function test_verify_checks_the_email_record(): void
    {
        $domain = TeamFactory::new()->create()->federateDomain('acme.com', false);
        FakeDns::txt('_neev-email.acme.com', $domain->verification_token);

        $this->artisan('neev:email-domain:verify', ['domain' => 'ACME.com.'])
            ->expectsOutputToContain('Checking DNS TXT record: _neev-email.acme.com')
            ->expectsOutputToContain('Domain verified successfully: acme.com')
            ->assertSuccessful();

        $this->assertTrue($domain->fresh()->isVerified());
    }

    public function test_verify_fails_when_the_record_does_not_match(): void
    {
        $domain = TeamFactory::new()->create()->federateDomain('acme.com', false);
        FakeDns::txt('_neev-email.acme.com', 'someone-elses-token');

        $this->artisan('neev:email-domain:verify', ['domain' => 'acme.com'])
            ->expectsOutputToContain('DNS verification failed.')
            ->assertFailed();

        $this->assertFalse($domain->fresh()->isVerified());
    }

    public function test_verify_refuses_to_guess_between_several_owners(): void
    {
        $first = EmailDomainFactory::new()->create(['domain' => 'acme.com']);
        $second = EmailDomainFactory::new()->create(['domain' => 'acme.com']);

        $this->artisan('neev:email-domain:verify', ['domain' => 'acme.com', '--force' => true])
            ->expectsOutputToContain('Domain is claimed by more than one owner: acme.com')
            ->expectsOutputToContain('Pick one with --owner-type and --owner-id.')
            ->assertFailed();

        $this->assertFalse($first->fresh()->isVerified());
        $this->assertFalse($second->fresh()->isVerified());
    }

    public function test_verify_owner_options_pick_one_claim(): void
    {
        $team = TeamFactory::new()->create();
        $other = EmailDomainFactory::new()->create(['domain' => 'acme.com']);
        $mine = EmailDomainFactory::new()->forOwner($team)->create(['domain' => 'acme.com']);

        $this->artisan('neev:email-domain:verify', [
            'domain' => 'acme.com',
            '--owner-type' => 'team',
            '--owner-id' => $team->slug,
            '--force' => true,
        ])
            ->expectsOutputToContain('Domain force-verified: acme.com')
            ->assertSuccessful();

        $this->assertTrue($mine->fresh()->isVerified());
        $this->assertFalse($other->fresh()->isVerified());
    }

    /**
     * An email domain is not exclusive: one team verifying `acme.com` does
     * not stop another from proving it too.
     */
    public function test_verify_succeeds_for_a_domain_another_owner_verified(): void
    {
        $team = TeamFactory::new()->create();
        EmailDomainFactory::new()->verified()->create(['domain' => 'acme.com']);
        $claim = $team->federateDomain('acme.com', false);
        FakeDns::txt('_neev-email.acme.com', $claim->verification_token);

        $this->artisan('neev:email-domain:verify', [
            'domain' => 'acme.com',
            '--owner-type' => 'team',
            '--owner-id' => (string) $team->id,
        ])->assertSuccessful();

        $this->assertTrue($claim->fresh()->isVerified());
    }

    public function test_force_on_a_failing_domain_fires_reverified(): void
    {
        Event::fake([DomainVerified::class, DomainReverified::class]);
        EmailDomainFactory::new()->verified()->create([
            'domain' => 'acme.com',
            'status' => EmailDomain::STATUS_FAILED,
            'verification_failed_at' => now()->subDay(),
        ]);

        $this->artisan('neev:email-domain:verify', ['domain' => 'acme.com', '--force' => true])->assertSuccessful();

        Event::assertDispatched(DomainReverified::class);
        Event::assertNotDispatched(DomainVerified::class);
    }

    public function test_add_refuses_to_enforce_a_domain_another_owner_enforces(): void
    {
        EmailDomainFactory::new()->verified()->create(['domain' => 'acme.com', 'enforce' => true]);
        $team = TeamFactory::new()->create();

        $this->artisan('neev:email-domain:add', [
            'domain' => 'acme.com',
            '--owner-type' => 'team',
            '--owner-id' => (string) $team->id,
            '--enforce' => true,
        ])
            ->expectsOutputToContain('Another owner already enforces this email domain. (acme.com)')
            ->assertFailed();

        $this->assertSame(0, $team->emailDomains()->count());
    }

    public function test_verify_reports_an_unknown_domain(): void
    {
        $this->artisan('neev:email-domain:verify', ['domain' => 'acme.com'])
            ->expectsOutputToContain('Domain not found: acme.com')
            ->assertFailed();
    }
}
