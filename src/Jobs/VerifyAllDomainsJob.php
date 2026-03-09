<?php

namespace Ssntpl\Neev\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Ssntpl\Neev\Models\Domain;

class VerifyAllDomainsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void
    {
        Domain::whereNotNull('verified_at')->each(function (Domain $domain) {
            VerifyDomainJob::dispatch($domain);
        });
    }
}
