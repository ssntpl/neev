<?php

namespace Ssntpl\Neev\Services;

class JwtSecret
{
    public static function get(): string
    {
        $secret = (string) (config('neev.jwt_secret') ?: config('app.key'));
        if ($secret === '') {
            throw new \RuntimeException('Neither neev.jwt_secret nor app.key is configured.');
        }

        if (str_starts_with($secret, 'base64:')) {
            $decoded = base64_decode(substr($secret, 7), true);
            if ($decoded !== false && $decoded !== '') {
                return $decoded;
            }
        }

        return $secret;
    }
}
