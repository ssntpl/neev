<?php

namespace Ssntpl\Neev\Commands\Team;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Ssntpl\Neev\Commands\Concerns\ResolvesTenantContext;

use function Laravel\Prompts\text;

class ActivateTeamCommand extends Command implements PromptsForMissingInput
{
    use ResolvesTenantContext;

    protected $signature = 'neev:team:activate {team : Team ID or slug}
                            {--deactivate : Deactivate instead of activate}
                            {--reason= : Reason for deactivation}';

    protected $description = 'Activate or deactivate a team';

    public function handle(): int
    {
        $team = $this->resolveTeam($this->argument('team'));

        if ($this->option('deactivate')) {
            if (! $team->isActive()) {
                $this->warn("Team '{$team->name}' is already inactive.");

                return self::SUCCESS;
            }

            $team->deactivate($this->option('reason'));
            $this->info("Team '{$team->name}' has been deactivated.");

            if ($reason = $this->option('reason')) {
                $this->line("  Reason: {$reason}");
            }

            return self::SUCCESS;
        }

        if ($team->isActive()) {
            $this->warn("Team '{$team->name}' is already active.");

            return self::SUCCESS;
        }

        $team->activate();
        $this->info("Team '{$team->name}' has been activated.");

        return self::SUCCESS;
    }

    protected function promptForMissingArgumentsUsing(): array
    {
        return [
            'team' => fn () => text(
                label: 'Enter the team ID or slug:',
                required: true,
            ),
        ];
    }
}
