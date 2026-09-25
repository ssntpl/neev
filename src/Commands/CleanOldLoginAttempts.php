<?php

namespace Ssntpl\Neev\Commands;

use Illuminate\Console\Command;
use Ssntpl\Neev\Models\LoginAttempt;

class CleanOldLoginAttempts extends Command
{
    protected $signature = 'neev:clean-login-attempts';
    protected $description = 'Delete login attempts older than given days in config.';

    public function handle(): int
    {
        $days = (int) config('neev.login_history_retention_days');

        // A negative retention would be "older than a day from now", which
        // deletes every record — so anything below 1 disables the cleanup.
        if ($days <= 0) {
            $this->info('Login history cleanup is disabled (login_history_retention_days is not a positive number of days).');

            return self::SUCCESS;
        }

        $count = LoginAttempt::where('created_at', '<', now()->subDays($days))->delete();

        $this->info("Deleted $count login attempts record(s) older than $days days.");

        return self::SUCCESS;
    }
}
