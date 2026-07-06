<?php

namespace Ssntpl\Neev\Events\MagicLink;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Ssntpl\Neev\Support\MagicLink\MagicLinkResult;

/**
 * Fired whenever a magic-link validation or consumption attempt fails
 * (expired, consumed/replayed, revoked, binding mismatch, invalid, ...).
 *
 * Useful for auditing and abuse detection.
 */
class MagicLinkRejected
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public MagicLinkResult $result,
    ) {
    }
}
