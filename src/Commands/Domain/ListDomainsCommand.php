<?php

namespace Ssntpl\Neev\Commands\Domain;

use Illuminate\Console\Command;
use Ssntpl\Neev\Commands\Concerns\ResolvesTenantContext;
use Ssntpl\Neev\Models\Domain;

class ListDomainsCommand extends Command
{
    use ResolvesTenantContext;

    protected $signature = 'neev:domain:list
                            {--tenant= : Filter by tenant ID or slug}
                            {--team= : Filter by team ID or slug}
                            {--unverified : Show only unverified domains}
                            {--json : Output as JSON}';

    protected $description = 'List domains';

    public function handle(): int
    {
        $query = Domain::query();

        if ($tenantRef = $this->option('tenant')) {
            $tenant = $this->resolveTenant($tenantRef);
            $query->where('tenant_id', $tenant->id);
        }

        if ($teamRef = $this->option('team')) {
            $team = $this->resolveTeam($teamRef);
            $query->where('team_id', $team->id);
        }

        if ($this->option('unverified')) {
            $query->whereNull('verified_at');
        }

        $domains = $query->latest()->get();

        if ($domains->isEmpty()) {
            $this->info('No domains found.');

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->line($domains->toJson(JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Domain', 'Team ID', 'Tenant ID', 'Primary', 'Enforce', 'Status'],
            $domains->map(fn ($d) => [
                $d->id,
                $d->domain,
                $d->team_id ?? '-',
                $d->tenant_id ?? '-',
                $d->is_primary ? 'Yes' : 'No',
                $d->enforce ? 'Yes' : 'No',
                $d->isVerified() ? 'Verified' : 'Unverified',
            ]),
        );

        return self::SUCCESS;
    }
}
