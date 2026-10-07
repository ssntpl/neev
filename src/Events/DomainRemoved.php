<?php

namespace Ssntpl\Neev\Events;

use Ssntpl\Neev\Models\EmailDomain;
use Ssntpl\Neev\Models\Hostname;

/**
 * An email domain or custom hostname was deleted because its TXT record stayed
 * missing for twice `neev.dns_verification.unverify_after_failed_days`. The
 * model is the deleted row; its owner still exists.
 */
class DomainRemoved
{
    public function __construct(public EmailDomain|Hostname $domain)
    {
    }
}
