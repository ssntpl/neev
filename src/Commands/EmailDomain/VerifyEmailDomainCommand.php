<?php

namespace Ssntpl\Neev\Commands\EmailDomain;

use Illuminate\Console\Command;
use Ssntpl\Neev\Commands\Concerns\ManagesDomainRows;
use Ssntpl\Neev\Jobs\VerifyDomainJob;
use Ssntpl\Neev\Models\EmailDomain;

class VerifyEmailDomainCommand extends Command
{
    use ManagesDomainRows;

    protected $signature = 'neev:email-domain:verify {domain? : The email domain to verify}
                            {--owner-type= : Owner type (team or tenant), to pick one claim when several exist}
                            {--owner-id= : Owner ID or slug, to pick one claim when several exist}
                            {--force : Mark as verified without DNS check}
                            {--all : Re-check every verified or failing email domain}';

    protected $description = 'Verify an email domain via its DNS TXT record';

    public function handle(): int
    {
        if ($this->option('all')) {
            EmailDomain::query()->rechecked()->each(fn (EmailDomain $d) => VerifyDomainJob::dispatch($d));
            $this->info('Dispatched verification jobs for all verified and failing email domains.');

            return self::SUCCESS;
        }

        $domain = EmailDomain::canonicalHost((string) $this->argument('domain'));

        if ($domain === '') {
            $this->error('You must specify a domain or use --all.');

            return self::FAILURE;
        }

        $query = EmailDomain::forHost($domain);

        if (! $this->whereOwnerFromOptions($query)) {
            return self::FAILURE;
        }

        $claims = $query->get();

        if ($claims->isEmpty()) {
            $this->error("Domain not found: {$domain}");

            return self::FAILURE;
        }

        // Several owners may hold one email domain. Taking whichever row came
        // back first would verify a claim nobody chose.
        if ($claims->count() > 1) {
            $this->error("Domain is claimed by more than one owner: {$domain}");
            $this->table(
                ['Owner type', 'Owner ID', 'Verified'],
                $claims->map(fn (EmailDomain $d) => [$d->owner_type, $d->owner_id, $d->isVerified() ? 'Yes' : 'No'])->all(),
            );
            $this->line('Pick one with --owner-type and --owner-id.');

            return self::FAILURE;
        }

        /** @var EmailDomain $row */
        $row = $claims->first();

        // The app disabled it: neither DNS nor an operator brings it back.
        if ($row->status === EmailDomain::STATUS_DISABLED) {
            $this->error("Domain is disabled: {$domain}");

            return self::FAILURE;
        }

        if ($this->option('force')) {
            $this->markVerified($row);
            $this->info("Domain force-verified: {$domain}");
            $this->warnIfEnforceDropped($row);

            return self::SUCCESS;
        }

        $this->line("Checking DNS TXT record: {$row->getDnsRecordName()}");

        if ($row->verify()) {
            $this->info("Domain verified successfully: {$domain}");
            $this->warnIfEnforceDropped($row);

            return self::SUCCESS;
        }

        $this->error('DNS verification failed. Ensure the TXT record matches the verification token.');
        $this->line('Use --force to skip DNS verification (e.g., for local development).');

        return self::FAILURE;
    }

    /**
     * Only the first owner to verify and enforce a domain keeps enforcing.
     */
    protected function warnIfEnforceDropped(EmailDomain $row): void
    {
        if ($row->enforceWasDropped()) {
            $this->warn('Another owner already enforces this domain, so enforce was turned off.');
        }
    }
}
