<?php

namespace Ssntpl\Neev\Commands\Domain;

use Illuminate\Console\Command;
use Ssntpl\Neev\Jobs\VerifyAllDomainsJob;
use Ssntpl\Neev\Models\Domain;

class VerifyDomainCommand extends Command
{
    protected $signature = 'neev:domain:verify {domain? : The domain to verify}
                            {--force : Mark as verified without DNS check}
                            {--all : Re-verify all previously verified domains}';

    protected $description = 'Verify a domain via DNS TXT record lookup';

    public function handle(): int
    {
        if ($this->option('all')) {
            VerifyAllDomainsJob::dispatch();
            $this->info('Dispatched verification jobs for all verified domains.');

            return self::SUCCESS;
        }

        $domainName = $this->argument('domain');

        if (! $domainName) {
            $this->error('You must specify a domain name or use --all.');

            return self::FAILURE;
        }

        $domain = Domain::where('domain', $domainName)->first();

        if (! $domain) {
            $this->error("Domain not found: {$domainName}");

            return self::FAILURE;
        }

        if ($this->option('force')) {
            $domain->update(['verified_at' => now()]);
            $this->info("Domain force-verified: {$domainName}");

            return self::SUCCESS;
        }

        $this->line("Checking DNS TXT record: {$domain->getDnsRecordName()}");

        if ($domain->verify()) {
            $this->info("Domain verified successfully: {$domainName}");

            return self::SUCCESS;
        }

        $this->error('DNS verification failed. Ensure the TXT record matches the verification token.');
        $this->line("Use --force to skip DNS verification (e.g., for local development).");

        return self::FAILURE;
    }
}
