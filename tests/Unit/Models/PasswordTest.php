<?php

namespace Ssntpl\Neev\Tests\Unit\Models;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Ssntpl\Neev\Models\Password;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;

class PasswordTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Password hashing
    // -----------------------------------------------------------------

    public function test_password_is_hashed_in_database(): void
    {
        $user = User::factory()->create();
        $plainPassword = 'my-secret-password';

        $password = Password::create([
            'user_id' => $user->id,
            'password' => $plainPassword,
        ]);

        $rawValue = DB::table('passwords')
            ->where('id', $password->id)
            ->value('password');

        $this->assertNotSame($plainPassword, $rawValue);
        $this->assertTrue(Hash::check($plainPassword, $rawValue));
    }

    // -----------------------------------------------------------------
    // UPDATED_AT constant
    // -----------------------------------------------------------------

    public function test_updated_at_is_null(): void
    {
        $this->assertNull(Password::UPDATED_AT);
    }

    public function test_password_record_has_no_updated_at_column(): void
    {
        $user = User::factory()->create();

        $password = Password::create([
            'user_id' => $user->id,
            'password' => 'test-password',
        ]);

        // The record should be created without errors despite no updated_at
        $this->assertNotNull($password->id);
        $this->assertNotNull($password->created_at);
    }

    // -----------------------------------------------------------------
    // checkPasswordWarning()
    // -----------------------------------------------------------------

    public function test_check_password_warning_returns_message_with_diff_for_humans_when_soft_expiry_passed(): void
    {
        config(['neev.password_soft_expiry_days' => 30]);

        $user = User::factory()->create();

        // Set the password created_at to 31 days ago (past soft expiry)
        $password = $user->passwords()->first();
        DB::table('passwords')
            ->where('id', $password->id)
            ->update(['created_at' => now()->subDays(31)]);

        $result = Password::checkPasswordWarning($user);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('message', $result);
        $this->assertStringContainsString('Please change the password', $result['message']);
        $this->assertStringContainsString('ago', $result['message']);
    }

    public function test_check_password_warning_returns_message_even_when_not_expired(): void
    {
        config(['neev.password_soft_expiry_days' => 30]);

        $user = User::factory()->create();

        // Password was just created (within soft expiry window)
        $result = Password::checkPasswordWarning($user);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('message', $result);
        $this->assertStringContainsString('You have changed your password', $result['message']);
    }

    public function test_check_password_warning_returns_false_for_user_with_no_password(): void
    {
        $user = User::factory()->create();

        // Remove all passwords
        DB::table('passwords')->where('user_id', $user->id)->delete();

        $result = Password::checkPasswordWarning($user);

        $this->assertFalse($result);
    }

    public function test_check_password_warning_returns_false_for_null_user(): void
    {
        $result = Password::checkPasswordWarning(null);

        $this->assertFalse($result);
    }

    // -----------------------------------------------------------------
    // isLoginBlock()
    // -----------------------------------------------------------------

    public function test_is_login_block_returns_true_when_hard_expiry_passed(): void
    {
        config(['neev.password_hard_expiry_days' => 90]);

        $user = User::factory()->create();

        // Set password created_at to 91 days ago
        $password = $user->passwords()->first();
        DB::table('passwords')
            ->where('id', $password->id)
            ->update(['created_at' => now()->subDays(91)]);

        $result = Password::isLoginBlock($user);

        $this->assertTrue($result);
    }

    public function test_is_login_block_returns_false_when_within_limit(): void
    {
        config(['neev.password_hard_expiry_days' => 90]);

        $user = User::factory()->create();

        // Password was just created (within hard expiry window)
        $result = Password::isLoginBlock($user);

        $this->assertFalse($result);
    }

    public function test_is_login_block_returns_false_when_hard_expiry_disabled(): void
    {
        config(['neev.password_hard_expiry_days' => 0]);

        $user = User::factory()->create();

        // Even with old password, disabled expiry should not block
        $password = $user->passwords()->first();
        DB::table('passwords')
            ->where('id', $password->id)
            ->update(['created_at' => now()->subDays(365)]);

        $result = Password::isLoginBlock($user);

        $this->assertFalse($result);
    }

    public function test_is_login_block_returns_false_for_user_with_no_password(): void
    {
        $user = User::factory()->create();

        DB::table('passwords')->where('user_id', $user->id)->delete();

        $result = Password::isLoginBlock($user);

        $this->assertFalse($result);
    }

    public function test_is_login_block_returns_false_for_null_user(): void
    {
        $result = Password::isLoginBlock(null);

        $this->assertFalse($result);
    }

    // -----------------------------------------------------------------
    // latestForUser() — tested via checkPasswordWarning / isLoginBlock
    // -----------------------------------------------------------------

    public function test_latest_for_user_returns_most_recent_password(): void
    {
        config(['neev.password_hard_expiry_days' => 90]);

        $user = User::factory()->create();

        // The factory already created one password. Create a second one.
        Password::create([
            'user_id' => $user->id,
            'password' => 'newer-password',
        ]);

        // The latest password was just created, so it should NOT block login
        $result = Password::isLoginBlock($user);

        $this->assertFalse($result);
    }

    public function test_latest_for_user_ignores_old_passwords(): void
    {
        config(['neev.password_hard_expiry_days' => 90]);

        $user = User::factory()->create();

        // Make the factory-created password very old
        $oldPassword = $user->passwords()->first();
        DB::table('passwords')
            ->where('id', $oldPassword->id)
            ->update(['created_at' => now()->subDays(365)]);

        // Create a new, recent password
        Password::create([
            'user_id' => $user->id,
            'password' => 'new-password',
        ]);

        // Should NOT block because the latest password is recent
        $result = Password::isLoginBlock($user);

        $this->assertFalse($result);
    }
}
