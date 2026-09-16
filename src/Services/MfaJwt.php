<?php

namespace Ssntpl\Neev\Services;

use Firebase\JWT\JWT;
use Illuminate\Support\Str;
use Ssntpl\Neev\Models\User;

/**
 * The short-lived token that stands between a first factor and a second.
 *
 * It authenticates nothing on its own: `JwtLoginMiddleware` accepts it for
 * `POST {prefix}/mfa/otp/verify` and for nothing else, and that endpoint trades
 * it for a real login token once the code checks out. Every first factor that
 * parks at the challenge issues one, so an API caller and a browser coming back
 * from an OAuth provider are holding the same credential.
 */
class MfaJwt
{
    /**
     * The attempt is carried as a claim so the verify step can stamp the row
     * the first factor opened, rather than recording a second one.
     */
    public function issue(User $user, int|string|null $attemptId = null): string
    {
        $now = time();

        return JWT::encode([
            'jti' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'type' => 'mfa',
            'iat' => $now,
            'exp' => $now + $this->expiryMinutes() * 60,
            'attempt_id' => $attemptId,
        ], JwtSecret::get(), 'HS256');
    }

    public function expiryMinutes(): int
    {
        return (int) config('neev.mfa_jwt_expiry_minutes', 30);
    }
}
