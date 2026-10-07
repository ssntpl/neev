<?php

namespace Ssntpl\Neev\Commands\Hostname;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use InvalidArgumentException;
use Ssntpl\Neev\Commands\Concerns\ManagesDomainRows;
use Ssntpl\Neev\Exceptions\HostnameTakenException;
use Ssntpl\Neev\Models\Hostname;

use function Laravel\Prompts\text;

class AddHostnameCommand extends Command implements PromptsForMissingInput
{
    use ManagesDomainRows;

    protected $signature = 'neev:hostname:add {host : The custom host to serve the owner at}
                            {--owner-type= : Owner type (team or tenant)}
                            {--owner-id= : Owner ID or slug}';

    protected $description = 'Add a custom host a tenant or team is served at';

    public function handle(): int
    {
        $owner = $this->ownerFromOptions();

        if ($owner === null) {
            return self::FAILURE;
        }

        $host = Hostname::canonicalHost((string) $this->argument('host'));

        if ($owner->hostnames()->forHost($host)->exists()) {
            $this->error("Host already added for this {$owner->getContextType()}: {$host}");

            return self::FAILURE;
        }

        try {
            $hostname = $owner->claimHost($host);
        } catch (HostnameTakenException|InvalidArgumentException $e) {
            $this->error("{$e->getMessage()} ({$host})");

            return self::FAILURE;
        }

        $this->info("Host added: {$host}");
        $this->newLine();
        $this->warn('To verify, add this DNS TXT record:');
        $this->line("  Name:  {$hostname->getDnsRecordName()}");
        $this->line("  Value: {$hostname->verification_token}");
        $this->newLine();
        $this->line("Then run: php artisan neev:hostname:verify {$host}");

        return self::SUCCESS;
    }

    protected function promptForMissingArgumentsUsing(): array
    {
        return [
            'host' => fn () => text(label: 'What host would you like to add?', required: true),
        ];
    }
}
