<?php

namespace Ssntpl\Neev\Events\MagicLink;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Ssntpl\Neev\Support\MagicLink\MagicLinkResult;

/**
 * Fired after a magic link is successfully validated and consumed. The host
 * application (or Neev's controllers) completes authentication separately.
 */
class MagicLinkConsumed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public object $user,
        public MagicLinkResult $result,
    ) {
    }
}
