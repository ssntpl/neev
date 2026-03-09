<?php

namespace Ssntpl\Neev\Commands\Auth;

use Illuminate\Console\Command;
use Ssntpl\Neev\Commands\Concerns\ResolvesTenantContext;

class ShowAuthCommand extends Command
{
    use ResolvesTenantContext;

    protected $signature = 'neev:auth:show
                            {--tenant= : Tenant ID or slug}
                            {--team= : Team ID or slug}
                            {--reveal : Show client ID (secret is never shown)}
                            {--json : Output as JSON}';

    protected $description = 'Display authentication configuration for a tenant or team';

    public function handle(): int
    {
        if ($this->isIsolated() && $this->option('tenant')) {
            return $this->showTenantAuth();
        }

        if ($this->option('team')) {
            return $this->showTeamAuth();
        }

        $label = $this->getStrategyLabel();
        $this->error("You must specify --{$label}" . ($this->isIsolated() ? ' or --team' : '') . '.');

        return self::FAILURE;
    }

    protected function showTenantAuth(): int
    {
        $tenant = $this->resolveTenant($this->option('tenant'));
        $settings = $tenant->authSettings;

        if (! $settings) {
            $this->info("No auth settings configured for tenant '{$tenant->name}'.");
            $this->line('Default method: ' . 'password');

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $data = $settings->toArray();
            unset($data['sso_client_secret']);
            if (! $this->option('reveal')) {
                unset($data['sso_client_id']);
            }
            $this->line(json_encode($data, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        return $this->displaySettings($tenant->name, 'tenant', $settings);
    }

    protected function showTeamAuth(): int
    {
        $team = $this->resolveTeam($this->option('team'));
        $settings = $team->authSettings;

        if (! $settings) {
            $this->info("No auth settings configured for team '{$team->name}'.");
            $this->line('Default method: ' . 'password');

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $data = $settings->toArray();
            unset($data['sso_client_secret']);
            if (! $this->option('reveal')) {
                unset($data['sso_client_id']);
            }
            $this->line(json_encode($data, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        return $this->displaySettings($team->name, 'team', $settings);
    }

    protected function displaySettings(string $name, string $type, object $settings): int
    {
        $this->newLine();
        $this->line("  <info>Entity:</info>         {$name} ({$type})");
        $this->line("  <info>Auth method:</info>    {$settings->auth_method}");

        if ($settings->isSSO()) {
            $this->line("  <info>SSO provider:</info>  {$settings->sso_provider}");

            if ($this->option('reveal')) {
                $this->line("  <info>Client ID:</info>     {$settings->sso_client_id}");
            } else {
                $this->line('  <info>Client ID:</info>     ******** (use --reveal to show)');
            }

            $this->line('  <info>Client secret:</info> ******** (never shown)');

            if ($settings->sso_tenant_id) {
                $this->line("  <info>SSO tenant ID:</info> {$settings->sso_tenant_id}");
            }

            $this->line('  <info>SSO configured:</info> ' . ($settings->hasSSOConfigured() ? '<fg=green>Yes</>' : '<fg=red>No</>'));
        }

        $this->line('  <info>Auto-provision:</info> ' . ($settings->auto_provision ? 'Yes' : 'No'));

        if ($settings->auto_provision_role) {
            $this->line("  <info>Auto-provision role:</info> {$settings->auto_provision_role}");
        }

        $this->newLine();

        return self::SUCCESS;
    }
}
