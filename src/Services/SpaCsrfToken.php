<?php

namespace Ssntpl\Neev\Services;

use Illuminate\Support\Str;

/**
 * Signed double-submit CSRF token for SPA cookie mode.
 *
 * The token is HMAC-signed to the app key rather than a bare random
 * value: plain double-submit is defeated by subdomain cookie injection
 * (an attacker-influenced sibling subdomain can plant a matching
 * cookie/header pair). An injected value the attacker cannot sign
 * fails verification. No server-side state is required.
 */
class SpaCsrfToken
{
    public function issue(): string
    {
        $value = Str::random(40);

        return $value . '.' . $this->signature($value);
    }

    /**
     * The header must echo the cookie exactly, and the token itself
     * must carry a valid signature.
     */
    public function validate(?string $cookie, ?string $header): bool
    {
        if (!$cookie || !$header || !hash_equals($cookie, $header)) {
            return false;
        }

        [$value, $signature] = array_pad(explode('.', $cookie, 2), 2, null);
        if (!$value || !$signature) {
            return false;
        }

        return hash_equals($this->signature($value), $signature);
    }

    protected function signature(string $value): string
    {
        return hash_hmac('sha256', $value, JwtSecret::get());
    }
}
