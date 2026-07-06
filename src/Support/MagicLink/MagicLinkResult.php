<?php

namespace Ssntpl\Neev\Support\MagicLink;

use Ssntpl\Neev\Models\MagicLinkToken;

/**
 * Immutable outcome of validating or consuming a magic link.
 *
 * Exposes the resolved status, the user (when resolvable) and channel metadata
 * so host applications can decide how to respond. Neev returns this primitive;
 * it does not render UI or perform redirects.
 */
class MagicLinkResult
{
    /** Good link — authentication may proceed. */
    public const VALID = 'valid';

    /** Token does not exist (wrong, already used/deleted, revoked, or fake). */
    public const INVALID = 'invalid';

    /** Token has passed its expiry time. */
    public const EXPIRED = 'expired';

    /** Token was opened from a different browser/device than it was issued on. */
    public const BINDING_MISMATCH = 'binding_mismatch';

    /** Token is valid but needs an explicit confirmation step before consumption. */
    public const PENDING_CONFIRMATION = 'pending_confirmation';

    /** The associated user account is inactive. */
    public const INACTIVE_USER = 'inactive_user';

    /**
     * @param  string  $status  One of the self::* status constants.
     * @param  string|null  $channel  Channel the link was issued for (e.g. "web").
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $status,
        public readonly ?object $user = null,
        public readonly ?string $channel = null,
        public readonly ?MagicLinkToken $token = null,
        public readonly array $meta = [],
    ) {
    }

    public static function valid(object $user, ?string $channel = null, ?MagicLinkToken $token = null, array $meta = []): self
    {
        return new self(self::VALID, $user, $channel, $token, $meta);
    }

    public static function failure(string $status, ?string $channel = null, ?MagicLinkToken $token = null, ?object $user = null, array $meta = []): self
    {
        return new self($status, $user, $channel, $token, $meta);
    }

    public function isValid(): bool
    {
        return $this->status === self::VALID;
    }

    public function needsConfirmation(): bool
    {
        return $this->status === self::PENDING_CONFIRMATION;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge([
            'status' => $this->status,
            'channel' => $this->channel,
        ], $this->meta);
    }
}
