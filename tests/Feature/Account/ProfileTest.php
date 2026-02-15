<?php

namespace Ssntpl\Neev\Tests\Feature\Account;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class ProfileTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    /**
     * Create an authenticated user with a login token.
     *
     * @return array{0: \Ssntpl\Neev\Models\User, 1: string}
     */
    protected function authenticatedUser(): array
    {
        $user = User::factory()->create();
        $token = $user->createLoginToken(60);

        return [$user, $token->plainTextToken];
    }

    // -----------------------------------------------------------------
    // GET /neev/users — get current user profile
    // -----------------------------------------------------------------

    public function test_get_user_returns_profile_with_name_and_email(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/users');

        $response->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', $user->name);

        // Emails should be loaded
        $this->assertArrayHasKey('emails', $response->json('data'));
    }

    public function test_get_user_includes_teams_relation(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/users');

        $response->assertOk();
        $this->assertArrayHasKey('teams', $response->json('data'));
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/neev/users');

        $response->assertStatus(401);
    }

    // -----------------------------------------------------------------
    // PUT /neev/users — update profile
    // -----------------------------------------------------------------

    public function test_update_user_name(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/users', [
                'name' => 'Updated Name',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonPath('data.name', 'Updated Name');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Name',
        ]);
    }

    public function test_update_user_name_persists_in_database(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/users', [
                'name' => 'Persisted Name',
            ]);

        $this->assertEquals('Persisted Name', $user->fresh()->name);
    }

    public function test_update_user_with_username(): void
    {
        $this->enableUsernameSupport();

        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/users', [
                'username' => 'newusername',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success');

        $this->assertEquals('newusername', $user->fresh()->username);
    }

    public function test_update_user_with_duplicate_username_returns_error(): void
    {
        $this->enableUsernameSupport();

        [$user, $token] = $this->authenticatedUser();
        $otherUser = User::factory()->create(['username' => 'taken']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/users', [
                'username' => 'taken',
            ]);

        $response->assertStatus(500)
            ->assertJsonPath('status', 'Failed');
    }

    public function test_update_user_name_and_username_simultaneously(): void
    {
        $this->enableUsernameSupport();

        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/users', [
                'name' => 'New Full Name',
                'username' => 'newhandle',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonPath('data.name', 'New Full Name');

        $user->refresh();
        $this->assertEquals('newhandle', $user->username);
    }
}
