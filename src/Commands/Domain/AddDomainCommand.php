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
                            {--tenant= : Tenant ID or slug (isolated mode)}
                            {--team= : Team ID or slug (shared mode)}
                            {--primary : Set as primary domain}
                            {--enforce : Enforce domain-based federation}
                            {--skip-verification : Mark as verified immediately}';

    protected $description = 'Add a domain to a tenant or team';

    public function handle(): int
    {
        $domainName = $this->argument('domain');
        $teamId = null;
        $tenantId = null;

        if ($this->option('tenant')) {
            $tenant = $this->resolveTenant($this->option('tenant'));
            $tenantId = $tenant->id;
        } elseif ($this->option('team')) {
            $team = $this->resolveTeam($this->option('team'));
            $teamId = $team->id;
        } else {
            $this->error('You must specify --tenant or --team.');

            return self::FAILURE;
        }

        // Check if domain already exists
        $existing = Domain::where('domain', $domainName)->first();
        if ($existing) {
            $this->error("Domain already exists: {$domainName}");

            return self::FAILURE;
        }

        $subdomainSuffix = config('neev.tenant_isolation_options.subdomain_suffix');
        $isSubdomain = $subdomainSuffix && str_ends_with($domainName, '.' . ltrim($subdomainSuffix, '.'));

        $autoVerify = $isSubdomain || $this->option('skip-verification');

        $domain = Domain::create([
            'domain' => $domainName,
            'team_id' => $teamId,
            'tenant_id' => $tenantId,
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
