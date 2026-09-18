<?php

namespace Ssntpl\Neev\Exceptions;

use Exception;

/**
 * Thrown when an account has been issued too many magic links in a short
 * window.
 *
 * Every issuance invalidates the account's previous link for that channel, so
 * unlimited issuance lets anyone who knows an address keep its owner's link
 * permanently dead and the inbox flooded. The refusal happens before anything
 * is invalidated, so the link the account already has survives.
 */
class MagicLinkThrottledException extends Exception
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Too many login links have been requested for this account. Please wait before requesting another.');
    }
}
