<?php

namespace Ssntpl\Neev\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Ssntpl\Neev\Exceptions\DomainAlreadyVerifiedException;
use Ssntpl\Neev\Models\Domain;

/**
 * Re-checks a verified domain's DNS record, as VerifyAllDomainsJob schedules.
 *
 * A domain that is not verified when the job runs is skipped: it is a pending
 * claim, verified through Domain::verify() or neev:domain:verify instead.
 */
class VerifyDomainJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public Domain $domain)
    {
    }

    public function handle(): void
    {
        // This job re-checks a verified domain. A new token issued after it was
        // queued turned the row back into a pending claim, waiting for a record
        // its owner may not have published yet; checking it now would only
        // mark that fresh claim failed.
        if ($this->domain->verified_at === null) {
            return;
        }

        try {
            $this->domain->verify();
        } catch (DomainAlreadyVerifiedException $e) {
            // The row was unverified by a new token while this ran, and another
            // owner has verified the host since. Its claim is pending again and
            // cannot win; there is nothing for a re-check to do.
            Log::info('Skipped re-check of a domain another owner now holds.', [
                'domain_id' => $this->domain->id,
            ]);
        }
    }
}
