<?php

namespace Ssntpl\Neev\Commands;

use Illuminate\Console\Command;
use Ssntpl\Neev\Models\LoginAttempt;

class CleanOldLoginAttempts extends Command
{
    protected $signature = 'neev:clean-login-attempts';
    protected $description = 'Delete login attempts older than given days in config.';

    public function handle()
    {
        if (config('neev.login_history_retention_days')) {
            $count = LoginAttempt::where('created_at', '<', now()->subDays(config('neev.login_history_retention_days')))->delete();

            $this->info("Deleted $count login attempts record(s) older than ".config('neev.login_history_retention_days')." days.");
        }
    }
}
