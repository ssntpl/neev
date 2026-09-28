<?php

namespace Ssntpl\Neev\Commands;

use Illuminate\Console\Command;
use Ssntpl\Neev\Models\AccessToken;

class CleanExpiredAccessTokens extends Command
{
    protected $signature = 'neev:clean-access-tokens';
    protected $description = 'Delete expired access tokens.';

    /**
     * `NeevAPIMiddleware` deletes an expired token only when it is presented,
     * so a token its holder never sends again stays forever. This sweeps them
     * by the same rule the middleware applies: `expires_at` in the past. A
     * token with no expiry never expires and is left alone.
     */
    public function handle(): int
    {
        $count = AccessToken::withoutTenantScope()
            ->where('expires_at', '<', now())
            ->delete();

        $this->info("Deleted {$count} expired access token(s).");

        return self::SUCCESS;
    }
}
