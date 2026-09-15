<?php

namespace Ssntpl\Neev\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Ssntpl\Neev\Support\MagicLink\MagicLinkResult;

/**
 * Fired whenever a magic-link validation or consumption attempt fails
 * (expired, consumed/replayed, revoked, binding mismatch, invalid, ...).
 *
 * Useful for auditing and abuse detection.
 *
 * Carries scalars for the same reason as MagicLinkConsumed — and more urgently:
 * this event fires on BINDING_MISMATCH *without* deleting the row, so a payload
 * built from the raw result held the stored hash of a still-live token next to
 * the account's password hash.
 *
 * `$status` is one of the MagicLinkResult::* constants. `$user` is null whenever
 * the attempt could not be tied to an account (an unknown or malformed token).
 */
class MagicLinkRejected
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public string $status,
        public ?string $channel = null,
        public ?object $user = null,
        public int|string|null $tokenId = null,
    ) {
    }

    public static function fromResult(MagicLinkResult $result): self
    {
        return new self(
            $result->status,
            $result->channel,
            $result->user,
            $result->token?->getKey(),
        );
    }
}
