<?php

namespace Ssntpl\Neev\Tests\Unit\Models;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Ssntpl\Neev\Database\Factories\EmailDomainFactory;
use Ssntpl\Neev\Database\Factories\HostnameFactory;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Database\Factories\TenantFactory;
use Ssntpl\Neev\Events\DomainReverified;
use Ssntpl\Neev\Events\DomainVerificationFailed;
use Ssntpl\Neev\Events\DomainVerified;
use Ssntpl\Neev\Events\DomainRemoved;
use Ssntpl\Neev\Events\DomainUnverified;
use Ssntpl\Neev\Exceptions\HostnameTakenException;
use Ssntpl\Neev\Jobs\VerifyAllDomainsJob;
use Ssntpl\Neev\Jobs\VerifyDomainJob;
use Ssntpl\Neev\Models\EmailDomain;
use Ssntpl\Neev\Models\Hostname;
use Ssntpl\Neev\Tests\Support\FakeDns;
use Ssntpl\Neev\Tests\TestCase;

// Must load before any test calls verify(); see the file for why.
require_once __DIR__ . '/../../Support/FakeDns.php';

/**
 * RFC 006 phase 3: custom hosts are claimed, proven, re-checked and removed
 * through `hostnames`, and an owner reads its hosts through HasHostnames.
 */
class HostnameLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['neev.platform_domain' => 'otper.com', 'neev.team' => true]);
    }

    protected function tearDown(): void
    {
        FakeDns::reset();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Claiming
    // ---------------------------------------------------------------

    public function test_a_claim_is_pending_with_a_host_record_to_publish(): void
    {
        $team = TeamFactory::new()->create();

        $hostname = $team->claimHost('APP.acme.com.');

        $this->assertSame('app.acme.com', $hostname->host);
        $this->assertSame(Hostname::STATUS_PENDING, $hostname->status);
        $this->assertFalse($hostname->isVerified());
        $this->assertNotEmpty($hostname->verification_token);
        $this->assertSame('_neev-host.app.acme.com', $hostname->getDnsRecordName());
    }

    public function test_a_host_another_owner_holds_cannot_be_claimed(): void
    {
        TeamFactory::new()->create()->claimHost('app.acme.com');

        $this->expectException(HostnameTakenException::class);

        TenantFactory::new()->create()->claimHost('app.acme.com');
    }

    public function test_an_unproven_claim_holds_the_host_however_old(): void
    {
        TeamFactory::new()->create()->claimHost('app.acme.com');
        $this->travel(90)->days();

        $this->expectException(HostnameTakenException::class);

        TeamFactory::new()->create()->claimHost('app.acme.com');
    }

    public function test_a_released_host_can_be_claimed_again(): void
    {
        $holder = TeamFactory::new()->create();
        $holder->claimHost('app.acme.com');
        $holder->releaseHost('app.acme.com');

        $owner = TeamFactory::new()->create();

        $this->assertTrue($owner->claimHost('app.acme.com')->isOwnedBy($owner));
    }

    public function test_nothing_under_the_platform_zone_can_be_claimed(): void
    {
        $team = TeamFactory::new()->create(['slug' => 'acme']);

        foreach (['acme.otper.com', 'otper.com', 'a.b.otper.com'] as $host) {
            try {
                $team->claimHost($host);
                $this->fail("{$host} was claimed.");
            } catch (InvalidArgumentException) {
            }
        }

        $this->assertSame(0, Hostname::count());
    }

    // ---------------------------------------------------------------
    // Verifying
    // ---------------------------------------------------------------

    public function test_a_host_is_proven_by_its_own_record_name(): void
    {
        Event::fake([DomainVerified::class]);
        $hostname = TeamFactory::new()->create()->claimHost('app.acme.com');

        FakeDns::txt('_neev-email.app.acme.com', $hostname->verification_token);
        $this->assertFalse($hostname->verify());

        FakeDns::txt('_neev-host.app.acme.com', $hostname->verification_token);
        $this->assertTrue($hostname->verify());

        $hostname->refresh();
        $this->assertSame(Hostname::STATUS_VERIFIED, $hostname->status);
        Event::assertDispatchedTimes(DomainVerified::class, 1);
    }

    public function test_a_row_copied_from_domains_still_verifies_against_the_old_record_name(): void
    {
        $team = TeamFactory::new()->create();
        DB::table('domains')->insert([
            'owner_type' => 'team',
            'owner_id' => $team->id,
            'domain' => 'app.acme.com',
            'verification_token' => 'copied-token',
            'verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $copied = Hostname::create([
            'owner_type' => 'team',
            'owner_id' => $team->id,
            'host' => 'app.acme.com',
            'status' => Hostname::STATUS_VERIFIED,
            'verification_token' => 'copied-token',
            'verified_at' => now(),
        ]);
        FakeDns::txt('_neev-verification.app.acme.com', 'copied-token');

        $this->assertTrue($copied->verify());
        $this->assertNull($copied->fresh()->verification_failed_at);
    }

    public function test_the_old_record_is_refused_once_legacy_record_is_off(): void
    {
        config(['neev.dns_verification.legacy_record' => false]);
        $team = TeamFactory::new()->create();
        DB::table('domains')->insert([
            'owner_type' => 'team',
            'owner_id' => $team->id,
            'domain' => 'app.acme.com',
            'verification_token' => 'copied-token',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $copied = HostnameFactory::new()->forOwner($team)->create(['host' => 'app.acme.com', 'verification_token' => 'copied-token']);
        FakeDns::txt('_neev-verification.app.acme.com', 'copied-token');

        $this->assertFalse($copied->verify());
    }

    public function test_the_old_record_matches_a_domains_row_in_another_spelling(): void
    {
        $team = TeamFactory::new()->create();
        DB::table('domains')->insert([
            'owner_type' => 'team',
            'owner_id' => $team->id,
            'domain' => 'App.ACME.com.',
            'verification_token' => 'copied-token',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $copied = HostnameFactory::new()->forOwner($team)->create(['host' => 'app.acme.com', 'verification_token' => 'copied-token']);
        FakeDns::txt('_neev-verification.app.acme.com', 'copied-token');

        $this->assertTrue($copied->verify());
    }

    public function test_changing_a_host_forgets_the_old_one_too(): void
    {
        $hostname = HostnameFactory::new()->verified()->create(['host' => 'old.acme.com']);
        Cache::put(Hostname::cacheKey('old.acme.com'), ['context_type' => 'team', 'context_id' => 1]);

        $hostname->update(['host' => 'new.acme.com']);

        $this->assertFalse(Cache::has(Hostname::cacheKey('old.acme.com')));
    }

    public function test_a_new_row_does_not_accept_the_old_record_name(): void
    {
        $hostname = TeamFactory::new()->create()->claimHost('app.acme.com');
        FakeDns::txt('_neev-verification.app.acme.com', $hostname->verification_token);

        $this->assertFalse($hostname->verify());
    }

    public function test_a_failure_is_dated_from_the_first_miss(): void
    {
        Event::fake([DomainVerificationFailed::class]);
        $hostname = HostnameFactory::new()->verified()->create(['host' => 'app.acme.com', 'verification_token' => 't']);
        FakeDns::txt('_neev-host.app.acme.com');

        $hostname->verify();
        $first = $hostname->fresh()->verification_failed_at;
        $this->travel(2)->days();
        $hostname->verify();

        $hostname->refresh();
        $this->assertTrue($hostname->verification_failed_at->equalTo($first));
        $this->assertSame(Hostname::STATUS_FAILED, $hostname->status);
        $this->assertTrue($hostname->isVerified(), 'A failing host serves until it is removed.');
        Event::assertDispatchedTimes(DomainVerificationFailed::class, 1);
    }

    public function test_mark_unverified_asks_for_the_same_record_again(): void
    {
        $hostname = HostnameFactory::new()->verified()->create(['host' => 'app.acme.com', 'verification_token' => 't']);

        $hostname->markUnverified();

        $this->assertFalse($hostname->isVerified());
        $this->assertSame(Hostname::STATUS_PENDING, $hostname->status);
        FakeDns::txt('_neev-host.app.acme.com', 't');
        $this->assertTrue($hostname->verify());
    }

    public function test_a_disabled_row_is_not_restored_by_dns(): void
    {
        $hostname = HostnameFactory::new()->verified()->create(['host' => 'app.acme.com', 'verification_token' => 't']);
        FakeDns::txt('_neev-host.app.acme.com', 't');

        $hostname->disable();

        $this->assertFalse($hostname->verify());
        $this->assertSame(Hostname::STATUS_DISABLED, $hostname->fresh()->status);
        $this->assertNull(Hostname::forHost('app.acme.com')->verified()->first());
    }

    public function test_a_disabled_row_is_not_restored_by_a_new_token(): void
    {
        $team = TeamFactory::new()->create();
        $domain = $team->federateDomain('acme.com', false);
        $domain->disable();

        $old = $domain->verification_token;

        $this->assertNull($domain->generateVerificationToken());
        try {
            $team->federateDomain('acme.com', true);
            $this->fail('A disabled domain was added again.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('This domain is disabled.', $e->getMessage());
        }
        $domain->markUnverified();
        FakeDns::txt('_neev-email.acme.com', $old);

        $this->assertSame($old, $domain->fresh()->verification_token, 'No new token was issued.');
        $this->assertFalse($domain->fresh()->enforce);
        $this->assertFalse($domain->fresh()->verify());
        $this->assertSame(EmailDomain::STATUS_DISABLED, $domain->fresh()->status);
        $this->assertFalse($domain->fresh()->isVerified());
    }

    public function test_a_record_that_comes_back_fires_reverified(): void
    {
        Event::fake([DomainVerified::class, DomainReverified::class, DomainVerificationFailed::class]);
        $hostname = HostnameFactory::new()->verified()->create(['host' => 'app.acme.com', 'verification_token' => 't']);

        FakeDns::txt('_neev-host.app.acme.com');
        $hostname->verify();
        FakeDns::txt('_neev-host.app.acme.com', 't');
        $hostname->verify();
        $hostname->verify();

        Event::assertDispatchedTimes(DomainVerificationFailed::class, 1);
        Event::assertDispatchedTimes(DomainReverified::class, 1);
        Event::assertNotDispatched(DomainVerified::class);
    }

    public function test_an_email_domain_copied_from_domains_verifies_against_the_old_record_name(): void
    {
        $team = TeamFactory::new()->create();
        DB::table('domains')->insert([
            'owner_type' => 'team',
            'owner_id' => $team->id,
            'domain' => 'acme.com',
            'verification_token' => 'copied-token',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $copied = EmailDomain::create([
            'owner_type' => 'team',
            'owner_id' => $team->id,
            'domain' => 'acme.com',
            'verification_token' => 'copied-token',
        ]);
        FakeDns::txt('_neev-verification.acme.com', 'copied-token');

        $this->assertTrue($copied->verify());
    }

    // ---------------------------------------------------------------
    // Re-verification
    // ---------------------------------------------------------------

    public function test_a_host_missing_its_record_for_seven_days_stops_serving(): void
    {
        Event::fake([DomainUnverified::class, DomainRemoved::class, DomainVerificationFailed::class]);
        $team = TeamFactory::new()->create();
        $hostname = $this->verifiedHost($team, 'app.acme.com', primary: true);
        $hostname->forceFill(['verification_token' => 't', 'verification_failed_at' => now()->subDays(7)])->save();
        FakeDns::txt('_neev-host.app.acme.com');

        VerifyDomainJob::dispatchSync($hostname);

        $this->assertFalse($hostname->fresh()->isVerified());
        $this->assertNotSame('app.acme.com', $team->fresh()->canonicalHost());
        Event::assertDispatched(DomainUnverified::class, fn (DomainUnverified $e) => $e->domain->is($hostname));
        Event::assertNotDispatched(DomainRemoved::class);
    }

    public function test_a_host_missing_its_record_for_fourteen_days_is_removed(): void
    {
        Event::fake([DomainRemoved::class, DomainVerificationFailed::class]);
        $team = TeamFactory::new()->create();
        $hostname = $this->verifiedHost($team, 'app.acme.com', primary: true);
        $hostname->forceFill(['verification_token' => 't', 'verification_failed_at' => now()->subDays(14)])->save();
        FakeDns::txt('_neev-host.app.acme.com');

        VerifyDomainJob::dispatchSync($hostname);

        $this->assertNull(Hostname::find($hostname->id));
        $this->assertNull($team->fresh()->primary_hostname_id);
        $this->assertNotNull($team->fresh(), 'The owner stays.');
        Event::assertDispatched(DomainRemoved::class, fn (DomainRemoved $e) => $e->domain->is($hostname));
    }

    public function test_a_host_failing_for_less_than_seven_days_stays(): void
    {
        Event::fake([DomainUnverified::class, DomainRemoved::class]);
        $hostname = HostnameFactory::new()->verified()->create([
            'host' => 'app.acme.com',
            'verification_token' => 't',
            'verification_failed_at' => now()->subDays(6),
        ]);
        FakeDns::txt('_neev-host.app.acme.com');

        VerifyDomainJob::dispatchSync($hostname);

        $this->assertTrue($hostname->fresh()->isVerified());
        Event::assertNotDispatched(DomainUnverified::class);
        Event::assertNotDispatched(DomainRemoved::class);
    }

    public function test_a_host_whose_record_came_back_is_kept(): void
    {
        $hostname = HostnameFactory::new()->verified()->create([
            'host' => 'app.acme.com',
            'verification_token' => 't',
            'verification_failed_at' => now()->subDays(30),
        ]);
        FakeDns::txt('_neev-host.app.acme.com', 't');

        VerifyDomainJob::dispatchSync($hostname);

        $this->assertNull($hostname->fresh()->verification_failed_at);
        $this->assertSame(Hostname::STATUS_VERIFIED, $hostname->fresh()->status);
    }

    public function test_an_email_domain_missing_its_record_for_fourteen_days_is_removed(): void
    {
        $domain = EmailDomain::create([
            'owner_type' => 'team',
            'owner_id' => TeamFactory::new()->create()->id,
            'domain' => 'acme.com',
            'verification_token' => 't',
            'verified_at' => now(),
            'verification_failed_at' => now()->subDays(30),
        ]);
        FakeDns::txt('_neev-email.acme.com');

        VerifyDomainJob::dispatchSync($domain);

        $this->assertNull($domain->fresh());
    }

    public function test_an_email_domain_verified_by_hand_is_not_rechecked(): void
    {
        Event::fake([DomainVerificationFailed::class]);
        $domain = EmailDomainFactory::new()->verified()->create([
            'domain' => 'acme.com',
            'verification_strategy' => EmailDomain::STRATEGY_MANUAL,
        ]);
        FakeDns::txt('_neev-email.acme.com');

        VerifyDomainJob::dispatchSync($domain);

        $this->assertSame(EmailDomain::STATUS_VERIFIED, $domain->fresh()->status);
        Event::assertNotDispatched(DomainVerificationFailed::class);
    }

    public function test_a_new_token_makes_a_manual_email_domain_dns_proven_again(): void
    {
        $domain = EmailDomainFactory::new()->verified()->create(['verification_strategy' => EmailDomain::STRATEGY_MANUAL]);

        $domain->generateVerificationToken();

        $this->assertSame(EmailDomain::STRATEGY_DNS, $domain->fresh()->verification_strategy);
    }

    public function test_a_strategy_set_with_the_token_is_kept(): void
    {
        $domain = EmailDomainFactory::new()->create([
            'verification_token' => 't',
            'verification_strategy' => EmailDomain::STRATEGY_MANUAL,
        ]);

        $this->assertSame(EmailDomain::STRATEGY_MANUAL, $domain->fresh()->verification_strategy);
    }

    public function test_a_host_under_the_platform_zone_is_never_checked(): void
    {
        Event::fake([DomainVerificationFailed::class, DomainRemoved::class]);
        config(['neev.platform_domain' => 'neev.test']);
        $copied = HostnameFactory::new()->verified()->create([
            'host' => 'acme.neev.test',
            'verification_failed_at' => now()->subDays(30),
        ]);
        FakeDns::txt('_neev-host.acme.neev.test');

        VerifyDomainJob::dispatchSync($copied);

        $this->assertNotNull($copied->fresh());
        Event::assertNothingDispatched();
    }

    public function test_the_sweep_rechecks_verified_and_failing_rows_only(): void
    {
        $pending = TeamFactory::new()->create()->claimHost('pending.acme.com');
        $verified = $this->verifiedHost(TeamFactory::new()->create(), 'app.acme.com');
        $unverified = $this->verifiedHost(TeamFactory::new()->create(), 'old.acme.com');
        $unverified->unverifyFailed();
        $email = EmailDomain::create([
            'owner_type' => 'team',
            'owner_id' => TeamFactory::new()->create()->id,
            'domain' => 'acme.com',
            'verified_at' => now(),
        ]);
        Bus::fake([VerifyDomainJob::class]);

        (new VerifyAllDomainsJob())->handle();

        Bus::assertDispatched(VerifyDomainJob::class, fn ($job) => $job->domain->is($verified));
        Bus::assertDispatched(VerifyDomainJob::class, fn ($job) => $job->domain->is($unverified));
        Bus::assertDispatched(VerifyDomainJob::class, fn ($job) => $job->domain->is($email));
        Bus::assertNotDispatched(VerifyDomainJob::class, fn ($job) => $job->domain->is($pending));
    }

    // ---------------------------------------------------------------
    // Owner helpers
    // ---------------------------------------------------------------

    public function test_the_canonical_host_is_the_primary_then_the_platform_host(): void
    {
        $team = TeamFactory::new()->create(['slug' => 'acme']);

        $this->assertSame('acme.otper.com', $team->canonicalHost());

        $this->verifiedHost($team, 'app.acme.com', primary: true);
        $this->assertSame('app.acme.com', $team->fresh()->canonicalHost());

        $team->fresh()->releaseHost('app.acme.com');
        $this->assertSame('acme.otper.com', $team->fresh()->canonicalHost());
    }

    public function test_a_pending_primary_is_not_canonical(): void
    {
        $team = TeamFactory::new()->create(['slug' => 'acme']);
        $hostname = $team->claimHost('app.acme.com');
        $team->forceFill(['primary_hostname_id' => $hostname->id])->save();

        $this->assertSame('acme.otper.com', $team->fresh()->canonicalHost());
    }

    public function test_only_a_verified_host_of_the_owner_can_be_primary(): void
    {
        $team = TeamFactory::new()->create();
        $other = $this->verifiedHost(TeamFactory::new()->create(), 'other.acme.com');
        $pending = $team->claimHost('app.acme.com');

        foreach ([$other, $pending] as $hostname) {
            try {
                $team->makePrimaryHostname($hostname);
                $this->fail("{$hostname->host} was made primary.");
            } catch (InvalidArgumentException) {
            }
        }

        $this->assertNull($team->fresh()->primary_hostname_id);
    }

    public function test_release_host_only_releases_the_owners_own(): void
    {
        $team = TeamFactory::new()->create();
        $this->verifiedHost(TeamFactory::new()->create(), 'app.acme.com');

        $this->assertFalse($team->releaseHost('app.acme.com'));
        $this->assertNotNull(Hostname::forHost('app.acme.com')->verified()->first());
    }

    public function test_deleting_an_owner_deletes_its_hosts_and_email_domains(): void
    {
        $team = TeamFactory::new()->create();
        $this->verifiedHost($team, 'app.acme.com', primary: true);
        $team->federateDomain('acme.com', false);
        $other = TeamFactory::new()->create();
        $other->federateDomain('acme.com', false);

        $team->delete();

        $this->assertNull(Hostname::forHost('app.acme.com')->verified()->first());
        $this->assertSame(0, Hostname::where('owner_id', $team->id)->count());
        $this->assertSame(0, EmailDomain::where('owner_type', 'team')->where('owner_id', $team->id)->count());
        $this->assertSame(1, $other->emailDomains()->count(), 'Another owner keeps its own.');
    }
}
