<?php

namespace Ssntpl\Neev\Tests\Feature\Account;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Models\AccessToken;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class APITokenTest extends TestCase
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
    // GET /neev/apiTokens — list API tokens
    // -----------------------------------------------------------------

    public function test_list_api_tokens(): void
    {
        [$user, $token] = $this->authenticatedUser();

        // Create some API tokens for the user
        $user->createApiToken('Token A');
        $user->createApiToken('Token B');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/apiTokens');

        $response->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonCount(2, 'data');
    }

    public function test_list_api_tokens_does_not_include_login_tokens(): void
    {
        [$user, $token] = $this->authenticatedUser();

        // The login token created in authenticatedUser() should not appear
        $user->createApiToken('My API Token');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/apiTokens');

        $response->assertOk()
            ->assertJsonCount(1, 'data');

        // Verify the returned token is an API token, not the login token
        $this->assertEquals('My API Token', $response->json('data.0.name'));
    }

    public function test_list_api_tokens_returns_empty_when_none_exist(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/apiTokens');

        $response->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonCount(0, 'data');
    }

    // -----------------------------------------------------------------
    // POST /neev/apiTokens — create API token
    // -----------------------------------------------------------------

    public function test_create_new_api_token_returns_token(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/apiTokens', [
                'name' => 'My New Token',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonPath('message', 'Token has been added.')
            ->assertJsonStructure(['data']);
    }

    public function test_create_api_token_with_custom_name(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/apiTokens', [
                'name' => 'CI/CD Token',
            ]);

        $response->assertOk();

        // Verify a token with that name exists in the database
        $this->assertDatabaseHas('access_tokens', [
            'user_id' => $user->id,
            'name' => 'CI/CD Token',
            'token_type' => AccessToken::api_token,
        ]);
    }

    public function test_create_api_token_without_name_uses_default(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/apiTokens', []);

        $response->assertOk();

        $this->assertDatabaseHas('access_tokens', [
            'user_id' => $user->id,
            'name' => 'api token',
            'token_type' => AccessToken::api_token,
        ]);
    }

    // -----------------------------------------------------------------
    // PUT /neev/apiTokens — update API token
    // -----------------------------------------------------------------

    public function test_update_api_token_name(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $apiToken = $user->createApiToken('Original Name');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/apiTokens', [
                'token_id' => $apiToken->accessToken->id,
                'name' => 'Renamed Token',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonPath('data.name', 'Renamed Token');

        $this->assertDatabaseHas('access_tokens', [
            'id' => $apiToken->accessToken->id,
            'name' => 'Renamed Token',
        ]);
    }

    public function test_update_api_token_permissions_via_endpoint(): void
    {
        [$user, $token] = $this->authenticatedUser();

        // Create a token without permissions (avoids hitting acl_permissions table)
        $apiToken = $user->createApiToken('Perm Token');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/apiTokens', [
                'token_id' => $apiToken->accessToken->id,
                'permissions' => ['read', 'write', 'delete'],
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success');

        $updatedToken = AccessToken::find($apiToken->accessToken->id);
        $this->assertEquals(['read', 'write', 'delete'], $updatedToken->permissions);
    }

    public function test_update_nonexistent_api_token_returns_error(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/apiTokens', [
                'token_id' => 99999,
                'name' => 'Ghost Token',
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed');
    }

    // -----------------------------------------------------------------
    // DELETE /neev/apiTokens — delete specific API token
    // -----------------------------------------------------------------

    public function test_delete_specific_api_token(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $apiToken = $user->createApiToken('Doomed Token');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/apiTokens', [
                'token_id' => $apiToken->accessToken->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success');

        $this->assertDatabaseMissing('access_tokens', [
            'id' => $apiToken->accessToken->id,
        ]);
    }

    public function test_deleting_api_token_does_not_affect_login_token(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $apiToken = $user->createApiToken('Expendable Token');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/apiTokens', [
                'token_id' => $apiToken->accessToken->id,
            ]);

        // Login token should still work
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/apiTokens');

        $response->assertOk();
    }

    // -----------------------------------------------------------------
    // DELETE /neev/apiTokens/deleteAll — delete all API tokens
    // -----------------------------------------------------------------

    public function test_delete_all_api_tokens(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $user->createApiToken('Token 1');
        $user->createApiToken('Token 2');
        $user->createApiToken('Token 3');

        $this->assertEquals(3, $user->apiTokens()->count());

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/apiTokens/deleteAll');

        $response->assertOk()
            ->assertJsonPath('status', 'Success');

        $this->assertEquals(0, $user->apiTokens()->count());
    }

    public function test_delete_all_api_tokens_does_not_delete_login_tokens(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $user->createApiToken('Token A');
        $user->createApiToken('Token B');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/apiTokens/deleteAll');

        // Login token should still be present and functional
        $this->assertEquals(1, $user->loginTokens()->count());

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/apiTokens');

        $response->assertOk();
    }

    public function test_delete_all_when_no_api_tokens_exist(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/apiTokens/deleteAll');

        $response->assertOk()
            ->assertJsonPath('status', 'Success');
    }
}
