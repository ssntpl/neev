<?php

namespace Ssntpl\Neev\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
        $this->domain->verify();
    }
}
