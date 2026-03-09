<?php

namespace Ssntpl\Neev\Events;

use Ssntpl\Neev\Models\Domain;

class DomainReverified
{
    public function __construct(public Domain $domain)
    {
    }
}
