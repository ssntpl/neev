<?php

namespace Ssntpl\Neev\Tests\Traits;

use Firebase\JWT\JWT;
use Illuminate\Support\Str;
use Ssntpl\Neev\Services\JwtSecret;

trait WithMfaJwtToken
{
    private function createMfaJwtToken(int $userId, ?int $attemptId = null): string
    {
        $now = time();
        $expirySeconds = (int) config('neev.mfa_jwt_expiry_minutes', 30) * 60;
        $payload = [
            // MfaJwt::issue() always carries one, and a token without it
            // cannot be recorded as spent — so it is refused rather than
            // being replayable for its whole life.
            'jti' => Str::uuid()->toString(),
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
