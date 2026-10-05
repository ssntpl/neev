<?php

namespace Ssntpl\Neev\Commands\Tenant;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Ssntpl\Neev\Commands\Concerns\ResolvesTenantContext;
use Ssntpl\Neev\Models\Hostname;
use Ssntpl\Neev\Models\RetiredSlug;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\Tenant;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Support\PlatformHost;
use Ssntpl\Neev\Support\SlugHelper;

use function Laravel\Prompts\text;

class CreateTenantCommand extends Command implements PromptsForMissingInput
{
    use ResolvesTenantContext;

    /**
     * The owner named by --owner, resolved before anything is created.
     */
    protected ?object $owner = null;

    protected $signature = 'neev:tenant:create {name : The name of the tenant or team}
                            {--slug= : Custom slug (auto-generated from name if omitted)}
                            {--owner= : Owner user ID or email}
                            {--domain= : Custom host to serve it at, proven by DNS}
                            {--activate : Activate the team immediately}';

    protected $description = 'Create a new tenant (isolated mode) or team (shared mode)';

    public function handle(): int
    {
        $name = $this->argument('name');

        if (! $this->isIsolated() && ! $this->teamsEnabled()) {
            $this->error('Team support is disabled. Set neev.team to true in config/neev.php, or enable tenant isolation with neev.tenant.');

            return self::FAILURE;
        }

        if ($this->isIsolated() && $this->option('owner') && ! $this->teamsEnabled()) {
            $this->error('--owner creates a team to hold the owner, but team support is disabled. Set neev.team to true, or create the tenant without --owner.');

            return self::FAILURE;
        }

        if (! $this->validateInputs()) {
            return self::FAILURE;
        }

        if ($this->isIsolated()) {
            return $this->createTenant($name);
        }

        return $this->createTeam($name);
    }

    /**
     * Check every option before the first row is written.
     *
     * The owner used to be looked up after the tenant had been created, so a
     * misspelt email left an ownerless tenant behind; a taken slug or domain
     * failed even later, with more rows already committed.
     */
    protected function validateInputs(): bool
    {
        $errors = [];

        if ($slug = $this->option('slug')) {
            if (! SlugHelper::isValid($slug)) {
                $errors[] = "Invalid slug [{$slug}]: use lowercase letters, digits and hyphens, starting and ending with a letter or digit.";
            } elseif ($this->slugTaken($slug)) {
                $errors[] = "Slug already in use: {$slug}";
            }
        }

        if ($ownerRef = $this->option('owner')) {
            $this->owner = ctype_digit($ownerRef)
                ? User::getClass()::find((int) $ownerRef)
                : User::findByEmail($ownerRef);

            if (! $this->owner) {
                $errors[] = "Owner not found: {$ownerRef}";
            }
        }

        if ($domain = $this->option('domain')) {
            // A host is unique across every owner, so any holder clashes.
            $held = Hostname::forHost($domain)->first();

            if (PlatformHost::covers($domain)) {
                $errors[] = "A host under the platform domain follows the slug: {$domain}";
            } elseif ($held !== null) {
                $errors[] = "Host already claimed: {$domain}";
            }
        }

        if ($errors === []) {
            return true;
        }

        $this->error('Nothing was created. Fix the following and run the command again:');
        foreach ($errors as $error) {
            $this->line("  {$error}");
        }

        return false;
    }

    /**
     * Is the slug already taken by whatever this command is about to create?
     */
    protected function slugTaken(string $slug): bool
    {
        // A slug another owner has retired is never issued again.
        if (RetiredSlug::heldAgainst($this->isIsolated() ? 'tenant' : 'team', $slug)->exists()) {
            return true;
        }

        if ($this->isIsolated()) {
            return Tenant::getClass()::where('slug', $slug)->exists();
        }

        return Team::withoutTenantScope()->where('slug', $slug)->exists();
    }

    protected function createTenant(string $name): int
    {
        $slug = $this->option('slug') ?: SlugHelper::generateForTenant($name);

        $tenant = Tenant::getClass()::create([
            'name' => $name,
            'slug' => $slug,
        ]);

        $this->info("Tenant created: {$tenant->name} (slug: {$tenant->slug}, ID: {$tenant->id})");

        if ($owner = $this->owner) {
            $teamData = [
                'name' => $name,
                'slug' => SlugHelper::generate($name),
                'user_id' => $owner->id,
                'tenant_id' => $tenant->id,
            ];

            if ($this->option('activate')) {
                $teamData['activated_at'] = now();
            }

            // forceCreate, because tenant_id is deliberately not fillable —
            // mass assignment dropped it and left the tenant's own team on the
            // platform, invisible once that tenant is resolved.
            $team = Team::getClass()::forceCreate($teamData);
            $team->allUsers()->attach($owner->id, ['joined' => true]);

            $this->info("Team created: {$team->name} (ID: {$team->id})");
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

        if ($owner = $this->owner) {
            $attributes['user_id'] = $owner->id;
        }

        if ($this->option('activate')) {
            $attributes['activated_at'] = now();
        }

        $team = Team::getClass()::create($attributes);

        if (isset($owner)) {
            $team->allUsers()->attach($owner->id, ['joined' => true]);
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

    /**
     * Claim the host for what was just created. It is pending until its TXT
     * record is checked, and only then can it be made the primary.
     */
    protected function attachDomain(string $domain, ?object $team, ?object $tenant): void
    {
        /** @var Team|Tenant $owner */
        $owner = $tenant ?? $team;
        $hostname = $owner->claimHost($domain);

        $this->info("Domain attached: {$hostname->host}");
        $this->warn("Verify via DNS TXT record:");
        $this->line("  Name:  {$hostname->getDnsRecordName()}");
        $this->line("  Value: {$hostname->verification_token}");
        $this->line("Then run: php artisan neev:hostname:verify {$hostname->host}");
        $this->line("and, to make it the primary: php artisan neev:hostname:primary {$hostname->host}");
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
