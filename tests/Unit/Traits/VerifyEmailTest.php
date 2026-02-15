<?php

namespace Ssntpl\Neev\Tests\Unit\Traits;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Database\Factories\EmailFactory;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;

class VerifyEmailTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // hasVerifiedEmail() - primary email
    // -----------------------------------------------------------------

    public function test_has_verified_email_returns_true_when_primary_email_is_verified(): void
    {
        // User::factory() afterCreating creates a verified primary email
        $user = User::factory()->create();

        $this->assertTrue($user->hasVerifiedEmail());
    }

    public function test_has_verified_email_returns_false_when_primary_email_is_not_verified(): void
    {
        $user = User::factory()->create();

        // Update the primary email to be unverified
        $user->email()->update(['verified_at' => null]);
        $user->load('email');

        $this->assertFalse($user->hasVerifiedEmail());
    }

    public function test_has_verified_email_returns_false_when_user_has_no_primary_email(): void
    {
        $user = User::factory()->create();

        // Delete all emails so there's no primary
        $user->emails()->delete();
        $user->load('email');

        $this->assertFalse($user->hasVerifiedEmail());
    }

    // -----------------------------------------------------------------
    // hasVerifiedEmail($email) - specific email
    // -----------------------------------------------------------------

    public function test_has_verified_email_with_specific_email_returns_true_when_verified(): void
    {
        $user = User::factory()->create();

        EmailFactory::new()->create([
            'user_id' => $user->id,
            'email' => 'secondary@example.com',
            'is_primary' => false,
            'verified_at' => now(),
        ]);

        $this->assertTrue($user->hasVerifiedEmail('secondary@example.com'));
    }

    public function test_has_verified_email_with_specific_email_returns_false_when_not_verified(): void
    {
        $user = User::factory()->create();

        EmailFactory::new()->unverified()->create([
            'user_id' => $user->id,
            'email' => 'unverified@example.com',
            'is_primary' => false,
        ]);

        $this->assertFalse($user->hasVerifiedEmail('unverified@example.com'));
    }

    public function test_has_verified_email_with_nonexistent_email_returns_false(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->hasVerifiedEmail('nonexistent@example.com'));
    }

    public function test_has_verified_email_checks_specific_email_not_primary(): void
    {
        $user = User::factory()->create();

        // Primary email is verified (from factory), but we check a different email
        EmailFactory::new()->unverified()->create([
            'user_id' => $user->id,
            'email' => 'other@example.com',
            'is_primary' => false,
        ]);

        // Primary is verified
        $this->assertTrue($user->hasVerifiedEmail());

        // But the specific other email is not
        $this->assertFalse($user->hasVerifiedEmail('other@example.com'));
    }

    public function test_has_verified_email_with_specific_email_works_for_other_users_email(): void
    {
        // The method queries Email model globally, not scoped to user
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        EmailFactory::new()->create([
            'user_id' => $userB->id,
            'email' => 'bob@example.com',
            'is_primary' => false,
            'verified_at' => now(),
        ]);

        // userA checking an email that belongs to userB
        // Based on the implementation, it just checks if the email exists and is verified
        $this->assertTrue($userA->hasVerifiedEmail('bob@example.com'));
    }
}
