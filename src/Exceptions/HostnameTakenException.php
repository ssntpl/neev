<?php

namespace Ssntpl\Neev\Exceptions;

use Exception;

/**
 * Thrown when a host is claimed while another owner holds it. A host is unique
 * across every owner (RFC 006 §2.1); the holder releases it to free it.
 *
 * The message is shown to the claimant, who may be in another tenant from the
 * holder, so it does not say that anyone holds the host.
 */
class HostnameTakenException extends Exception
{
    public function __construct()
    {
        parent::__construct('This host cannot be added.');
    }
}
