<?php

namespace Ssntpl\Neev\Commands\Member;

use Illuminate\Console\Command;
use Ssntpl\Neev\Commands\Concerns\ResolvesTenantContext;

class ListMembersCommand extends Command
{
    use ResolvesTenantContext;

    protected $signature = 'neev:member:list
                            {--team= : Team ID or slug}
                            {--tenant= : Tenant ID or slug (lists platform team members)}
                            {--json : Output as JSON}';

    protected $description = 'List members of a team or tenant';

    public function handle(): int
    {
        $team = $this->resolveTargetTeam();
        if (! $team) {
            return self::FAILURE;
        }

        $members = $team->allUsers()->with('email')->get();

        if ($members->isEmpty()) {
            $this->info("No members found in {$team->name}.");

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->line($members->toJson(JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Email', 'Role', 'Joined', 'Since'],
            $members->map(fn ($m) => [
                $m->id,
                $m->name,
                $m->email?->email ?? '-',
                $m->membership->role ?? '-',
                $m->membership->joined ? 'Yes' : 'No',
                $m->membership->created_at?->format('Y-m-d') ?? '-',
            ]),
        );

        return self::SUCCESS;
    }

    protected function resolveTargetTeam(): ?object
    {
        if ($teamRef = $this->option('team')) {
            return $this->resolveTeam($teamRef);
        }

        if ($tenantRef = $this->option('tenant')) {
            $tenant = $this->resolveTenant($tenantRef);

            if (! $tenant->platform_team_id) {
                $this->error("Tenant '{$tenant->name}' has no platform team.");

                return null;
            }

            return $tenant->platformTeam;
        }

        $this->error('You must specify --team or --tenant.');

        return null;
    }
}
