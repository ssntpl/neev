<?php

namespace Ssntpl\Neev\Exceptions;

use Exception;

/**
 * Thrown when a magic link is requested for a user whose email is unverified
 * while `magic_link.allow_unverified_users` is off.
 *
 * The alternative is worse than an exception: the link would be mailed, and
 * redemption would then reject it as "invalid or expired" every time, leaving
 * the user in a dead end with no way out.
 */
class MagicLinkUnverifiedException extends Exception
{
    public static function forEmail(?string $email = null): self
    {
        return new self(
            'Cannot issue a magic link to the unverified email address'
            . ($email ? " [{$email}]" : '')
            . '. Have the user verify their email first, or enable neev.magic_link.allow_unverified_users '
            . 'to let redeeming the link verify it.'
        );
    }
}
