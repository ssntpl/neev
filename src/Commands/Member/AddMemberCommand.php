<?php

namespace Ssntpl\Neev\Commands\Member;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Ssntpl\Neev\Commands\Concerns\ResolvesTenantContext;

use function Laravel\Prompts\text;

class AddMemberCommand extends Command implements PromptsForMissingInput
{
    use ResolvesTenantContext;

    protected $signature = 'neev:member:add {email : Email of the user to add}
                            {--team= : Team ID or slug}
                            {--tenant= : Tenant ID or slug (uses platform team)}
                            {--role= : Role to assign to the member}';

    protected $description = 'Add a user to a team (bypasses invitation flow)';

    public function handle(): int
    {
        $user = $this->resolveUserByEmail($this->argument('email'));

        $team = $this->resolveTargetTeam();
        if (! $team) {
            return self::FAILURE;
        }

        // Check if user is already a member
        if ($team->allUsers()->where('users.id', $user->id)->exists()) {
            $this->warn("{$user->name} is already a member of {$team->name}.");

            return self::FAILURE;
        }

        $role = $this->option('role');

        $team->addMember($user, $role);

        $roleSuffix = $role ? " with role '{$role}'" : '';
        $this->info("Added {$user->name} ({$this->argument('email')}) to {$team->name}{$roleSuffix}.");

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

    protected function promptForMissingArgumentsUsing(): array
    {
        return [
            'email' => fn () => text(
                label: 'What is the email of the user to add?',
                required: true,
            ),
        ];
    }
}
