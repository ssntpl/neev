<?php

namespace Ssntpl\Neev\Commands\EmailDomain;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Ssntpl\Neev\Commands\Concerns\ManagesDomainRows;
use Ssntpl\Neev\Exceptions\EmailDomainEnforcedException;
use Ssntpl\Neev\Models\EmailDomain;

use function Laravel\Prompts\text;

class AddEmailDomainCommand extends Command implements PromptsForMissingInput
{
    use ManagesDomainRows;

    protected $signature = 'neev:email-domain:add {domain : The email domain whose users join the owner}
                            {--owner-type= : Owner type (team or tenant)}
                            {--owner-id= : Owner ID or slug}
                            {--enforce : Only invite users at this domain}
                            {--skip-verification : Mark as verified without DNS}';

    protected $description = 'Add an email domain to a tenant or team';

    public function handle(): int
    {
        $owner = $this->ownerFromOptions();

        if ($owner === null) {
            return self::FAILURE;
        }

        $domain = EmailDomain::canonicalHost((string) $this->argument('domain'));

        // Other owners may hold it too: an email domain is not exclusive.
        if ($owner->emailDomains()->forHost($domain)->exists()) {
            $this->error("Domain already added for this {$owner->getContextType()}: {$domain}");

            return self::FAILURE;
        }

        try {
            /** @var EmailDomain $row */
            $row = $owner->emailDomains()->create(['domain' => $domain, 'enforce' => (bool) $this->option('enforce')]);
        } catch (EmailDomainEnforcedException $e) {
            $this->error("{$e->getMessage()} ({$domain})");

            return self::FAILURE;
        }

        $row->generateVerificationToken();

        if ($this->option('skip-verification')) {
            $this->markVerified($row);
            $this->info("Domain added and verified: {$domain}");

            return self::SUCCESS;
        }

        $this->info("Domain added: {$domain}");
        $this->newLine();
        $this->warn('To verify, add this DNS TXT record:');
        $this->line("  Name:  {$row->getDnsRecordName()}");
        $this->line("  Value: {$row->verification_token}");
        $this->newLine();
        $this->line("Then run: php artisan neev:email-domain:verify {$domain}");

        return self::SUCCESS;
    }

    protected function promptForMissingArgumentsUsing(): array
    {
        return [
            'domain' => fn () => text(label: 'What email domain would you like to add?', required: true),
        ];
    }
}
