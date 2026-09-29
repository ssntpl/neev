<?php

namespace Ssntpl\Neev\Commands\Domain;

use Illuminate\Console\Command;
use Ssntpl\Neev\Commands\Concerns\ResolvesTenantContext;
use Ssntpl\Neev\Exceptions\DomainAlreadyVerifiedException;
use Ssntpl\Neev\Jobs\VerifyAllDomainsJob;
use Ssntpl\Neev\Models\Domain;

class VerifyDomainCommand extends Command
{
    use ResolvesTenantContext;

    protected $signature = 'neev:domain:verify {domain? : The domain to verify}
                            {--owner-type= : Owner type (team or tenant), to pick one claim when several exist}
                            {--owner-id= : Owner ID or slug, to pick one claim when several exist}
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

        $domainName = Domain::canonicalHost($domainName);
        $query = Domain::where('domain', $domainName);

        $ownerType = $this->option('owner-type');
        $ownerId = $this->option('owner-id');

        if ($ownerType !== null && ! in_array($ownerType, ['team', 'tenant'], true)) {
            $this->error('--owner-type must be "team" or "tenant".');

            return self::FAILURE;
        }

        if ($ownerId !== null && $ownerType === null) {
            $this->error('--owner-id needs --owner-type.');

            return self::FAILURE;
        }

        if ($ownerType !== null) {
            $query->where('owner_type', $ownerType);
        }

        if ($ownerId !== null) {
            $owner = $ownerType === 'tenant'
                ? $this->resolveTenant((string) $ownerId)
                : $this->resolveTeam((string) $ownerId);
            $query->where('owner_id', $owner->getKey());
        }

        $claims = $query->get();

        if ($claims->isEmpty()) {
            $this->error("Domain not found: {$domainName}");

            return self::FAILURE;
        }

        // Several owners may hold claims on one host. Taking whichever row
        // came back first would verify — or with --force, hand the host to —
        // an owner nobody chose.
        if ($claims->count() > 1) {
            $this->error("Domain is claimed by more than one owner: {$domainName}");
            $this->table(
                ['Owner type', 'Owner ID', 'Verified'],
                $claims->map(fn (Domain $d) => [$d->owner_type, $d->owner_id, $d->verified_at ? 'Yes' : 'No'])->all(),
            );
            $this->line('Pick one with --owner-type and --owner-id.');

            return self::FAILURE;
        }

        $domain = $claims->first();

        if ($this->option('force')) {
            // Skipping DNS does not skip the rule verify() applies: the first
            // owner of a kind to verify a host gets it.
            try {
                $domain->markVerified();
            } catch (DomainAlreadyVerifiedException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $this->info("Domain force-verified: {$domainName}");

            return self::SUCCESS;
        }

        $this->line("Checking DNS TXT record: {$domain->getDnsRecordName()}");

        try {
            $verified = $domain->verify();
        } catch (DomainAlreadyVerifiedException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($verified) {
            $this->info("Domain verified successfully: {$domainName}");

            return self::SUCCESS;
        }

        $this->error('DNS verification failed. Ensure the TXT record matches the verification token.');
        $this->line("Use --force to skip DNS verification (e.g., for local development).");

        return self::FAILURE;
    }
}
