<?php

namespace Ssntpl\Neev\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Ssntpl\Neev\Models\EmailDomain;
use Ssntpl\Neev\Models\Hostname;

/**
 * Queues a re-check of every verified email domain and custom host, and of
 * every one unverified for its missing record. Schedule it daily: a row whose
 * record has been missing for `neev.dns_verification.unverify_after_failed_days`
 * is unverified, and at twice that deleted (VerifyDomainJob). Platform
 * subdomains are not stored, so they are never checked.
 */
class VerifyAllDomainsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void
    {
        EmailDomain::query()->rechecked()->each(function (EmailDomain $domain) {
            VerifyDomainJob::dispatch($domain);
        });

        Hostname::query()->rechecked()->each(function (Hostname $hostname) {
            VerifyDomainJob::dispatch($hostname);
        });
    }
}
