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
        try {
            $this->domain->verify();
        } catch (DomainAlreadyVerifiedException $e) {
            // The row was unverified after this job was queued (a new token) and
            // another owner has verified the host since. Its claim is pending
            // again and cannot win; there is nothing for a re-check to do.
            Log::info('Skipped re-check of a domain another owner now holds.', [
                'domain_id' => $this->domain->id,
            ]);
        }
    }
}
