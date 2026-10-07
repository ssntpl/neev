<?php

namespace Ssntpl\Neev\Events;

use Ssntpl\Neev\Models\EmailDomain;

/**
 * An email domain was verified with `enforce` on while another owner's
 * verified row already enforced the domain, so it was saved with `enforce`
 * turned off: only the first owner to verify and enforce a domain keeps
 * enforcing it. Fires wherever the row is verified, including the daily
 * re-check, so the app can tell the owner nobody was watching.
 */
class EmailDomainEnforceDropped
{
    public function __construct(public EmailDomain $domain)
    {
    }
}
