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
        return [
            'blade resend verification' => ['GET', 'email/send'],
            'blade email change' => ['PUT', 'email/change'],
            'api resend verification' => ['POST', 'neev/email/send'],
            'api email change' => ['POST', 'neev/email/change'],
            // Already limited, pinned so they stay that way.
            'blade confirmation code' => ['POST', 'account/confirmation/otp'],
            'api confirmation code' => ['POST', 'neev/confirmation/otp'],
            'api magic link' => ['POST', 'neev/sendLoginLink'],
            'api forgot password' => ['POST', 'neev/forgotPassword'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mailingRoutes')]
    public function test_the_route_carries_a_throttle(string $method, string $uri): void
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
    }
}
