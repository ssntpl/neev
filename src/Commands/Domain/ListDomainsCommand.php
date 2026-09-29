<?php

namespace Ssntpl\Neev\Commands\Domain;

use Illuminate\Console\Command;
use Ssntpl\Neev\Commands\Concerns\ResolvesTenantContext;
use Ssntpl\Neev\Models\Domain;

class ListDomainsCommand extends Command
{
    use ResolvesTenantContext;

    protected $signature = 'neev:domain:list
                            {--owner-type= : Filter by owner type (team or tenant)}
                            {--owner-id= : Filter by owner ID, or slug with --owner-type}
                            {--unverified : Show only unverified domains}
                            {--json : Output as JSON}';

    protected $description = 'List domains';

    public function handle(): int
    {
        $query = Domain::query();

        $ownerType = $this->option('owner-type');
        $ownerId = (string) $this->option('owner-id');

        if ($ownerType) {
            $query->where('owner_type', $ownerType);
        }

        if ($ownerId !== '') {
            if (ctype_digit($ownerId)) {
                $query->where('owner_id', (int) $ownerId);
            } else {
                // A slug names a team or a tenant, and the two are looked up in
                // different tables, so the kind has to be given. Matched as a
                // raw owner_id, a slug used to list nothing, without saying why.
                if (! in_array($ownerType, ['team', 'tenant'], true)) {
                    $this->error('A slug in --owner-id needs --owner-type team or tenant.');

                    return self::FAILURE;
                }

                $owner = $ownerType === 'tenant'
                    ? $this->resolveTenant($ownerId)
                    : $this->resolveTeam($ownerId);
                $query->where('owner_id', $owner->getKey());
            }
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
