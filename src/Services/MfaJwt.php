<?php

namespace Ssntpl\Neev\Services;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
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
 *
 * It is spent by the trade. Its `jti` was recorded and never read, so the same
 * JWT could be traded again for as long as it lived — a second login token
 * from one first factor, each time with a fresh second factor, which is
 * exactly what a step-up credential must not allow. A failed code does not
 * spend it: the user has to be able to try again.
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

    /**
     * Spend this token, so it cannot be traded a second time.
     *
     * The record lives exactly as long as the token would have: once `exp`
     * has passed the signature is refused anyway, so there is nothing left to
     * remember.
     *
     * @param  array<string, mixed>  $claims
     */
    public function spend(array $claims): void
    {
        $jti = $claims['jti'] ?? null;

        if (!is_string($jti) || $jti === '') {
            return;
        }

        $seconds = max(1, (int) ($claims['exp'] ?? 0) - time());

        Cache::put($this->spentKey($jti), true, $seconds);
    }

    /** @param array<string, mixed> $claims */
    public function isSpent(array $claims): bool
    {
        $jti = $claims['jti'] ?? null;

        // A token we issued always carries one. Without it there is nothing to
        // record as spent, so it could be replayed for its whole life.
        if (!is_string($jti) || $jti === '') {
            return true;
        }

        return Cache::has($this->spentKey($jti));
    }

    protected function spentKey(string $jti): string
    {
        return 'neev:mfa-jwt:spent:' . $jti;
    }
}
