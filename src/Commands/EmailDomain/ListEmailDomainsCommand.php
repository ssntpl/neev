<?php

namespace Ssntpl\Neev\Commands\EmailDomain;

use Illuminate\Console\Command;
use Ssntpl\Neev\Commands\Concerns\ManagesDomainRows;
use Ssntpl\Neev\Models\EmailDomain;

class ListEmailDomainsCommand extends Command
{
    use ManagesDomainRows;

    protected $signature = 'neev:email-domain:list
                            {--owner-type= : Filter by owner type (team or tenant)}
                            {--owner-id= : Filter by owner ID, or slug with --owner-type}
                            {--unverified : Show only unverified domains}
                            {--json : Output as JSON}';

    protected $description = 'List email domains';

    public function handle(): int
    {
        $query = EmailDomain::query()->latest();

        if (! $this->whereOwnerFromOptions($query)) {
            return self::FAILURE;
        }

        if ($this->option('unverified')) {
            $query->whereNull('verified_at');
        }

        $domains = $query->get();

        if ($domains->isEmpty()) {
            $this->info('No domains found.');

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->line($domains->toJson(JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Domain', 'Owner Type', 'Owner ID', 'Enforce', 'Status'],
            $domains->map(fn (EmailDomain $d) => [
                $d->id,
                $d->domain,
                $d->owner_type,
                $d->owner_id,
                $d->enforce ? 'Yes' : 'No',
                ucfirst($d->status),
            ]),
        );

        return self::SUCCESS;
    }
}
