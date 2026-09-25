<?php

namespace Ssntpl\Neev\Tests\Unit;

use Illuminate\Support\Facades\Route;
use Ssntpl\Neev\Tests\TestCase;

/**
 * Every endpoint that sends mail on request is rate limited.
 *
 * Without a limit, one authenticated caller can empty an account's inbox
 * allowance and bury the mail that matters — and on a shared sending domain,
 * the reputation of every other tenant with it. The verification and
 * confirmation code routes were limited from the start; the four here were
 * not, which made them the cheapest way to do it.
 */
class MailingRoutesAreThrottledTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('neev.team', true);
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    public static function mailingRoutes(): array
    {
        // The third element says whether the limit must name its own bucket.
        // Signed-in routes must: an unnamed `throttle:` keys on the user
        // alone, so they would share one counter. The pre-login routes are
        // keyed by IP and share a group limit deliberately — that limit is
        // there to bound abuse of the whole sign-in surface, not to give one
        // account a mail budget.
        return [
            'blade resend verification' => ['GET', 'email/send', true],
            'blade email change' => ['PUT', 'email/change', true],
            'api resend verification' => ['POST', 'neev/email/send', true],
            'api email change' => ['POST', 'neev/email/change', true],
            // Already limited, pinned so they stay that way.
            'blade confirmation code' => ['POST', 'account/confirmation/otp', true],
            'blade recovery codes' => ['POST', 'account/recovery/codes', true],
            'api recovery codes' => ['POST', 'neev/recoveryCodes', true],
            'api confirmation code' => ['POST', 'neev/confirmation/otp', true],
            'api magic link' => ['POST', 'neev/sendLoginLink', false],
            'api forgot password' => ['POST', 'neev/forgotPassword', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mailingRoutes')]
    public function test_the_route_carries_a_throttle(string $method, string $uri, bool $ownBucket): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($candidate) => $candidate->uri() === $uri
                && in_array($method, $candidate->methods(), true));

        $this->assertNotNull($route, "No {$method} {$uri} route is registered.");

        $throttles = array_filter(
            $route->gatherMiddleware(),
            fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'throttle'),
        );

        $this->assertNotEmpty(
            $throttles,
            "{$method} {$uri} sends mail on request and must carry a throttle.",
        );

        if (!$ownBucket) {
            return;
        }

        // Named, because an unnamed `throttle:` keys on the signed-in user
        // alone: every one of these would share a single counter, so five
        // resends would lock the user out of entering the code that arrived.
        foreach ($throttles as $throttle) {
            $this->assertSame(
                3,
                count(explode(',', explode(':', $throttle, 2)[1] ?? '')),
                "{$method} {$uri} must name its own throttle bucket: {$throttle}",
            );
        }
    }

    /**
     * Two routes that mail different things must not share a counter.
     */
    public function test_resending_a_verification_mail_does_not_spend_the_confirmation_budget(): void
    {
        $buckets = [];

        foreach (self::mailingRoutes() as $label => [$method, $uri, $ownBucket]) {
            $route = collect(Route::getRoutes()->getRoutes())
                ->first(fn ($candidate) => $candidate->uri() === $uri
                    && in_array($method, $candidate->methods(), true));

            $throttle = collect($route->gatherMiddleware())
                ->first(fn ($m) => is_string($m) && str_starts_with($m, 'throttle'));

            $buckets[$label] = explode(',', $throttle)[2] ?? null;
        }

        $this->assertNotSame($buckets['blade resend verification'], $buckets['blade confirmation code']);
        $this->assertNotSame($buckets['api resend verification'], $buckets['api confirmation code']);
        $this->assertNotSame($buckets['blade email change'], $buckets['blade resend verification']);
    }
}
