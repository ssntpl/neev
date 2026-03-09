<?php

namespace Ssntpl\Neev\Commands\Domain;

use Illuminate\Console\Command;
use Ssntpl\Neev\Models\Domain;

class ListDomainsCommand extends Command
{
    protected $signature = 'neev:domain:list
                            {--owner-type= : Filter by owner type (team or tenant)}
                            {--owner-id= : Filter by owner ID}
                            {--unverified : Show only unverified domains}
                            {--json : Output as JSON}';

    protected $description = 'List domains';

    public function handle(): int
    {
        $query = Domain::query();

        if ($ownerType = $this->option('owner-type')) {
            $query->where('owner_type', $ownerType);
        }

        if ($ownerId = $this->option('owner-id')) {
            $query->where('owner_id', $ownerId);
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
            ['ID', 'Domain', 'Owner Type', 'Owner ID', 'Primary', 'Enforce', 'Status'],
            $domains->map(fn ($d) => [
                $d->id,
                $d->domain,
                $d->owner_type ?? '-',
                $d->owner_id ?? '-',
                $d->is_primary ? 'Yes' : 'No',
                $d->enforce ? 'Yes' : 'No',
                $d->isVerified() ? 'Verified' : 'Unverified',
            ]),
        );

        return self::SUCCESS;
    }
}
