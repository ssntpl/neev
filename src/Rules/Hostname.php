<?php

namespace Ssntpl\Neev\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Ssntpl\Neev\Models\Hostname as HostnameModel;

/**
 * A host name as a hostname or email domain row stores it: `acme.com`, `app.acme.com`.
 *
 * The value is judged in canonical form (lowercase, no surrounding dots), the
 * form the row keeps. Anything else — a URL, a path, a port, a space, a single
 * label — used to be stored as given, and handed its owner a DNS record name
 * that can never exist, so the claim could never verify.
 *
 * An IP address is refused too. No address is written `user@192.168.1.1`, and
 * no TXT record can sit under one, so it can neither federate nor verify. A
 * name whose last label is all digits is treated the same way: no top-level
 * domain is numeric.
 */
class Hostname implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $host = is_string($value) ? HostnameModel::canonicalHost($value) : '';

        if ($host === ''
            || strlen($host) > 253
            || !str_contains($host, '.')
            || preg_match('/\.\d+$/', $host)
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            $fail('The domain must be a host name.');
        }
    }
}
