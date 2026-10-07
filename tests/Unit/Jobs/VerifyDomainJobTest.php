<?php

namespace Ssntpl\Neev\Tests\Unit\Jobs;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Ssntpl\Neev\Database\Factories\EmailDomainFactory;
use Ssntpl\Neev\Database\Factories\HostnameFactory;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Database\Factories\TenantFactory;
use Ssntpl\Neev\Events\DomainRemoved;
use Ssntpl\Neev\Events\DomainReverified;
use Ssntpl\Neev\Events\DomainUnverified;
use Ssntpl\Neev\Events\DomainVerificationFailed;
use Ssntpl\Neev\Events\DomainVerified;
use Ssntpl\Neev\Events\EmailDomainEnforceDropped;
use Ssntpl\Neev\Jobs\VerifyDomainJob;
use Ssntpl\Neev\Models\EmailDomain;
use Ssntpl\Neev\Models\Hostname;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\Support\FakeDns;
use Ssntpl\Neev\Tests\TestCase;

// Must load before any test calls verify(); see the file for why.
require_once __DIR__ . '/../../Support/FakeDns.php';

class VerifyDomainJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        FakeDns::reset();

        parent::tearDown();
    }

    /**
     * The job re-checks a verified row. One sent back to pending after it
     * was queued is a claim whose record may not be published yet; the job
     * must not mark that claim failed.
     */
    public function test_skips_a_domain_made_pending_after_it_was_queued(): void
    {
        Event::fake([DomainVerified::class, DomainVerificationFailed::class]);
        $domain = EmailDomainFactory::new()->verified()->create([
            'domain' => 'acme.com',
            'verification_token' => 'old',
        ]);
        $job = new VerifyDomainJob($domain);

        EmailDomain::whereKey($domain->id)->update([
            'verified_at' => null,
            'status' => EmailDomain::STATUS_PENDING,
            'verification_token' => 'new',
        ]);

        // The queue serialises the model and loads it again when the job runs.
        unserialize(serialize($job))->handle();

        $domain->refresh();
        $this->assertNull($domain->verified_at);
        $this->assertNull($domain->verification_failed_at);
        Event::assertNotDispatched(DomainVerified::class);
        Event::assertNotDispatched(DomainVerificationFailed::class);
    }

    /**
     * The job re-checks verified rows only. A pending claim is left alone
     * even with its record published; it is verified through verify() or
     * neev:hostname:verify or neev:email-domain:verify.
     */
    public function test_does_not_verify_a_pending_claim_even_with_its_record_published(): void
    {
        Event::fake([DomainVerified::class]);
        $claim = HostnameFactory::new()->create([
            'host' => 'app.acme.com',
            'verification_token' => 'published',
        ]);
        FakeDns::txt('_neev-host.app.acme.com', 'published');

        (new VerifyDomainJob($claim))->handle();

        $this->assertNull($claim->fresh()->verified_at);
        Event::assertNotDispatched(DomainVerified::class);
    }

    /**
     * A verified email domain is still re-checked: with no record published,
     * the failure is recorded and the domain stays verified for now.
     */
    public function test_re_checks_a_verified_email_domain_and_records_a_missing_record(): void
    {
        FakeDns::txt('_neev-email.acme.com');
        $domain = EmailDomainFactory::new()->verified()->create([
            'domain' => 'acme.com',
            'verification_token' => 'published',
        ]);

        (new VerifyDomainJob($domain))->handle();

        $domain->refresh();
        $this->assertNotNull($domain->verified_at);
        $this->assertNotNull($domain->verification_failed_at);
        $this->assertSame(EmailDomain::STATUS_FAILED, $domain->status);
    }

    /**
     * A row restored by the re-check while another owner has since started
     * enforcing is restored without enforce. Nobody is watching the job, so
     * the event is how the owner hears of it.
     */
    public function test_restoring_a_row_another_owner_now_enforces_fires_enforce_dropped(): void
    {
        Event::fake([EmailDomainEnforceDropped::class]);
        FakeDns::txt('_neev-email.acme.com', 'published');
        $domain = EmailDomainFactory::new()->create([
            'domain' => 'acme.com',
            'verification_token' => 'published',
            'enforce' => true,
            'status' => EmailDomain::STATUS_FAILED,
            'verification_failed_at' => now()->subDays(8),
        ]);
        EmailDomainFactory::new()->verified()->create(['domain' => 'acme.com', 'enforce' => true]);

        (new VerifyDomainJob($domain))->handle();

        $domain->refresh();
        $this->assertTrue($domain->isVerified());
        $this->assertFalse($domain->enforce);
        Event::assertDispatched(EmailDomainEnforceDropped::class);
    }

    /**
     * A new token leaves the row verified, and the re-check holds it to the
     * new token's record: the old record no longer counts.
     */
    public function test_re_checks_a_verified_row_against_its_new_token(): void
    {
        Event::fake([DomainVerificationFailed::class]);
        FakeDns::txt('_neev-host.app.acme.com', 'old');
        $hostname = HostnameFactory::new()->verified()->create([
            'host' => 'app.acme.com',
            'verification_token' => 'old',
        ]);

        $hostname->generateVerificationToken();
        $this->assertTrue($hostname->fresh()->isVerified());

        (new VerifyDomainJob($hostname->fresh()))->handle();

        $hostname->refresh();
        $this->assertTrue($hostname->isVerified(), 'It keeps serving until the window runs out.');
        $this->assertSame(Hostname::STATUS_FAILED, $hostname->status);
        Event::assertDispatched(DomainVerificationFailed::class);

        FakeDns::txt('_neev-host.app.acme.com', $hostname->verification_token);
        (new VerifyDomainJob($hostname))->handle();

        $hostname->refresh();
        $this->assertSame(Hostname::STATUS_VERIFIED, $hostname->status);
        $this->assertNull($hostname->verification_failed_at);
    }

    /**
     * A failure streak is dated from its first missed check, so a later miss
     * does not move it.
     */
    public function test_a_later_miss_keeps_the_start_of_the_failure_streak(): void
    {
        FakeDns::txt('_neev-host.app.acme.com');
        $started = now()->subDays(2)->startOfSecond();
        $hostname = HostnameFactory::new()->verified()->create([
            'host' => 'app.acme.com',
            'verification_token' => 'published',
            'verification_failed_at' => $started,
        ]);

        (new VerifyDomainJob($hostname))->handle();

        $this->assertTrue($hostname->fresh()->verification_failed_at->equalTo($started));
    }

    /**
     * A custom host whose record has been missing for
     * `neev.dns_verification.unverify_after_failed_days` stops serving, but
     * stays held: its owner can still publish the record again.
     */
    public function test_unverifies_a_host_whose_record_has_been_missing_long_enough(): void
    {
        Event::fake([DomainUnverified::class, DomainRemoved::class]);
        config(['neev.dns_verification.unverify_after_failed_days' => 7]);
        FakeDns::txt('_neev-host.app.acme.com');
        $team = TeamFactory::new()->create();
        $hostname = HostnameFactory::new()->forOwner($team)->verified()->create([
            'host' => 'app.acme.com',
            'verification_token' => 'published',
            'verification_failed_at' => now()->subDays(8),
        ]);
        $team->makePrimaryHostname($hostname);

        (new VerifyDomainJob($hostname))->handle();

        $hostname = $hostname->fresh();
        $this->assertNotNull($hostname);
        $this->assertNull($hostname->verified_at);
        $this->assertSame(Hostname::STATUS_FAILED, $hostname->status);
        $this->assertNull(Hostname::ownerOf('app.acme.com', $team->getMorphClass()));
        Event::assertDispatched(DomainUnverified::class, fn (DomainUnverified $e) => $e->domain->is($hostname));
        Event::assertNotDispatched(DomainRemoved::class);
    }

    /**
     * Still missing at twice the window, the host is deleted, freeing it for
     * another owner, and its owner no longer points its primary at it.
     */
    public function test_removes_a_host_whose_record_has_been_missing_twice_as_long(): void
    {
        Event::fake([DomainRemoved::class]);
        config(['neev.dns_verification.unverify_after_failed_days' => 7]);
        FakeDns::txt('_neev-host.app.acme.com');
        $team = TeamFactory::new()->create();
        $hostname = HostnameFactory::new()->forOwner($team)->verified()->create([
            'host' => 'app.acme.com',
            'verification_token' => 'published',
            'verification_failed_at' => now()->subDays(8),
        ]);
        $team->makePrimaryHostname($hostname);
        $hostname->unverifyFailed();
        $hostname->forceFill(['verification_failed_at' => now()->subDays(14)])->save();

        (new VerifyDomainJob($hostname))->handle();

        $this->assertNull(Hostname::find($hostname->id));
        $this->assertNull($team->fresh()->primary_hostname_id);
        Event::assertDispatched(DomainRemoved::class, fn (DomainRemoved $e) => $e->domain->is($hostname));
    }

    public function test_keeps_a_host_whose_record_has_only_just_gone_missing(): void
    {
        Event::fake([DomainUnverified::class, DomainRemoved::class]);
        config(['neev.dns_verification.unverify_after_failed_days' => 7]);
        FakeDns::txt('_neev-host.app.acme.com');
        $hostname = HostnameFactory::new()->verified()->create([
            'host' => 'app.acme.com',
            'verification_token' => 'published',
            'verification_failed_at' => now()->subDays(2),
        ]);

        (new VerifyDomainJob($hostname))->handle();

        $this->assertNotNull($hostname->fresh()->verified_at);
        Event::assertNotDispatched(DomainUnverified::class);
        Event::assertNotDispatched(DomainRemoved::class);
    }

    /**
     * An email domain whose registration lapsed must not keep its membership
     * power: once unverified it federates, enforces and manages nobody.
     */
    public function test_unverifies_an_email_domain_whose_record_has_been_missing_long_enough(): void
    {
        Event::fake([DomainUnverified::class]);
        config(['neev.dns_verification.unverify_after_failed_days' => 7]);
        FakeDns::txt('_neev-email.acme.com');
        $team = TeamFactory::new()->create();
        $domain = EmailDomainFactory::new()->forOwner($team)->verified()->create([
            'domain' => 'acme.com',
            'verification_token' => 'published',
            'verification_failed_at' => now()->subDays(7),
            'enforce' => true,
        ]);

        (new VerifyDomainJob($domain))->handle();

        $domain = $domain->fresh();
        $this->assertNull($domain->verified_at);
        $this->assertSame(EmailDomain::STATUS_FAILED, $domain->status);
        $this->assertFalse(EmailDomain::isVerifiedForEmail('alice@acme.com'));
        $this->assertFalse($team->fresh()->enforcesDomain());
        $this->assertFalse($team->fresh()->hasVerifiedDomainFor('alice@acme.com'));
        Event::assertDispatched(DomainUnverified::class);
    }

    /**
     * Deleting an email domain gives back the accounts it deactivated, since
     * nothing would be left to reactivate them.
     */
    public function test_removes_an_email_domain_missing_twice_as_long_and_reactivates_its_members(): void
    {
        Event::fake([DomainRemoved::class]);
        config(['neev.dns_verification.unverify_after_failed_days' => 7]);
        FakeDns::txt('_neev-email.acme.com');
        $team = TeamFactory::new()->create();
        $domain = EmailDomainFactory::new()->forOwner($team)->create([
            'domain' => 'acme.com',
            'verification_token' => 'published',
            'status' => EmailDomain::STATUS_FAILED,
            'verification_failed_at' => now()->subDays(14),
        ]);
        $member = User::factory()->create(['active' => true, 'email' => 'alice@acme.com']);
        $team->addMember($member);
        $member->deactivate();

        (new VerifyDomainJob($domain))->handle();

        $this->assertNull(EmailDomain::find($domain->id));
        $this->assertTrue($member->fresh()->active);
        Event::assertDispatched(DomainRemoved::class, fn (DomainRemoved $e) => $e->domain->is($domain));
    }

    /**
     * Under isolation the job runs with no tenant resolved, as a queue worker
     * does. The team, its members and their other teams are inside a tenant,
     * so a scoped lookup would find none of them and leave the member locked
     * out with the claim gone.
     */
    public function test_under_isolation_removing_an_email_domain_reactivates_its_members(): void
    {
        config(['neev.tenant' => true, 'neev.dns_verification.unverify_after_failed_days' => 7]);
        FakeDns::txt('_neev-email.acme.com');
        $tenant = TenantFactory::new()->create();
        $team = TeamFactory::new()->create(['tenant_id' => $tenant->id]);
        $domain = $this->lapsedClaim($team);
        $member = User::factory()->create(['active' => true, 'email' => 'alice@acme.com', 'tenant_id' => $tenant->id]);
        $team->addMember($member);
        $member->deactivate();

        unserialize(serialize(new VerifyDomainJob($domain)))->handle();

        $this->assertNull(EmailDomain::find($domain->id));
        $this->assertTrue((bool) User::query()->withoutGlobalScopes()->find($member->id)->active);
    }

    /**
     * Another team of the member's, in the same tenant, still holds the
     * domain and may be the one that deactivated them, so the removal leaves
     * them as they are. A scoped lookup would miss that team.
     */
    public function test_under_isolation_a_member_another_team_also_claims_for_stays_deactivated(): void
    {
        config(['neev.tenant' => true, 'neev.dns_verification.unverify_after_failed_days' => 7]);
        FakeDns::txt('_neev-email.acme.com');
        $tenant = TenantFactory::new()->create();
        $team = TeamFactory::new()->create(['tenant_id' => $tenant->id]);
        $other = TeamFactory::new()->create(['tenant_id' => $tenant->id]);
        $domain = $this->lapsedClaim($team);
        EmailDomainFactory::new()->forOwner($other)->verified()->create(['domain' => 'acme.com']);
        $member = User::factory()->create(['active' => true, 'email' => 'alice@acme.com', 'tenant_id' => $tenant->id]);
        $team->addMember($member);
        $other->addMember($member);
        $member->deactivate();

        unserialize(serialize(new VerifyDomainJob($domain)))->handle();

        $this->assertNull(EmailDomain::find($domain->id));
        $this->assertFalse((bool) User::query()->withoutGlobalScopes()->find($member->id)->active);
    }

    /**
     * An `acme.com` claim of the team's whose record has been missing long
     * enough for the job to delete it.
     */
    protected function lapsedClaim(Team $team): EmailDomain
    {
        return EmailDomainFactory::new()->forOwner($team)->create([
            'domain' => 'acme.com',
            'verification_token' => 'published',
            'status' => EmailDomain::STATUS_FAILED,
            'verification_failed_at' => now()->subDays(14),
        ]);
    }

    /**
     * A row unverified for its missing record is restored once the record is
     * published again, as long as it has not been deleted.
     */
    public function test_restores_an_unverified_row_whose_record_came_back(): void
    {
        Event::fake([DomainReverified::class, DomainVerified::class]);
        config(['neev.dns_verification.unverify_after_failed_days' => 7]);
        $domain = EmailDomainFactory::new()->create([
            'domain' => 'acme.com',
            'verification_token' => 'published',
            'status' => EmailDomain::STATUS_FAILED,
            'verification_failed_at' => now()->subDays(10),
        ]);
        FakeDns::txt('_neev-email.acme.com', 'published');

        (new VerifyDomainJob($domain))->handle();

        $domain = $domain->fresh();
        $this->assertNotNull($domain->verified_at);
        $this->assertNull($domain->verification_failed_at);
        $this->assertSame(EmailDomain::STATUS_VERIFIED, $domain->status);
        Event::assertDispatched(DomainReverified::class);
        Event::assertNotDispatched(DomainVerified::class);
    }

    /**
     * A pending claim whose verify() has missed is not a failed row: the
     * job leaves it alone however long ago it first missed.
     */
    public function test_never_removes_a_pending_claim(): void
    {
        config(['neev.dns_verification.unverify_after_failed_days' => 7]);
        FakeDns::txt('_neev-email.acme.com');
        $claim = EmailDomainFactory::new()->create([
            'domain' => 'acme.com',
            'verification_token' => 'unpublished',
            'verification_failed_at' => now()->subDays(30),
        ]);

        (new VerifyDomainJob($claim))->handle();

        $this->assertNotNull($claim->fresh());
    }

    public function test_zero_days_never_unverifies_or_removes(): void
    {
        config(['neev.dns_verification.unverify_after_failed_days' => 0]);
        FakeDns::txt('_neev-host.app.acme.com');
        $hostname = HostnameFactory::new()->verified()->create([
            'host' => 'app.acme.com',
            'verification_token' => 'published',
            'verification_failed_at' => now()->subDays(365),
        ]);

        (new VerifyDomainJob($hostname))->handle();

        $this->assertNotNull($hostname->fresh()->verified_at);
    }

    /**
     * A host under the platform zone follows a slug and has no record to
     * check. None can be claimed, but one copied from `domains` may remain.
     */
    public function test_skips_a_host_under_the_platform_zone(): void
    {
        config(['neev.platform_domain' => 'neev.test']);
        $hostname = HostnameFactory::new()->verified()->create([
            'host' => 'acme.neev.test',
            'verification_token' => 'published',
        ]);

        (new VerifyDomainJob($hostname))->handle();

        $this->assertNull($hostname->fresh()->verification_failed_at);
    }
}
