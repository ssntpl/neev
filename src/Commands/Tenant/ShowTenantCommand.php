<?php

namespace Ssntpl\Neev\Commands\Tenant;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Ssntpl\Neev\Commands\Concerns\ResolvesTenantContext;
use Ssntpl\Neev\Models\Domain;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\Tenant;

use function Laravel\Prompts\text;

class ShowTenantCommand extends Command implements PromptsForMissingInput
{
    use ResolvesTenantContext;

    protected $signature = 'neev:tenant:show {identifier : ID, slug, or domain}
                            {--json : Output as JSON}';

    protected $description = 'Show details for a tenant (isolated) or team (shared)';

    public function handle(): int
    {
        $identifier = $this->argument('identifier');

        if ($this->isIsolated()) {
            return $this->showTenant($identifier);
        }

        return $this->showTeam($identifier);
    }

    protected function showTenant(string $identifier): int
    {
        $tenant = $this->findTenant($identifier);

        if (! $tenant) {
            $this->error("Tenant not found: {$identifier}");

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line($tenant->load(['teams', 'domains', 'authSettings'])->toJson(JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line("  <info>Tenant:</info> {$tenant->name}");
        $this->line("  <info>ID:</info>     {$tenant->id}");
        $this->line("  <info>Slug:</info>   {$tenant->slug}");

        if ($tenant->managed_by_tenant_id) {
            $parent = $tenant->managedBy;
            $this->line("  <info>Managed by:</info> {$parent->name} (ID: {$parent->id})");
        }

        if ($tenant->platform_team_id) {
            $team = $tenant->platformTeam;
            $this->line("  <info>Platform team:</info> {$team->name} (ID: {$team->id})");
        }

        $this->line("  <info>Teams:</info>  {$tenant->teams()->count()}");
        $this->line("  <info>Created:</info> {$tenant->created_at->format('Y-m-d H:i')}");

        $domains = $tenant->domains;
        if ($domains->isNotEmpty()) {
            $this->newLine();
            $this->line('  <info>Domains:</info>');
            foreach ($domains as $domain) {
                $status = $domain->isVerified() ? '<fg=green>verified</>' : '<fg=yellow>unverified</>';
                $primary = $domain->is_primary ? ' (primary)' : '';
                $this->line("    - {$domain->domain} [{$status}]{$primary}");
            }
        }

        if ($tenant->authSettings) {
            $this->newLine();
            $this->line("  <info>Auth method:</info> {$tenant->authSettings->auth_method}");
            if ($tenant->authSettings->isSSO()) {
                $this->line("  <info>SSO provider:</info> {$tenant->authSettings->sso_provider}");
            }
        }

        $this->newLine();

        return self::SUCCESS;
    }

    protected function showTeam(string $identifier): int
    {
        $team = $this->findTeam($identifier);

        if (! $team) {
            $this->error("Team not found: {$identifier}");

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line($team->load(['owner', 'domains', 'authSettings'])->toJson(JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line("  <info>Team:</info>    {$team->name}");
        $this->line("  <info>ID:</info>      {$team->id}");
        $this->line("  <info>Slug:</info>    {$team->slug}");
        $this->line("  <info>Status:</info>  " . ($team->isActive() ? '<fg=green>Active</>' : '<fg=yellow>Inactive</>'));

        if ($team->inactive_reason) {
            $this->line("  <info>Reason:</info>  {$team->inactive_reason}");
        }

        if ($team->owner) {
            $this->line("  <info>Owner:</info>   {$team->owner->name} (ID: {$team->owner->id})");
        }

        $this->line("  <info>Members:</info> {$team->users()->count()}");
        $this->line("  <info>Created:</info> {$team->created_at->format('Y-m-d H:i')}");

        $domains = $team->domains;
        if ($domains->isNotEmpty()) {
            $this->newLine();
            $this->line('  <info>Domains:</info>');
            foreach ($domains as $domain) {
                $status = $domain->isVerified() ? '<fg=green>verified</>' : '<fg=yellow>unverified</>';
                $primary = $domain->is_primary ? ' (primary)' : '';
                $this->line("    - {$domain->domain} [{$status}]{$primary}");
            }
        }

        if ($team->authSettings) {
            $this->newLine();
            $this->line("  <info>Auth method:</info> {$team->authSettings->auth_method}");
            if ($team->authSettings->isSSO()) {
                $this->line("  <info>SSO provider:</info> {$team->authSettings->sso_provider}");
            }
        }

        $this->newLine();

        return self::SUCCESS;
    }

    protected function findTenant(string $identifier): ?Tenant
    {
        $class = Tenant::getClass();

        if (ctype_digit($identifier)) {
            return $class::find((int) $identifier);
        }

        $tenant = $class::where('slug', $identifier)->first();
        if ($tenant) {
            return $tenant;
        }

        // Try domain resolution
        $domain = Domain::where('domain', $identifier)->where('owner_type', 'tenant')->first();

        return $domain?->owner;
    }

    protected function findTeam(string $identifier): ?Team
    {
        $class = Team::getClass();

        if (ctype_digit($identifier)) {
            return $class::find((int) $identifier);
        }

        $team = $class::where('slug', $identifier)->first();
        if ($team) {
            return $team;
        }

        // Try domain resolution
        $domain = Domain::where('domain', $identifier)->where('owner_type', 'team')->first();

        return $domain?->owner;
    }

    protected function promptForMissingArgumentsUsing(): array
    {
        $label = $this->isIsolated() ? 'tenant' : 'team';

        return [
            'identifier' => fn () => text(
                label: "Enter the {$label} ID, slug, or domain:",
                required: true,
            ),
        ];
    }
}
