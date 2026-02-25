<?php

namespace Ssntpl\Neev\Commands\Member;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Ssntpl\Neev\Commands\Concerns\ResolvesTenantContext;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

class RemoveMemberCommand extends Command implements PromptsForMissingInput
{
    use ResolvesTenantContext;

    protected $signature = 'neev:member:remove {email : Email of the user to remove}
                            {--team= : Team ID or slug}
                            {--force : Skip confirmation}';

    protected $description = 'Remove a user from a team';

    public function handle(): int
    {
        $user = $this->resolveUserByEmail($this->argument('email'));

        if (! $this->option('team')) {
            $this->error('You must specify --team.');

            return self::FAILURE;
        }

        $team = $this->resolveTeam($this->option('team'));

        // Prevent removing owner
        if ($team->user_id === $user->id) {
            $this->error("Cannot remove the team owner ({$user->name}).");

            return self::FAILURE;
        }

        // Check membership
        if (! $team->allUsers()->where('users.id', $user->id)->exists()) {
            $this->warn("{$user->name} is not a member of {$team->name}.");

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $confirmed = confirm(
                label: "Remove {$user->name} ({$this->argument('email')}) from {$team->name}?",
                default: false,
            );

            if (! $confirmed) {
                $this->info('Cancelled.');

                return self::SUCCESS;
            }
        }

        $team->allUsers()->detach($user->id);

        $this->info("Removed {$user->name} from {$team->name}.");

        return self::SUCCESS;
    }

    protected function promptForMissingArgumentsUsing(): array
    {
        return [
            'email' => fn () => text(
                label: 'What is the email of the user to remove?',
                required: true,
            ),
        ];
    }
}
