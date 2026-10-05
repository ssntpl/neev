<?php

namespace Ssntpl\Neev\Events;

use Ssntpl\Neev\Models\Domain;
use Ssntpl\Neev\Models\EmailDomain;
use Ssntpl\Neev\Models\Hostname;

/**
 * Fired for an email domain or a hostname. Domain is the deprecated model
 * kept for one release (RFC 006).
 */
class DomainVerified
{
    public function __construct(public Domain|EmailDomain|Hostname $domain)
    {
    }
}
