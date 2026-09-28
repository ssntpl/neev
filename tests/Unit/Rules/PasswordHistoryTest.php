<?php

namespace Ssntpl\Neev\Tests\Unit\Rules;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Rules\PasswordHistory;
use Ssntpl\Neev\Support\PasswordSubject;
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
    // Passes when no user is found — no history exists to reuse.
    // First-time registration lands here; failing would block every
    // registration under the default password rules.
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
    // The body's email names nobody: only a proven account is compared
    // -----------------------------------------------------------------

    /**
     * Read from the request body, this rule was a password oracle: anyone
     * could post a victim's address with a guess and learn from the error
     * whether it was that account's password.
     */
    public function test_ignores_an_email_in_the_request_body(): void
    {
        $user = User::factory()->create();

        // No authenticated user, no proven subject — only an address in the body.
        $this->app['request']->merge(['email' => $user->email]);

        $rule = PasswordHistory::notReused(5);

        // 'password' was set by the factory
        $this->assertFalse($this->runRule($rule, 'password'));
    }

    public function test_ignores_an_id_in_the_request_body(): void
    {
        $user = User::factory()->create();

        $this->app['request']->merge(['id' => $user->id]);

        $rule = PasswordHistory::notReused(5);

        $this->assertFalse($this->runRule($rule, 'password'));
    }

    public function test_the_body_email_cannot_redirect_the_check_away_from_the_signed_in_user(): void
    {
        $user = User::factory()->create(['password' => 'mine-123']);
        $victim = User::factory()->create(['password' => 'theirs-456']);

        $this->setRequestUser($user);
        $this->app['request']->merge(['email' => $victim->email]);

        $rule = PasswordHistory::notReused(5);

        $this->assertTrue($this->runRule($rule, 'mine-123'), 'the signed-in user is still compared');
        $this->assertFalse($this->runRule($rule, 'theirs-456'), "another account's password is never compared");
    }

    // -----------------------------------------------------------------
    // A proven subject (a reset path) is compared without a sign-in
    // -----------------------------------------------------------------

    public function test_compares_against_the_subject_a_controller_has_proven(): void
    {
        $user = User::factory()->create();

        PasswordSubject::set($this->app['request'], $user);

        $rule = PasswordHistory::notReused(5);

        $this->assertTrue($this->runRule($rule, 'password'));
        $this->assertFalse($this->runRule($rule, 'brand-new-unique-password'));
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
