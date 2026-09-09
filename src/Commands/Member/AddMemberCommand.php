<?php

namespace Ssntpl\Neev\Commands\Member;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Ssntpl\Neev\Commands\Concerns\ResolvesTenantContext;

use function Laravel\Prompts\text;

class AddMemberCommand extends Command implements PromptsForMissingInput
{
    use ResolvesTenantContext;

    protected $signature = 'neev:member:add {email : Email of the user to add}
                            {--team= : Team ID or slug (required)}
                            {--role= : Role to assign to the member}';

    protected $description = 'Add a user to a team (bypasses invitation flow)';

    public function handle(): int
    {
        $team = $this->resolveTargetTeam();
        if (! $team) {
            return self::FAILURE;
        }

        // Resolve the team first: it supplies the context the user is looked
        // up in, so the tenant scope finds that tenant's user.
        $user = $this->resolveUserByEmail($this->argument('email'), $this->contextForTeam($team));

        // Under isolation a team belongs to one tenant, and so does a user.
        // Adding across that line would put someone in a team their tenant
        // never gave them access to.
        if ($this->isIsolated() && $user->tenant_id !== $team->tenant_id) {
            $this->error("{$user->name} belongs to a different tenant than {$team->name}.");

            return self::FAILURE;
        }

        // Check if user is already a member
        if ($team->allUsers()->where('users.id', $user->id)->exists()) {
            $this->warn("{$user->name} is already a member of {$team->name}.");

            return self::FAILURE;
        }

        $role = $this->option('role');

        try {
            // addMember attaches the membership first and grants the role
            // second, so an unknown role would otherwise leave the user in the
            // team without it. Roll the attach back and report the bad role.
            DB::transaction(fn () => $team->addMember($user, $role));
        } catch (InvalidArgumentException $e) {
            $this->error("Role not found: {$role}");

            return self::FAILURE;
        }

        $roleSuffix = $role ? " with role '{$role}'" : '';
        $this->info("Added {$user->name} ({$this->argument('email')}) to {$team->name}{$roleSuffix}.");

        return self::SUCCESS;
    }

    protected function resolveTargetTeam(): ?object
    {
        if ($teamRef = $this->option('team')) {
            return $this->resolveTeam($teamRef);
        }

        $this->error('You must specify --team.');

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
