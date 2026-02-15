<?php

namespace Ssntpl\Neev\Tests\Feature\Account;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Database\Factories\LoginAttemptFactory;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class SessionsTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    protected function authenticatedUser(): array
    {
        $user = User::factory()->create();
        $token = $user->createLoginToken(60);

        return [$user, $token->plainTextToken];
    }

    // -----------------------------------------------------------------
    // GET /neev/sessions — active sessions
    // -----------------------------------------------------------------

    public function test_get_sessions_returns_active_sessions(): void
    {
        [$user, $token] = $this->authenticatedUser();

        // The createLoginToken call already created a session
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/sessions');

        $response->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonStructure(['data']);

        // Should have at least 1 session (the current login token)
        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
    }

    // -----------------------------------------------------------------
    // GET /neev/loginAttempts — login history
    // -----------------------------------------------------------------

    public function test_get_login_attempts_returns_history(): void
    {
        [$user, $token] = $this->authenticatedUser();

        // Create some login attempts
        LoginAttemptFactory::new()->create(['user_id' => $user->id]);
        LoginAttemptFactory::new()->failed()->create(['user_id' => $user->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/loginAttempts');

        $response->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonStructure(['data']);

        $this->assertGreaterThanOrEqual(2, count($response->json('data')));
    }

    public function test_get_login_attempts_returns_empty_when_none(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/loginAttempts');

        $response->assertOk()
            ->assertJsonPath('status', 'Success');
    }

    // -----------------------------------------------------------------
    // DELETE /neev/users — delete account
    // -----------------------------------------------------------------

    public function test_delete_user_with_correct_password(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/users', [
                'password' => 'password',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonPath('message', 'Account has been deleted.');

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_delete_user_rejects_wrong_password(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/users', [
                'password' => 'wrong-password',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('status', 'Failed')
            ->assertJsonPath('message', 'Password is Wrong.');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_delete_user_requires_password(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/users', []);

        $response->assertStatus(422);
    }
}
