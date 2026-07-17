<?php

namespace Ssntpl\Neev\Commands;

use Illuminate\Console\Command;
use Ssntpl\Neev\Models\MagicLinkToken;

class CleanExpiredMagicLinks extends Command
{
    protected $signature = 'neev:clean-magic-links';
    protected $description = 'Delete expired magic-link tokens.';

    public function handle(): int
    {
        $count = MagicLinkToken::withoutTenantScope()
            ->where('expires_at', '<', now())
            ->delete();

        $this->info("Deleted {$count} expired magic-link token(s).");

        return self::SUCCESS;
    }
}
