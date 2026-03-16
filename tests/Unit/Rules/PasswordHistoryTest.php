<?php

namespace Ssntpl\Neev\Tests\Unit\Rules;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Rules\PasswordHistory;
use Ssntpl\Neev\Services\AuthService;
use Ssntpl\Neev\Tests\TestCase;

class PasswordHistoryTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Helper to run the rule and return whether it failed
    // -----------------------------------------------------------------

    protected function runRule(PasswordHistory $rule, string $value): bool
    {
        $failed = false;
        $rule->validate('password', $value, function () use (&$failed) {
            $failed = true;
        });

        return $failed;
    }

    /**
     * Set the authenticated user on the request so request()->user() works.
     */
    protected function setRequestUser($user): void
    {
        $this->app['request']->setUserResolver(function () use ($user) {
            return $user;
        });
    }

    // -----------------------------------------------------------------
    // notReused() factory method
    // -----------------------------------------------------------------

    public function test_not_reused_creates_instance_with_default_count(): void
    {
        $rule = PasswordHistory::notReused();

        $this->assertInstanceOf(PasswordHistory::class, $rule);

        $reflection = new \ReflectionClass($rule);
        $property = $reflection->getProperty('count');
        $this->assertSame(5, $property->getValue($rule));
    }

    public function test_not_reused_creates_instance_with_custom_count(): void
    {
        $rule = PasswordHistory::notReused(3);

        $reflection = new \ReflectionClass($rule);
        $property = $reflection->getProperty('count');
        $this->assertSame(3, $property->getValue($rule));
    }

    // -----------------------------------------------------------------
    // Passes when no user is found (rule skips)
    // -----------------------------------------------------------------

    public function test_passes_when_no_user_found(): void
    {
        // No authenticated user, no email input
        $rule = PasswordHistory::notReused(5);

        $failed = $this->runRule($rule, 'anything');

        $this->assertFalse($failed);
    }

    // -----------------------------------------------------------------
    // Fails when password matches current password
    // -----------------------------------------------------------------

    public function test_fails_when_password_matches_current_password(): void
    {
        $user = User::factory()->create();
        $this->setRequestUser($user);

        // UserFactory creates user with 'password' as current password
        $rule = PasswordHistory::notReused(5);

        $failed = $this->runRule($rule, 'password');

        $this->assertTrue($failed);
    }

    // -----------------------------------------------------------------
    // Passes when password is new (not in history)
    // -----------------------------------------------------------------

    public function test_passes_when_password_is_new(): void
    {
        $user = User::factory()->create();
        $this->setRequestUser($user);

        $rule = PasswordHistory::notReused(5);

        $failed = $this->runRule($rule, 'completely-new-password-123');

        $this->assertFalse($failed);
    }

    // -----------------------------------------------------------------
    // Fails when password matches any of the password history entries
    // -----------------------------------------------------------------

    public function test_fails_when_password_matches_password_history(): void
    {
        $user = User::factory()->create();
        $this->setRequestUser($user);

        $authService = new AuthService();

        // Change password a few times to build history
        $authService->changePassword($user, 'second-password');
        $authService->changePassword($user, 'third-password');
        $user->refresh();

        $rule = PasswordHistory::notReused(5);

        // Current password (third-password) should fail
        $this->assertTrue($this->runRule($rule, 'third-password'));
        // Historical passwords should also fail
        $this->assertTrue($this->runRule($rule, 'second-password'));
        $this->assertTrue($this->runRule($rule, 'password'));
    }

    // -----------------------------------------------------------------
    // Passes when password matches old password beyond count limit
    // -----------------------------------------------------------------

    public function test_passes_when_password_matches_old_password_beyond_count_limit(): void
    {
        $user = User::factory()->create();
        $this->setRequestUser($user);

        $authService = new AuthService();

        // Build history: password -> second -> third -> fourth
        $authService->changePassword($user, 'second-password');
        $authService->changePassword($user, 'third-password');
        $authService->changePassword($user, 'fourth-password');
        $user->refresh();

        // Rule checks current password + last 2 from history = 3 total
        $rule = PasswordHistory::notReused(3);

        // 'password' (the first one) is beyond the count limit of 3
        $failed = $this->runRule($rule, 'password');

        $this->assertFalse($failed);
    }

    // -----------------------------------------------------------------
    // Works when user found via email input
    // -----------------------------------------------------------------

    public function test_works_when_user_found_via_email_input(): void
    {
        $user = User::factory()->create();

        // Simulate a request with an email input (no authenticated user)
        $this->app['request']->merge(['email' => $user->email]);

        $rule = PasswordHistory::notReused(5);

        // 'password' was set by the factory
        $failed = $this->runRule($rule, 'password');

        $this->assertTrue($failed);
    }

    // -----------------------------------------------------------------
    // Passes with email input when password is new
    // -----------------------------------------------------------------

    public function test_passes_via_email_input_when_password_is_new(): void
    {
        $user = User::factory()->create();

        $this->app['request']->merge(['email' => $user->email]);

        $rule = PasswordHistory::notReused(5);

        $failed = $this->runRule($rule, 'brand-new-unique-password');

        $this->assertFalse($failed);
    }

    // -----------------------------------------------------------------
    // Failure message includes count
    // -----------------------------------------------------------------

    public function test_failure_message_includes_count(): void
    {
        $user = User::factory()->create();
        $this->setRequestUser($user);

        $rule = PasswordHistory::notReused(3);
        $message = null;
        $rule->validate('password', 'password', function ($msg) use (&$message) {
            $message = $msg;
        });

        $this->assertStringContainsString('3', $message);
    }
}
