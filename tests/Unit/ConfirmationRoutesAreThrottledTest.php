<?php

namespace Ssntpl\Neev\Tests\Unit;

use Illuminate\Support\Facades\Route;
use Ssntpl\Neev\Tests\TestCase;

/**
 * Every endpoint that re-checks the account's password is rate limited.
 *
 * Confirming a sensitive action exists to stop a stolen session or bearer
 * token from reaching it. Left unlimited, the same check hands that session
 * an online password oracle instead: `AuthService::confirmIdentity()` is a
 * bare `Hash::check()`, nothing counts a wrong answer, and none of it passes
 * through the login lockout. One named bucket covers them all.
 */
class ConfirmationRoutesAreThrottledTest extends TestCase
{
    public const BUCKET = 'neev-confirmation';

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function confirmationRoutes(): array
    {
        return [
            'blade add or remove a factor' => ['POST', 'account/multiFactorAuth'],
            'blade passkey enrolment' => ['POST', 'account/passkeys/register/options'],
            'blade change password' => ['POST', 'account/change-password'],
            'blade delete account' => ['DELETE', 'account/accountDelete'],
            'blade sign out other sessions' => ['POST', 'account/logoutSessions'],
            'api add a factor' => ['POST', 'neev/mfa/add'],
            'api remove a factor' => ['DELETE', 'neev/mfa/delete'],
            'api passkey enrolment' => ['POST', 'neev/passkeys/register/options'],
            'api change password' => ['PUT', 'neev/changePassword'],
            'api delete account' => ['DELETE', 'neev/users'],
            'api sign out other sessions' => ['POST', 'neev/logoutAll'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('confirmationRoutes')]
    public function test_the_route_shares_the_confirmation_bucket(string $method, string $uri): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($candidate) => $candidate->uri() === $uri
                && in_array($method, $candidate->methods(), true));

        $this->assertNotNull($route, "No {$method} {$uri} route is registered.");

        $throttles = array_values(array_filter(
            $route->gatherMiddleware(),
            fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'throttle'),
        ));

        $this->assertNotEmpty($throttles, "{$method} {$uri} checks a password and must carry a throttle.");

        // Named, so a stolen session's guesses at one action count against
        // its guesses at every other — and so the limit is not shared with
        // the mailing and code buckets a signed-in user also draws on.
        $this->assertContains(
            'throttle:5,1,' . self::BUCKET,
            $throttles,
            "{$method} {$uri} must sit in the {$method} confirmation bucket: " . implode(', ', $throttles),
        );
    }
}
