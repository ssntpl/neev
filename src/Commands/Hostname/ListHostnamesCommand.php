<?php

namespace Ssntpl\Neev\Commands\Hostname;

use Illuminate\Console\Command;
use Ssntpl\Neev\Commands\Concerns\ManagesDomainRows;
use Ssntpl\Neev\Models\Hostname;

class ListHostnamesCommand extends Command
{
    use ManagesDomainRows;

    protected $signature = 'neev:hostname:list
                            {--owner-type= : Filter by owner type (team or tenant)}
                            {--owner-id= : Filter by owner ID, or slug with --owner-type}
                            {--unverified : Show only unverified hosts}
                            {--json : Output as JSON}';

    protected $description = 'List custom hosts';

    public function handle(): int
    {
        // The CLI runs outside any tenant, so load owners across them.
        $query = Hostname::query()->with(['owner' => fn ($q) => $q->withoutGlobalScopes()])->latest();

        if (! $this->whereOwnerFromOptions($query)) {
            return self::FAILURE;
        }

        if ($this->option('unverified')) {
            $query->whereNull('verified_at');
        }

        $hostnames = $query->get();

        if ($hostnames->isEmpty()) {
            $this->info('No hosts found.');

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->line($hostnames->toJson(JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Host', 'Owner Type', 'Owner ID', 'Primary', 'Status'],
            $hostnames->map(fn (Hostname $h) => [
                $h->id,
                $h->host,
                $h->owner_type,
                $h->owner_id,
                (int) $h->owner->getAttribute('primary_hostname_id') === $h->id ? 'Yes' : 'No',
                ucfirst($h->status),
            ]),
        );

        return self::SUCCESS;
    }
}
