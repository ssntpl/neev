<?php

namespace Ssntpl\Neev\Commands\Tenant;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Ssntpl\Neev\Commands\Concerns\ResolvesTenantContext;
use Ssntpl\Neev\Models\Domain;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\Tenant;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Support\SlugHelper;

use function Laravel\Prompts\text;

class CreateTenantCommand extends Command implements PromptsForMissingInput
{
    use ResolvesTenantContext;

    protected $signature = 'neev:tenant:create {name : The name of the tenant or team}
                            {--slug= : Custom slug (auto-generated from name if omitted)}
                            {--owner= : Owner user ID or email}
                            {--domain= : Domain to attach}
                            {--activate : Activate the team immediately}
                            {--managed-by= : Parent tenant ID or slug (isolated mode only)}';

    protected $description = 'Create a new tenant (isolated mode) or team (shared mode)';

    public function handle(): int
    {
        $name = $this->argument('name');

        if ($this->isIsolated()) {
            return $this->createTenant($name);
        }

        return $this->createTeam($name);
    }

    protected function createTenant(string $name): int
    {
        $slug = $this->option('slug') ?: SlugHelper::generateForTenant($name);

        $attributes = [
            'name' => $name,
            'slug' => $slug,
        ];

        if ($managedBy = $this->option('managed-by')) {
            $parent = $this->resolveTenant($managedBy);
            $attributes['managed_by_tenant_id'] = $parent->id;
        }

        $tenant = Tenant::getClass()::create($attributes);

        $this->info("Tenant created: {$tenant->name} (slug: {$tenant->slug}, ID: {$tenant->id})");

        // Create platform team with owner if provided
        if ($ownerRef = $this->option('owner')) {
            $owner = ctype_digit($ownerRef)
                ? (User::getClass()::findOrFail((int) $ownerRef))
                : $this->resolveUserByEmail($ownerRef);

            $teamData = [
                'name' => $name,
                'slug' => SlugHelper::generate($name),
                'user_id' => $owner->id,
                'tenant_id' => $tenant->id,
            ];

            if ($this->option('activate')) {
                $teamData['activated_at'] = now();
            }

            $team = Team::getClass()::create($teamData);
            $team->allUsers()->attach($owner->id, ['role' => 'owner', 'joined' => true]);

            $tenant->update(['platform_team_id' => $team->id]);

            $this->info("Platform team created: {$team->name} (ID: {$team->id})");
            $this->info("Owner: {$owner->name} (ID: {$owner->id})");
        }

        if ($domain = $this->option('domain')) {
            $this->attachDomain($domain, null, $tenant);
        }

        return self::SUCCESS;
    }

    protected function createTeam(string $name): int
    {
        $slug = $this->option('slug') ?: SlugHelper::generate($name);

        $attributes = [
            'name' => $name,
            'slug' => $slug,
        ];

        if ($ownerRef = $this->option('owner')) {
            $owner = ctype_digit($ownerRef)
                ? (User::getClass()::findOrFail((int) $ownerRef))
                : $this->resolveUserByEmail($ownerRef);

            $attributes['user_id'] = $owner->id;
        }

        if ($this->option('activate')) {
            $attributes['activated_at'] = now();
        }

        $team = Team::getClass()::create($attributes);

        if (isset($owner)) {
            $team->allUsers()->attach($owner->id, ['role' => 'owner', 'joined' => true]);
            $this->info("Team created: {$team->name} (slug: {$team->slug}, ID: {$team->id})");
            $this->info("Owner: {$owner->name} (ID: {$owner->id})");
        } else {
            $this->info("Team created: {$team->name} (slug: {$team->slug}, ID: {$team->id})");
        }

        if ($domain = $this->option('domain')) {
            $this->attachDomain($domain, $team, null);
        }

        return self::SUCCESS;
    }

    protected function attachDomain(string $domain, ?object $team, ?object $tenant): void
    {
        $subdomainSuffix = config('neev.tenant_isolation_options.subdomain_suffix');
        $isSubdomain = $subdomainSuffix && str_ends_with($domain, '.' . ltrim($subdomainSuffix, '.'));

        $domainRecord = Domain::create([
            'domain' => $domain,
            'team_id' => $team?->id,
            'tenant_id' => $tenant?->id,
            'is_primary' => true,
            'verified_at' => $isSubdomain ? now() : null,
        ]);

        if ($isSubdomain) {
            $this->info("Domain attached and auto-verified: {$domain}");
        } else {
            $token = $domainRecord->generateVerificationToken();
            $this->info("Domain attached: {$domain}");
            $this->warn("Verify via DNS TXT record:");
            $this->line("  Name:  {$domainRecord->getDnsRecordName()}");
            $this->line("  Value: {$token}");
            $this->line("Then run: php artisan neev:domain:verify {$domain}");
        }
    }

    protected function promptForMissingArgumentsUsing(): array
    {
        $label = $this->isIsolated() ? 'tenant' : 'team';

        return [
            'name' => fn () => text(
                label: "What is the name of the {$label}?",
                required: true,
            ),
        ];
    }
}
