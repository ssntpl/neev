<?php

namespace Ssntpl\Neev\Tests\Unit\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Models\Password;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Rules\PasswordHistory;
use Ssntpl\Neev\Tests\TestCase;

class CleanOldPasswordsTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Helper: set up password rules config with PasswordHistory rule
    // -----------------------------------------------------------------

    protected function configurePasswordHistoryCount(int $count): void
    {
        config([
            'neev.password' => [
                'required',
                'confirmed',
                PasswordHistory::notReused($count),
            ],
        ]);
    }

    // -----------------------------------------------------------------
    // Keeps the configured number of passwords per user
    // -----------------------------------------------------------------

    public function test_keeps_configured_number_of_passwords_per_user(): void
    {
        $this->configurePasswordHistoryCount(3);

        $user = User::factory()->create();
        // Factory creates 1 password ('password')

        // Add more passwords (total: 5)
        Password::create(['user_id' => $user->id, 'password' => 'second-pass']);
        Password::create(['user_id' => $user->id, 'password' => 'third-pass']);
        Password::create(['user_id' => $user->id, 'password' => 'fourth-pass']);
        Password::create(['user_id' => $user->id, 'password' => 'fifth-pass']);

        $this->assertSame(5, Password::where('user_id', $user->id)->count());

        $this->artisan('neev:clean-passwords')
            ->assertSuccessful();

        // Should keep only last 3
        $this->assertSame(3, Password::where('user_id', $user->id)->count());
    }

    // -----------------------------------------------------------------
    // Deletes excess old passwords
    // -----------------------------------------------------------------

    public function test_deletes_excess_old_passwords(): void
    {
        $this->configurePasswordHistoryCount(2);

        $user = User::factory()->create();
        // Factory creates 1 password

        // Add 3 more (total: 4)
        $second = Password::create(['user_id' => $user->id, 'password' => 'second']);
        $third = Password::create(['user_id' => $user->id, 'password' => 'third']);
        $fourth = Password::create(['user_id' => $user->id, 'password' => 'fourth']);

        $this->artisan('neev:clean-passwords')
            ->assertSuccessful();

        // Only the last 2 (third, fourth) should remain
        $remaining = Password::where('user_id', $user->id)->orderByDesc('id')->pluck('id')->toArray();
        $this->assertCount(2, $remaining);
        $this->assertContains($fourth->id, $remaining);
        $this->assertContains($third->id, $remaining);
    }

    // -----------------------------------------------------------------
    // Works across multiple users
    // -----------------------------------------------------------------

    public function test_works_across_multiple_users(): void
    {
        $this->configurePasswordHistoryCount(2);

        $userA = User::factory()->create();
        $userB = User::factory()->create();

        // UserA: factory creates 1, add 2 more (total 3)
        Password::create(['user_id' => $userA->id, 'password' => 'a-second']);
        Password::create(['user_id' => $userA->id, 'password' => 'a-third']);

        // UserB: factory creates 1, add 3 more (total 4)
        Password::create(['user_id' => $userB->id, 'password' => 'b-second']);
        Password::create(['user_id' => $userB->id, 'password' => 'b-third']);
        Password::create(['user_id' => $userB->id, 'password' => 'b-fourth']);

        $this->artisan('neev:clean-passwords')
            ->assertSuccessful();

        // Each user should have exactly 2 passwords
        $this->assertSame(2, Password::where('user_id', $userA->id)->count());
        $this->assertSame(2, Password::where('user_id', $userB->id)->count());
    }

    // -----------------------------------------------------------------
    // Outputs completion message
    // -----------------------------------------------------------------

    public function test_outputs_completion_message(): void
    {
        $this->configurePasswordHistoryCount(5);

        $this->artisan('neev:clean-passwords')
            ->expectsOutputToContain('Password cleanup completed.')
            ->assertSuccessful();
    }

    // -----------------------------------------------------------------
    // Does not delete when user has fewer than configured count
    // -----------------------------------------------------------------

    public function test_does_not_delete_when_user_has_fewer_than_configured_count(): void
    {
        $this->configurePasswordHistoryCount(5);

        $user = User::factory()->create();
        // Factory creates 1 password

        Password::create(['user_id' => $user->id, 'password' => 'second-pass']);

        $this->artisan('neev:clean-passwords')
            ->assertSuccessful();

        // Both should remain (2 < 5)
        $this->assertSame(2, Password::where('user_id', $user->id)->count());
    }

    // -----------------------------------------------------------------
    // Uses default count of 5 when PasswordHistory rule not in config
    // -----------------------------------------------------------------

    public function test_uses_default_count_when_password_history_rule_not_in_config(): void
    {
        // Config with no PasswordHistory rule object
        config([
            'neev.password' => [
                'required',
                'confirmed',
            ],
        ]);

        $user = User::factory()->create();
        // Factory creates 1 password. Add 6 more (total: 7)
        for ($i = 1; $i <= 6; $i++) {
            Password::create(['user_id' => $user->id, 'password' => "pass-{$i}"]);
        }

        $this->artisan('neev:clean-passwords')
            ->assertSuccessful();

        // Default is 5, so should keep last 5
        $this->assertSame(5, Password::where('user_id', $user->id)->count());
    }

    // -----------------------------------------------------------------
    // Preserves the most recent passwords (order check)
    // -----------------------------------------------------------------

    public function test_preserves_the_most_recent_passwords(): void
    {
        $this->configurePasswordHistoryCount(2);

        $user = User::factory()->create();
        // Factory creates 1 password (id = lowest)

        $second = Password::create(['user_id' => $user->id, 'password' => 'second']);
        $third = Password::create(['user_id' => $user->id, 'password' => 'third']);

        $this->artisan('neev:clean-passwords')
            ->assertSuccessful();

        $remainingIds = Password::where('user_id', $user->id)->orderByDesc('id')->pluck('id')->toArray();

        // The last 2 by id should be kept (third, second)
        $this->assertContains($third->id, $remainingIds);
        $this->assertContains($second->id, $remainingIds);
        $this->assertCount(2, $remainingIds);
    }
}
