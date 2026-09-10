<?php

namespace Ssntpl\Neev\Exceptions;

use Exception;

/**
 * Thrown when a magic link is requested for a channel that cannot produce a
 * usable URL: either the channel is not declared under
 * `neev.magic_link.channels`, or it is a deep-link channel whose `scheme` and
 * `universal_link` are both empty.
 *
 * Both cases used to fall back to a web URL silently, so a mobile client asking
 * for `channel=mobile` received a `200` and an emailed link that opens a browser
 * instead of the app. The misconfiguration surfaced as a user-facing login
 * failure that nothing logged. Failing at send time surfaces it to the operator
 * instead — the same reasoning as MagicLinkBindingException.
 */
class MagicLinkChannelException extends Exception
{
    /**
     * @param  array<int, string>  $configured
     */
    public static function unknown(string $channel, array $configured = []): self
    {
        return new self(
            "Unknown magic-link channel [{$channel}]."
            . ($configured ? ' Configured channels: [' . implode(', ', $configured) . '].' : '')
            . ' Declare it under neev.magic_link.channels or request a configured channel.'
        );
    }

    public static function unconfiguredDeepLink(string $channel): self
    {
        return new self(
            "Magic-link channel [{$channel}] is a deep-link channel but neither "
            . "'scheme' nor 'universal_link' is set. Configure one, or the link "
            . 'would silently be mailed as a web URL that opens a browser instead '
            . 'of the app.'
        );
    }
}
