<?php

namespace Ssntpl\Neev\Tests\Unit\Jobs;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Ssntpl\Neev\Database\Factories\DomainFactory;
use Ssntpl\Neev\Events\DomainVerificationFailed;
use Ssntpl\Neev\Events\DomainVerified;
use Ssntpl\Neev\Jobs\VerifyDomainJob;
use Ssntpl\Neev\Models\Domain;
use Ssntpl\Neev\Tests\Support\FakeDns;
use Ssntpl\Neev\Tests\TestCase;

// Must load before any test calls Domain::verify(); see the file for why.
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
     * A domain unverified by a new token after the job was queued, on a host
     * another owner has verified since, cannot win its claim. The re-check has
     * nothing to do and must not fail the job.
     */
    public function test_skips_a_claim_another_owner_now_holds(): void
    {
        Event::fake([DomainVerified::class]);
        $claim = DomainFactory::new()->verified()->create([
            'domain' => 'acme.com',
            'verification_token' => 'old',
        ]);
        $job = new VerifyDomainJob($claim);

        // While the job runs on the model it loaded verified, a new token
        // resets the row and another owner verifies the host.
        Domain::whereKey($claim->id)->update(['verified_at' => null, 'verification_token' => 'new']);
        DomainFactory::new()->verified()->create(['domain' => 'acme.com']);
        FakeDns::txt('_neev-verification.acme.com', 'old');

        $job->handle();

        $claim->refresh();
        $this->assertNull($claim->verified_at);
        $this->assertNull($claim->verification_failed_at);
        Event::assertNotDispatched(DomainVerified::class);
    }

    /**
     * The job re-checks a verified domain. A new token issued after it was
     * queued makes the row a fresh pending claim, whose record may not be
     * published yet; the job must not mark that claim failed.
     */
    public function test_skips_a_domain_a_new_token_reset_after_it_was_queued(): void
    {
        Event::fake([DomainVerified::class, DomainVerificationFailed::class]);
        $domain = DomainFactory::new()->verified()->create([
            'domain' => 'acme.com',
            'verification_token' => 'old',
        ]);
        $job = new VerifyDomainJob($domain);

        Domain::whereKey($domain->id)->update(['verified_at' => null, 'verification_token' => 'new']);

        // The queue serialises the model and loads it again when the job runs.
        unserialize(serialize($job))->handle();

        $domain->refresh();
        $this->assertNull($domain->verified_at);
        $this->assertNull($domain->verification_failed_at);
        Event::assertNotDispatched(DomainVerified::class);
        Event::assertNotDispatched(DomainVerificationFailed::class);
    }

    /**
     * The job re-checks verified domains only. A pending claim is left alone
     * even with its record published; it is verified through Domain::verify()
     * or neev:domain:verify.
     */
    public function test_does_not_verify_a_pending_claim_even_with_its_record_published(): void
    {
        Event::fake([DomainVerified::class]);
        $claim = DomainFactory::new()->create([
            'domain' => 'acme.com',
            'verification_token' => 'published',
        ]);
        FakeDns::txt('_neev-verification.acme.com', 'published');

        (new VerifyDomainJob($claim))->handle();

        $this->assertNull($claim->fresh()->verified_at);
        Event::assertNotDispatched(DomainVerified::class);
    }

    /**
     * A verified domain is still re-checked: with no record published, the
     * failure is recorded and the domain stays verified.
     */
    public function test_re_checks_a_verified_domain_and_records_a_missing_record(): void
    {
        $domain = DomainFactory::new()->verified()->create([
            'domain' => 'acme.com',
            'verification_token' => 'published',
        ]);

        (new VerifyDomainJob($domain))->handle();

        $domain->refresh();
        $this->assertNotNull($domain->verified_at);
        $this->assertNotNull($domain->verification_failed_at);
    }
}
