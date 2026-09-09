<?php

namespace Ssntpl\Neev\Commands\Domain;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Ssntpl\Neev\Commands\Concerns\ResolvesTenantContext;
use Ssntpl\Neev\Models\Domain;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class AddDomainCommand extends Command implements PromptsForMissingInput
{
    use ResolvesTenantContext;

    protected $signature = 'neev:domain:add {domain : The domain to add}
                            {--owner-type= : Owner type (team or tenant)}
                            {--owner-id= : Owner ID}
                            {--primary : Set as primary domain}
                            {--enforce : Enforce domain-based federation}
                            {--skip-verification : Mark as verified immediately}';

    protected $description = 'Add a domain to a tenant or team';

    public function handle(): int
    {
        $domainName = $this->argument('domain');
        $ownerType = $this->option('owner-type') ?: $this->askOwnerType();
        $ownerId = $this->option('owner-id') ?: $this->askOwnerId($ownerType);

        if (! $ownerType || ! $ownerId) {
            $this->error('You must specify --owner-type and --owner-id.');

            return self::FAILURE;
        }

        if (! in_array($ownerType, ['team', 'tenant'], true)) {
            $this->error('--owner-type must be "team" or "tenant".');

            return self::FAILURE;
        }

        // Resolve the owner, which also accepts a slug — the row has to store
        // the key, not whatever was typed.
        $owner = $ownerType === 'tenant'
            ? $this->resolveTenant((string) $ownerId)
            : $this->resolveTeam((string) $ownerId);

        // A second row for the same owner says nothing new.
        if (Domain::where('domain', $domainName)
            ->where('owner_type', $ownerType)
            ->where('owner_id', $owner->getKey())
            ->exists()) {
            $this->error("Domain already added for this {$ownerType}: {$domainName}");

            return self::FAILURE;
        }

        // Taken only once another owner of this kind has verified it.
        if (Domain::findByHostForOwnerType($domainName, $ownerType)) {
            $this->error("Domain already verified by another {$ownerType}: {$domainName}");

            return self::FAILURE;
        }

        $autoVerify = $this->option('skip-verification');

        $domain = Domain::create([
            'domain' => $domainName,
            'owner_type' => $ownerType,
            'owner_id' => $owner->getKey(),
            'is_primary' => (bool) $this->option('primary'),
            'enforce' => (bool) $this->option('enforce'),
            'verified_at' => $autoVerify ? now() : null,
        ]);

        if ($this->option('primary')) {
            $domain->markAsPrimary();
        }

        if ($autoVerify) {
            $this->info("Domain added and verified: {$domainName}");
        } else {
            $token = $domain->generateVerificationToken();
            $this->info("Domain added: {$domainName}");
            $this->newLine();
            $this->warn('To verify, add this DNS TXT record:');
            $this->line("  Name:  {$domain->getDnsRecordName()}");
            $this->line("  Value: {$token}");
            $this->newLine();
            $this->line("Then run: php artisan neev:domain:verify {$domainName}");
        }

        return self::SUCCESS;
    }

    /**
     * Ask what kind of thing owns the domain, when --owner-type was omitted.
     */
    protected function askOwnerType(): ?string
    {
        if (! $this->input->isInteractive()) {
            return null;
        }

        return select(
            label: 'What owns this domain?',
            options: [
                'tenant' => 'A tenant',
                'team' => 'A team',
            ],
            default: $this->isIsolated() ? 'tenant' : 'team',
        );
    }

    /**
     * Ask which one, when --owner-id was omitted.
     */
    protected function askOwnerId(?string $ownerType): ?string
    {
        if ($ownerType === null || ! $this->input->isInteractive()) {
            return null;
        }

        return text(
            label: "Which {$ownerType} owns it? (ID or slug)",
            required: true,
        );
    }

    protected function promptForMissingArgumentsUsing(): array
    {
        return [
            'domain' => fn () => text(
                label: 'What domain would you like to add?',
                required: true,
            ),
        ];
    }
}
