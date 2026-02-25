<?php

namespace Ssntpl\Neev\Commands\Domain;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Ssntpl\Neev\Models\Domain;

use function Laravel\Prompts\text;

class VerifyDomainCommand extends Command implements PromptsForMissingInput
{
    protected $signature = 'neev:domain:verify {domain : The domain to verify}
                            {--force : Mark as verified without DNS check}';

    protected $description = 'Verify a domain via DNS TXT record lookup';

    public function handle(): int
    {
        $domainName = $this->argument('domain');

        $domain = Domain::where('domain', $domainName)->first();

        if (! $domain) {
            $this->error("Domain not found: {$domainName}");

            return self::FAILURE;
        }

        if ($domain->isVerified()) {
            $this->info("Domain is already verified: {$domainName}");

            return self::SUCCESS;
        }

        if ($this->option('force')) {
            $domain->update(['verified_at' => now(), 'verification_token' => null]);
            $this->info("Domain force-verified: {$domainName}");

            return self::SUCCESS;
        }

        $recordName = $domain->getDnsRecordName();
        $this->line("Checking DNS TXT record: {$recordName}");

        $records = dns_get_record($recordName, DNS_TXT);

        if ($records === false || empty($records)) {
            $this->error('No DNS TXT records found.');
            $this->line('Ensure you have added the TXT record and waited for DNS propagation.');
            $this->line("Use --force to skip DNS verification (e.g., for local development).");

            return self::FAILURE;
        }

        foreach ($records as $record) {
            $txt = $record['txt'] ?? '';
            if ($domain->verify($txt)) {
                $this->info("Domain verified successfully: {$domainName}");

                return self::SUCCESS;
            }
        }

        $this->error('DNS TXT record found but token does not match.');
        $this->line('Ensure the TXT value matches the verification token exactly.');

        return self::FAILURE;
    }

    protected function promptForMissingArgumentsUsing(): array
    {
        return [
            'domain' => fn () => text(
                label: 'Which domain would you like to verify?',
                required: true,
            ),
        ];
    }
}
