<?php

namespace Ssntpl\Neev\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Ssntpl\Neev\Support\MagicLink\MagicLinkResult;

/**
 * Fired after a magic link is successfully validated and consumed. The host
 * application (or Neev's controllers) completes authentication separately.
 *
 * This carries scalars rather than the MagicLinkResult it is built from.
 * SerializesModels only rewrites properties that are themselves models, so a
 * plain result object was serialized whole — dragging the full User and
 * MagicLinkToken models into the queue payload by value, password hash and
 * stored token hash and all. Those payloads live in Redis, `jobs`, and
 * `failed_jobs`, which is retained indefinitely and routinely copied into
 * backups and staging.
 *
 * `$user` stays a model: SerializesModels reduces it to a class-and-id
 * reference, which is both smaller and safe.
 */
class MagicLinkConsumed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public object $user,
        public ?string $channel = null,
        public int|string|null $tokenId = null,
    ) {
    }

    public static function fromResult(object $user, MagicLinkResult $result): self
    {
        return new self($user, $result->channel, $result->token?->getKey());
    }
}
