<?php

namespace Ssntpl\Neev\Events;

use Ssntpl\Neev\Models\EmailDomain;
use Ssntpl\Neev\Models\Hostname;

/**
 * An email domain or custom hostname stopped granting because its TXT record
 * has been missing for `neev.dns_verification.unverify_after_failed_days`: a
 * host no longer serves, an email domain no longer federates or enforces. It
 * is deleted (DomainRemoved) if the record is still missing at twice that.
 */
class DomainUnverified
{
    public function __construct(public EmailDomain|Hostname $domain)
    {
    }
}
