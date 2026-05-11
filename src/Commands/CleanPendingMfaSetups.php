<?php

namespace Ssntpl\Neev\Commands;

use Illuminate\Console\Command;
use Ssntpl\Neev\Models\MultiFactorAuth;

class CleanPendingMfaSetups extends Command
{
    protected $signature = 'neev:clean-pending-mfa-setups';
    protected $description = 'Delete pending multi-factor auth setup rows older than the configured retention window.';

    public function handle()
    {
        $days = config('neev.mfa_pending_retention_days');
        if (!$days) {
            return;
        }

        $count = MultiFactorAuth::where('status', MultiFactorAuth::STATUS_PENDING)
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Deleted $count pending MFA setup row(s) older than $days day(s).");
    }
}
