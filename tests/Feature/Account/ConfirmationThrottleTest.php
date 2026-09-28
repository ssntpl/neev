<?php

namespace Ssntpl\Neev\Tests\Feature\Account;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Database\Factories\MultiFactorAuthFactory;
use Ssntpl\Neev\Models\LoginAttempt;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

/**
 * A stolen session gets five guesses a minute at the password, across every
 * action that asks for it — not an unlimited run at one of them.
 */
class ConfirmationThrottleTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    private const PASSWORD = 'Password123!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableMFA();
    }

    private function enrolledUser(): User
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'password' => bcrypt(self::PASSWORD)]);
        MultiFactorAuthFactory::new()->create(['user_id' => $user->id, 'method' => 'authenticator', 'preferred' => true]);

        return $user;
    }

    public function test_the_api_locks_the_sixth_guess_within_a_minute(): void
    {
        $user = $this->enrolledUser();
        $token = $user->createLoginToken(1440)->plainTextToken;

        for ($i = 1; $i <= 5; $i++) {
            $this->withHeader('Authorization', 'Bearer ' . $token)
                ->deleteJson('/neev/mfa/delete', ['auth_method' => 'authenticator', 'password' => "wrong-{$i}"])
                ->assertForbidden();
        }

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/mfa/delete', ['auth_method' => 'authenticator', 'password' => self::PASSWORD])
            ->assertStatus(429);

        $this->assertCount(1, $user->fresh()->activeMultiFactorAuths, 'the factor survives the locked-out right answer');
    }

    /** One bucket: guesses spent on one action count against every other. */
    public function test_guesses_at_one_action_spend_the_budget_of_the_others(): void
    {
        $user = $this->enrolledUser();
        $token = $user->createLoginToken(1440)->plainTextToken;

        for ($i = 1; $i <= 5; $i++) {
            $this->withHeader('Authorization', 'Bearer ' . $token)
                ->putJson('/neev/changePassword', [
                    'current_password' => "wrong-{$i}",
                    'password' => 'New-Password-1!',
                    'password_confirmation' => 'New-Password-1!',
                ])->assertForbidden();
        }

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/users', ['password' => 'wrong-6'])
            ->assertStatus(429);
    }

    public function test_the_blade_kit_locks_the_sixth_guess_within_a_minute(): void
    {
        $user = $this->enrolledUser();
        $attempt = $user->loginAttempts()->create([
            'method' => LoginAttempt::Password,
            'multi_factor_method' => 'authenticator',
            'is_success' => true,
        ]);
        $this->actingAs($user);
        session(['attempt_id' => $attempt->id]);

        for ($i = 1; $i <= 5; $i++) {
            $this->post(route('multi.auth'), ['auth_method' => 'authenticator', 'action' => 'delete', 'password' => "wrong-{$i}"])
                ->assertSessionHasErrors('password');
        }

        $this->post(route('multi.auth'), ['auth_method' => 'authenticator', 'action' => 'delete', 'password' => self::PASSWORD])
            ->assertStatus(429);

        $this->assertCount(1, $user->fresh()->activeMultiFactorAuths);
    }

    /** The bucket is per account, so one account's lockout is not another's. */
    public function test_the_bucket_is_per_account(): void
    {
        $locked = $this->enrolledUser();
        $lockedToken = $locked->createLoginToken(1440)->plainTextToken;
        for ($i = 1; $i <= 5; $i++) {
            $this->withHeader('Authorization', 'Bearer ' . $lockedToken)
                ->deleteJson('/neev/mfa/delete', ['auth_method' => 'authenticator', 'password' => "wrong-{$i}"]);
        }

        $other = $this->enrolledUser();
        $this->withHeader('Authorization', 'Bearer ' . $other->createLoginToken(1440)->plainTextToken)
            ->deleteJson('/neev/mfa/delete', ['auth_method' => 'authenticator', 'password' => self::PASSWORD])
            ->assertOk();
    }
}
