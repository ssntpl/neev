<?php

namespace Ssntpl\Neev\Exceptions;

use Exception;

/**
 * Thrown when an owner asks to enforce an email domain another owner already
 * enforces. Verifying a domain is not exclusive, enforcing it is (RFC 006
 * §4.2): one owner at a time decides who at the domain may join.
 */
class EmailDomainEnforcedException extends Exception
{
    public function __construct()
    {
        parent::__construct('Another owner already enforces this email domain.');
    }
}
