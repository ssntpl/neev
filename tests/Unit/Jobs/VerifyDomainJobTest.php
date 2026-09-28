<?php

namespace Ssntpl\Neev\Tests\Unit\Jobs;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Ssntpl\Neev\Database\Factories\DomainFactory;
use Ssntpl\Neev\Jobs\VerifyDomainJob;
use Ssntpl\Neev\Tests\TestCase;

class VerifyDomainJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A domain unverified by a new token after the job was queued, on a host
     * another owner has verified since, cannot win its claim. The re-check has
     * nothing to do and must not fail the job.
     */
    public function test_skips_a_claim_another_owner_now_holds(): void
    {
        Log::spy();
        DomainFactory::new()->verified()->create(['domain' => 'acme.com']);
        $claim = DomainFactory::new()->create([
            'domain' => 'acme.com',
            'verification_token' => 'pending',
        ]);

        (new VerifyDomainJob($claim))->handle();

        $claim->refresh();
        $this->assertNull($claim->verified_at);
        $this->assertNull($claim->verification_failed_at);
        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context) => $context['domain_id'] === $claim->id)
            ->once();
    }
}
