<?php

namespace Ssntpl\Neev\Events;

use Ssntpl\Neev\Models\Domain;

class DomainVerified
{
    public function __construct(public Domain $domain)
    {
    }
}
