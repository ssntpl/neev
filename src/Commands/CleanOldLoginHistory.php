<?php

namespace Ssntpl\Neev\Commands;

use Illuminate\Console\Command;
use Ssntpl\Neev\Models\LoginHistory;
use Illuminate\Support\Carbon;

class CleanOldLoginHistory extends Command
{
    protected $signature = 'neev:clean-login-history';
    protected $description = 'Delete login history older than given days in config.';

    public function handle()
    {
        if (config('neev.last_login_history_in_days')) {
            $count = LoginHistory::where('created_at', '<', Carbon::now()->subDays(config('neev.last_login_history_in_days')))->delete();
    
            $this->info("Deleted $count login history record(s) older than ".config('neev.last_login_history_in_days')." days.");
        }
    }
}
