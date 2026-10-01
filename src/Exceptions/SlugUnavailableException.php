<?php

namespace Ssntpl\Neev\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a team or tenant is saved with a slug another owner holds, or
 * one another owner has retired. A retired slug is never issued again (RFC 006
 * §6 Q1), so the second case is permanent.
 */
class SlugUnavailableException extends InvalidArgumentException
{
    public function __construct(string $slug)
    {
        parent::__construct("The slug \"{$slug}\" is not available.");
    }
}
