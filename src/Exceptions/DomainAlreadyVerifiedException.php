<?php

namespace Ssntpl\Neev\Exceptions;

use Exception;

/**
 * Thrown by Domain::verify() when another owner of the same kind has already
 * verified the host. The first owner to prove a host gets it; a later claim
 * cannot, whatever its DNS record says.
 */
class DomainAlreadyVerifiedException extends Exception
{
    public function __construct(string $ownerType = 'team')
    {
        parent::__construct("This domain is already verified by another {$ownerType}.");
    }
}
