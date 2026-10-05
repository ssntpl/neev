<?php

namespace Ssntpl\Neev\Commands\Hostname;

use Illuminate\Console\Command;
use Ssntpl\Neev\Jobs\VerifyDomainJob;
use Ssntpl\Neev\Models\Hostname;

class VerifyHostnameCommand extends Command
{
    protected $signature = 'neev:hostname:verify {host? : The host to verify}
                            {--all : Re-check every verified or failing host}';

    protected $description = 'Verify a custom host via its DNS TXT record';

    public function handle(): int
    {
        if ($this->option('all')) {
            Hostname::query()->rechecked()->each(fn (Hostname $h) => VerifyDomainJob::dispatch($h));
            $this->info('Dispatched verification jobs for all verified and failing hosts.');

            return self::SUCCESS;
        }

        $host = Hostname::canonicalHost((string) $this->argument('host'));

        if ($host === '') {
            $this->error('You must specify a host or use --all.');

            return self::FAILURE;
        }

        // A host is unique, so it names one row.
        $hostname = Hostname::forHost($host)->first();

        if ($hostname === null) {
            $this->error("Host not found: {$host}");

            return self::FAILURE;
        }

        $this->line("Checking DNS TXT record: {$hostname->getDnsRecordName()}");

        if ($hostname->verify()) {
            $this->info("Host verified successfully: {$host}");

            return self::SUCCESS;
        }

        $this->error('DNS verification failed. Ensure the TXT record matches the verification token.');

        return self::FAILURE;
    }
}
