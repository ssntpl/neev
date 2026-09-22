<?php

namespace Ssntpl\Neev\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Ssntpl\Neev\Models\User;
use Throwable;

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
     * Take this token for the trade about to happen, or refuse.
     *
     * Atomic, where `isSpent()` followed by `spend()` is not: the check lives
     * in `JwtLoginMiddleware` and the write in the controller, so two requests
     * arriving together both pass the check before either writes and both mint
     * a login token — one first factor, two sessions. Reordering the write
     * cannot fix that; only claiming the token in one operation can. It takes
     * a reusable second factor to reach (a TOTP inside its window, or a
     * recovery code), which is exactly the pair a step-up credential is
     * supposed to make single-use.
     *
     * `Cache::add()` also returns false when the store cannot store anything —
     * a `null` cache, which already leaves this package without its throttles,
     * its login back-off and its passkey challenges. That is told apart from a
     * genuine second trade below rather than refusing every login on such a
     * deployment.
     *
     * Release it with `release()` if the trade then fails, so a caller is not
     * left holding a spent token and an unfinished login.
     *
     * @param  array<string, mixed>  $claims
     */
    public function claim(array $claims): bool
    {
        $jti = $claims['jti'] ?? null;

        if (!is_string($jti) || $jti === '') {
            return false;
        }

        $key = $this->spentKey($jti);

        if (Cache::add($key, true, $this->remaining($claims))) {
            return true;
        }

        return !Cache::has($key);
    }

    /** Give the token back: the trade it was claimed for did not happen. */
    public function release(array $claims): void
    {
        $jti = $claims['jti'] ?? null;

        if (is_string($jti) && $jti !== '') {
            Cache::forget($this->spentKey($jti));
        }
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

        Cache::put($this->spentKey($jti), true, $this->remaining($claims));
    }

    /**
     * Seconds the token has left. The record need not outlive it: past `exp`
     * the signature is refused anyway.
     *
     * @param  array<string, mixed>  $claims
     */
    protected function remaining(array $claims): int
    {
        return max(1, (int) ($claims['exp'] ?? 0) - time());
    }

    /**
     * Spend the step-up token this request arrived with, if it has one.
     *
     * The API surface gets its claims from `JwtLoginMiddleware`; the Blade
     * challenge is session-authenticated and never decodes the token, so it
     * would otherwise complete a first factor and leave the token it was
     * issued alongside — the cookie an OAuth callback attaches on a stateful
     * origin — good for a second trade on the API endpoint.
     */
    public function spendFromRequest(Request $request): void
    {
        $claims = $request->attributes->get('jwt_claims');

        if (is_array($claims)) {
            $this->spend($claims);

            return;
        }

        $cookie = $request->cookie(config('neev.spa.cookie_name', 'neev_session'));

        if (!is_string($cookie) || $cookie === '') {
            return;
        }

        try {
            $decoded = (array) JWT::decode($cookie, new Key(JwtSecret::get(), 'HS256'));
        } catch (Throwable) {
            // Not a step-up token — a login token, or nothing we issued.
            return;
        }

        if (($decoded['type'] ?? null) === 'mfa') {
            $this->spend($decoded);
        }
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
