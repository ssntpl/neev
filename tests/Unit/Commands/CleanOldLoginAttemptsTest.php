<?php

namespace Ssntpl\Neev\Tests\Unit\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Database\Factories\LoginAttemptFactory;
use Ssntpl\Neev\Models\LoginAttempt;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;

class CleanOldLoginAttemptsTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Deletes login attempts older than configured days
    // -----------------------------------------------------------------

    public function test_deletes_login_attempts_older_than_configured_days(): void
    {
        config(['neev.last_login_attempts_in_days' => 30]);

        $user = User::factory()->create();

        // Create an old login attempt (40 days ago)
        $old = LoginAttemptFactory::new()->create([
            'user_id' => $user->id,
            'created_at' => now()->subDays(40),
        ]);

        // Create a recent login attempt (10 days ago)
        $recent = LoginAttemptFactory::new()->create([
            'user_id' => $user->id,
            'created_at' => now()->subDays(10),
        ]);

        $this->artisan('neev:clean-login-attempts')
            ->assertSuccessful();

        $this->assertDatabaseMissing('login_attempts', ['id' => $old->id]);
        $this->assertDatabaseHas('login_attempts', ['id' => $recent->id]);
    }

    // -----------------------------------------------------------------
    // Keeps recent login attempts
    // -----------------------------------------------------------------

    public function test_keeps_recent_login_attempts(): void
    {
        config(['neev.last_login_attempts_in_days' => 30]);

        $user = User::factory()->create();

        LoginAttemptFactory::new()->create([
            'user_id' => $user->id,
            'created_at' => now()->subDays(5),
        ]);

        LoginAttemptFactory::new()->create([
            'user_id' => $user->id,
            'created_at' => now()->subDays(29),
        ]);

        $this->artisan('neev:clean-login-attempts')
            ->assertSuccessful();

        $this->assertSame(2, LoginAttempt::count());
    }

    // -----------------------------------------------------------------
    // Outputs info message with count
    // -----------------------------------------------------------------

    public function test_outputs_info_message_with_count(): void
    {
        config(['neev.last_login_attempts_in_days' => 30]);

        $user = User::factory()->create();

        LoginAttemptFactory::new()->create([
            'user_id' => $user->id,
            'created_at' => now()->subDays(40),
        ]);

        LoginAttemptFactory::new()->create([
            'user_id' => $user->id,
            'created_at' => now()->subDays(50),
        ]);

        $this->artisan('neev:clean-login-attempts')
            ->expectsOutputToContain('Deleted 2 login attempts record(s) older than 30 days.')
            ->assertSuccessful();
    }

    // -----------------------------------------------------------------
    // Does nothing when config not set
    // -----------------------------------------------------------------

    public function test_does_nothing_when_config_not_set(): void
    {
        config(['neev.last_login_attempts_in_days' => null]);

        $user = User::factory()->create();

        LoginAttemptFactory::new()->create([
            'user_id' => $user->id,
            'created_at' => now()->subDays(100),
        ]);

        $this->artisan('neev:clean-login-attempts')
            ->assertSuccessful();

        // Record should still exist
        $this->assertSame(1, LoginAttempt::count());
    }

    // -----------------------------------------------------------------
    // Does nothing when config is zero (falsy)
    // -----------------------------------------------------------------

    public function test_does_nothing_when_config_is_zero(): void
    {
        config(['neev.last_login_attempts_in_days' => 0]);

        $user = User::factory()->create();

        LoginAttemptFactory::new()->create([
            'user_id' => $user->id,
            'created_at' => now()->subDays(100),
        ]);

        $this->artisan('neev:clean-login-attempts')
            ->assertSuccessful();

        // Record should still exist because config is falsy
        $this->assertSame(1, LoginAttempt::count());
    }

    // -----------------------------------------------------------------
    // Deletes only old attempts across multiple users
    // -----------------------------------------------------------------

    public function test_deletes_old_attempts_across_multiple_users(): void
    {
        config(['neev.last_login_attempts_in_days' => 30]);

        $userA = User::factory()->create();
        $userB = User::factory()->create();

        // Old attempts for both users
        LoginAttemptFactory::new()->create([
            'user_id' => $userA->id,
            'created_at' => now()->subDays(35),
        ]);
        LoginAttemptFactory::new()->create([
            'user_id' => $userB->id,
            'created_at' => now()->subDays(45),
        ]);

        // Recent attempts for both users
        LoginAttemptFactory::new()->create([
            'user_id' => $userA->id,
            'created_at' => now()->subDays(5),
        ]);
        LoginAttemptFactory::new()->create([
            'user_id' => $userB->id,
            'created_at' => now()->subDays(10),
        ]);

        $this->artisan('neev:clean-login-attempts')
            ->assertSuccessful();

        $this->assertSame(2, LoginAttempt::count());
    }

    // -----------------------------------------------------------------
    // Outputs correct count of zero when no old records
    // -----------------------------------------------------------------

    public function test_outputs_zero_count_when_no_old_records(): void
    {
        config(['neev.last_login_attempts_in_days' => 30]);

        $user = User::factory()->create();

        LoginAttemptFactory::new()->create([
            'user_id' => $user->id,
            'created_at' => now(),
        ]);

        $this->artisan('neev:clean-login-attempts')
            ->expectsOutputToContain('Deleted 0 login attempts record(s)')
            ->assertSuccessful();
    }
}
