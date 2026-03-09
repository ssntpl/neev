<?php

namespace Ssntpl\Neev\Events;

use Ssntpl\Neev\Models\Domain;

class DomainVerificationFailed
{
    public function __construct(public Domain $domain)
    {
    }
}
