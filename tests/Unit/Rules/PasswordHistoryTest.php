<?php

namespace Ssntpl\Neev\Tests\Unit\Rules;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Models\Email;
use Ssntpl\Neev\Models\Password;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Rules\PasswordHistory;
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
    // Fails when password matches most recent password
    // -----------------------------------------------------------------

    public function test_fails_when_password_matches_most_recent_password(): void
    {
        $user = User::factory()->create();
        $this->setRequestUser($user);

        // UserFactory afterCreating creates a password with 'password'
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
    // Fails when password matches any of last N passwords
    // -----------------------------------------------------------------

    public function test_fails_when_password_matches_any_of_last_n_passwords(): void
    {
        $user = User::factory()->create();
        $this->setRequestUser($user);

        // Factory creates 'password' as the first password
        // Add more passwords
        Password::create(['user_id' => $user->id, 'password' => 'second-password']);
        Password::create(['user_id' => $user->id, 'password' => 'third-password']);

        $rule = PasswordHistory::notReused(5);

        // All three should fail
        $this->assertTrue($this->runRule($rule, 'password'));
        $this->assertTrue($this->runRule($rule, 'second-password'));
        $this->assertTrue($this->runRule($rule, 'third-password'));
    }

    // -----------------------------------------------------------------
    // Passes when password matches old password beyond count limit
    // -----------------------------------------------------------------

    public function test_passes_when_password_matches_old_password_beyond_count_limit(): void
    {
        $user = User::factory()->create();
        $this->setRequestUser($user);

        // Factory already created 'password' as the first one.
        // Add 3 more passwords so we have 4 total. With count=3, only last 3 are checked.
        Password::create(['user_id' => $user->id, 'password' => 'second-password']);
        Password::create(['user_id' => $user->id, 'password' => 'third-password']);
        Password::create(['user_id' => $user->id, 'password' => 'fourth-password']);

        // Rule checks only last 3 passwords (fourth, third, second)
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
        // Factory afterCreating creates a primary email

        $emailRecord = $user->email;

        // Simulate a request with an email input (no authenticated user)
        $this->app['request']->merge(['email' => $emailRecord->email]);

        $rule = PasswordHistory::notReused(5);

        // 'password' was created by the factory
        $failed = $this->runRule($rule, 'password');

        $this->assertTrue($failed);
    }

    // -----------------------------------------------------------------
    // Passes with email input when password is new
    // -----------------------------------------------------------------

    public function test_passes_via_email_input_when_password_is_new(): void
    {
        $user = User::factory()->create();
        $emailRecord = $user->email;

        $this->app['request']->merge(['email' => $emailRecord->email]);

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
