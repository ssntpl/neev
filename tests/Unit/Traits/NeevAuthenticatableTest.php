<?php

namespace Ssntpl\Neev\Tests\Unit\Traits;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;

class NeevAuthenticatableTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // hasVerifiedEmail()
    // -----------------------------------------------------------------

    public function test_has_verified_email_returns_true_when_email_is_verified(): void
    {
        // User::factory() creates a user with email_verified_at = now()
        $user = User::factory()->create();

        $this->assertTrue($user->hasVerifiedEmail());
    }

    public function test_has_verified_email_returns_false_when_email_is_not_verified(): void
    {
        $user = User::factory()->unverified()->create();

        $this->assertFalse($user->hasVerifiedEmail());
    }

    public function test_has_verified_email_returns_false_when_email_verified_at_set_to_null(): void
    {
        $user = User::factory()->create();

        // Set to unverified after creation
        $user->forceFill(['email_verified_at' => null])->save();
        $user->refresh();

        $this->assertFalse($user->hasVerifiedEmail());
    }

    // -----------------------------------------------------------------
    // markEmailAsVerified()
    // -----------------------------------------------------------------

    public function test_mark_email_as_verified_sets_email_verified_at(): void
    {
        $user = User::factory()->unverified()->create();

        $this->assertNull($user->email_verified_at);

        $result = $user->markEmailAsVerified();

        $this->assertTrue($result);
        $this->assertNotNull($user->email_verified_at);

        // Verify persisted
        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_mark_email_as_verified_on_already_verified_user(): void
    {
        $user = User::factory()->create();

        $this->assertNotNull($user->email_verified_at);

        $result = $user->markEmailAsVerified();

        $this->assertTrue($result);
        $this->assertNotNull($user->email_verified_at);
    }
}
