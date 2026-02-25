<?php

namespace Ssntpl\Neev\Commands\Tenant;

use Illuminate\Console\Command;
use Ssntpl\Neev\Commands\Concerns\ResolvesTenantContext;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\Tenant;

class ListTenantsCommand extends Command
{
    use ResolvesTenantContext;

    protected $signature = 'neev:tenant:list
                            {--search= : Filter by name or slug}
                            {--inactive : Show only inactive entries}
                            {--json : Output as JSON}
                            {--limit=25 : Maximum number of results}';

    protected $description = 'List tenants (isolated mode) or teams (shared mode)';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        if ($this->isIsolated()) {
            return $this->listTenants($limit);
        }

        return $this->listTeams($limit);
    }

    protected function listTenants(int $limit): int
    {
        $query = Tenant::getClass()::query()
            ->withCount('teams');

        if ($search = $this->option('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        $tenants = $query->latest()->limit($limit)->get();

        if ($tenants->isEmpty()) {
            $this->info('No tenants found.');

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->line($tenants->toJson(JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Slug', 'Teams', 'Created'],
            $tenants->map(fn ($t) => [
                $t->id,
                $t->name,
                $t->slug,
                $t->teams_count,
                $t->created_at->format('Y-m-d'),
            ]),
        );

        return self::SUCCESS;
    }

    protected function listTeams(int $limit): int
    {
        $query = Team::getClass()::query()
            ->withCount('users');

        if ($search = $this->option('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if ($this->option('inactive')) {
            $query->whereNull('activated_at');
        }

        $teams = $query->latest()->limit($limit)->get();

        if ($teams->isEmpty()) {
            $this->info('No teams found.');

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->line($teams->toJson(JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Slug', 'Members', 'Status', 'Created'],
            $teams->map(fn ($t) => [
                $t->id,
                $t->name,
                $t->slug,
                $t->users_count,
                $t->isActive() ? 'Active' : 'Inactive',
                $t->created_at->format('Y-m-d'),
            ]),
        );

        return self::SUCCESS;
    }
}
