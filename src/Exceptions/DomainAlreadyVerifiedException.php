<?php

namespace Ssntpl\Neev\Exceptions;

use Exception;

/**
 * Was thrown by Domain::verify() when another owner of the same kind had
 * already verified the host. Nothing throws it since `domains` became
 * read-only (RFC 006): a host taken by another owner throws
 * HostnameTakenException when it is claimed.
 *
 * @deprecated Removed with Domain in the next release.
 */
class DomainAlreadyVerifiedException extends Exception
{
    public function __construct(string $ownerType = 'team')
    {
        parent::__construct("This domain is already verified by another {$ownerType}.");
    }
}
