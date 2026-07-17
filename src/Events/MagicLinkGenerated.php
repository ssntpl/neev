<?php

namespace Ssntpl\Neev\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Ssntpl\Neev\Models\MagicLinkToken;

/**
 * Fired after a magic link is successfully generated (before delivery).
 */
class MagicLinkGenerated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public object $user,
        public MagicLinkToken $token,
    ) {
    }
}
