<?php

namespace Ssntpl\Neev\Commands\Domain;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Ssntpl\Neev\Commands\Concerns\ResolvesTenantContext;
use Ssntpl\Neev\Models\Domain;

use function Laravel\Prompts\text;

class AddDomainCommand extends Command implements PromptsForMissingInput
{
    use ResolvesTenantContext;

    protected $signature = 'neev:domain:add {domain : The domain to add}
                            {--owner-type= : Owner type (team or tenant)}
                            {--owner-id= : Owner ID}
                            {--primary : Set as primary domain}
                            {--enforce : Enforce domain-based federation}
                            {--skip-verification : Mark as verified immediately}';

    protected $description = 'Add a domain to a tenant or team';

    public function handle(): int
    {
        $domainName = $this->argument('domain');
        $ownerType = $this->option('owner-type');
        $ownerId = $this->option('owner-id');

        if (! $ownerType || ! $ownerId) {
            $this->error('You must specify --owner-type and --owner-id.');

            return self::FAILURE;
        }

        if (! in_array($ownerType, ['team', 'tenant'])) {
            $this->error('--owner-type must be "team" or "tenant".');

            return self::FAILURE;
        }

        // Validate owner exists
        if ($ownerType === 'tenant') {
            $this->resolveTenant((string) $ownerId);
        } else {
            $this->resolveTeam((string) $ownerId);
        }

        // Check if domain already exists
        $existing = Domain::where('domain', $domainName)->first();
        if ($existing) {
            $this->error("Domain already exists: {$domainName}");

            return self::FAILURE;
        }

        $autoVerify = $this->option('skip-verification');

        $domain = Domain::create([
            'domain' => $domainName,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'is_primary' => (bool) $this->option('primary'),
            'enforce' => (bool) $this->option('enforce'),
            'verified_at' => $autoVerify ? now() : null,
        ]);

        if ($this->option('primary')) {
            $domain->markAsPrimary();
        }

        if ($autoVerify) {
            $this->info("Domain added and verified: {$domainName}");
        } else {
            $token = $domain->generateVerificationToken();
            $this->info("Domain added: {$domainName}");
            $this->newLine();
            $this->warn('To verify, add this DNS TXT record:');
            $this->line("  Name:  {$domain->getDnsRecordName()}");
            $this->line("  Value: {$token}");
            $this->newLine();
            $this->line("Then run: php artisan neev:domain:verify {$domainName}");
        }

        return self::SUCCESS;
    }

    protected function promptForMissingArgumentsUsing(): array
    {
        return [
            'domain' => fn () => text(
                label: 'What domain would you like to add?',
                required: true,
            ),
        ];
    }
}
