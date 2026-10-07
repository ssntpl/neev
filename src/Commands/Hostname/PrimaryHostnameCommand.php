<?php

namespace Ssntpl\Neev\Commands\Hostname;

use Illuminate\Console\Command;
use Ssntpl\Neev\Models\Hostname;

class PrimaryHostnameCommand extends Command
{
    protected $signature = 'neev:hostname:primary {host : The verified host to make its owner\'s primary}';

    protected $description = 'Make a custom host its owner\'s primary host';

    public function handle(): int
    {
        $host = Hostname::canonicalHost((string) $this->argument('host'));
        $hostname = Hostname::forHost($host)->first();

        if ($hostname === null) {
            $this->error("Host not found: {$host}");

            return self::FAILURE;
        }

        if (! $hostname->isVerified()) {
            $this->error("Only a verified host can be primary: {$host}");
            $this->line("Verify it first: php artisan neev:hostname:verify {$host}");

            return self::FAILURE;
        }

        // The CLI runs outside any tenant, so look the owner up across them.
        $owner = $hostname->owner()->withoutGlobalScopes()->first();

        if ($owner === null || ! method_exists($owner, 'makePrimaryHostname')) {
            $this->error("The owner of {$host} keeps no primary host.");

            return self::FAILURE;
        }

        $owner->makePrimaryHostname($hostname);
        $this->info("Primary host set: {$host}");

        return self::SUCCESS;
    }
}
