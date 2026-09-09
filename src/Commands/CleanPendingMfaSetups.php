<?php

namespace Ssntpl\Neev\Commands;

use Illuminate\Console\Command;
use Ssntpl\Neev\Models\MultiFactorAuth;

class CleanPendingMfaSetups extends Command
{
    protected $signature = 'neev:clean-pending-mfa-setups';

    protected $description = 'Delete MFA setups that were started but never verified';

    public function handle(): int
    {
        $days = (int) config('neev.mfa_pending_setup_retention_days', 2);

        if ($days <= 0) {
            $this->info('Pending MFA setup cleanup is disabled (mfa_pending_setup_retention_days is not a positive number of days).');

            return self::SUCCESS;
        }

        $deleted = MultiFactorAuth::query()
            ->where('status', MultiFactorAuth::STATUS_PENDING)
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Deleted {$deleted} pending MFA setup(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
