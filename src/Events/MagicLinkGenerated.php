<?php

namespace Ssntpl\Neev\Events;

use DateTimeInterface;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Ssntpl\Neev\Models\MagicLinkToken;

/**
 * Fired after a magic link is successfully generated (before delivery).
 *
 * The token is described by scalars rather than carried as a model. A queued
 * listener would otherwise restore the `MagicLinkToken` property through
 * SerializesModels, which re-queries the row with firstOrFail() — and the row is
 * deleted the moment the link is redeemed. A user who clicks before the queue
 * drains would leave the listener throwing ModelNotFoundException: an audit
 * trail that silently loses exactly the records generated under load.
 *
 * `$user` stays a model on purpose. SerializesModels reduces it to a class-and-id
 * reference, so the queue payload never contains the user's attributes — its
 * password hash included.
 */
class MagicLinkGenerated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public object $user,
        public int|string $tokenId,
        public string $channel,
        public DateTimeInterface $expiresAt,
        public ?string $createdIp = null,
        public ?string $userAgent = null,
    ) {
    }

    public static function fromToken(object $user, MagicLinkToken $token): self
    {
        return new self(
            $user,
            $token->getKey(),
            $token->channel,
            $token->expires_at,
            $token->created_ip,
            $token->user_agent,
        );
    }
}
