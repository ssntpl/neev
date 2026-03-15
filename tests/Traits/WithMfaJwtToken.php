<?php

namespace Ssntpl\Neev\Tests\Traits;

use Firebase\JWT\JWT;
use Ssntpl\Neev\Services\JwtSecret;

trait WithMfaJwtToken
{
    private function createMfaJwtToken(int $userId, ?int $attemptId = null): string
    {
        $now = time();
        $expirySeconds = (int) config('neev.mfa_jwt_expiry_minutes', 30) * 60;
        $payload = [
            'user_id' => $userId,
            'type' => 'mfa',
            'iat' => $now,
            'exp' => $now + $expirySeconds,
        ];

        if ($attemptId !== null) {
            $payload['attempt_id'] = $attemptId;
        }

        return JWT::encode($payload, JwtSecret::get(), 'HS256');
    }
}
