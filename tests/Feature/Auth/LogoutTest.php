<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Ssntpl\Neev\Events\LoggedOutEvent;
use Ssntpl\Neev\Models\AccessToken;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class LogoutTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    /**
     * Create a user and a login token for API authentication.
     *
     * Returns the user and the plain-text token string in the format
     * "{id}|{plaintext}" which is what the API middleware expects.
     */
    private function createAuthenticatedUser(array $userState = []): array
    {
        $user = User::factory()->create($userState);
        $newToken = $user->createLoginToken(1440);

        return [
            'user' => $user,
            'plainTextToken' => $newToken->plainTextToken,
            'accessToken' => $newToken->accessToken,
        ];
    }

    // -----------------------------------------------------------------
    // POST /neev/logout
    // -----------------------------------------------------------------

    public function test_successful_logout_deletes_access_token(): void
    {
        $data = $this->createAuthenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $data['plainTextToken'])
            ->postJson('/neev/logout');

        $response->assertOk();
        $response->assertJson([
            'status' => 'Success',
            'message' => 'Logged out successfully.',
        ]);

        $this->assertDatabaseMissing('access_tokens', [
            'id' => $data['accessToken']->id,
        ]);
    }

    public function test_logout_dispatches_logged_out_event(): void
    {
        Event::fake([LoggedOutEvent::class]);

        $data = $this->createAuthenticatedUser();

        $this->withHeader('Authorization', 'Bearer ' . $data['plainTextToken'])
            ->postJson('/neev/logout');

        Event::assertDispatched(LoggedOutEvent::class, function (LoggedOutEvent $event) use ($data) {
            return $event->user->id === $data['user']->id;
        });
    }

    public function test_logout_returns_401_without_token(): void
    {
        $response = $this->postJson('/neev/logout');

        $response->assertStatus(401);
        $response->assertJson(['message' => 'Missing token']);
    }

    public function test_logout_returns_401_with_invalid_token(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer 99999|invalidtoken')
            ->postJson('/neev/logout');

        $response->assertStatus(401);
    }

    public function test_other_tokens_remain_after_single_logout(): void
    {
        $data = $this->createAuthenticatedUser();
        $user = $data['user'];

        // Create a second login token
        $secondToken = $user->createLoginToken(1440);

        // Logout using the first token
        $this->withHeader('Authorization', 'Bearer ' . $data['plainTextToken'])
            ->postJson('/neev/logout');

        // First token should be gone
        $this->assertDatabaseMissing('access_tokens', [
            'id' => $data['accessToken']->id,
        ]);

        // Second token should still exist
        $this->assertDatabaseHas('access_tokens', [
            'id' => $secondToken->accessToken->id,
        ]);
    }

    // -----------------------------------------------------------------
    // POST /neev/logoutAll
    // -----------------------------------------------------------------

    public function test_logout_all_deletes_all_login_tokens(): void
    {
        $data = $this->createAuthenticatedUser();
        $user = $data['user'];

        // Create additional login tokens
        $user->createLoginToken(1440);
        $user->createLoginToken(1440);

        $loginTokenCount = $user->loginTokens()->count();
        $this->assertEquals(3, $loginTokenCount);

        $response = $this->withHeader('Authorization', 'Bearer ' . $data['plainTextToken'])
            ->postJson('/neev/logoutAll');

        $response->assertOk();
        $response->assertJson([
            'status' => 'Success',
            'message' => 'Logged out successfully.',
        ]);

        $this->assertEquals(0, $user->loginTokens()->count());
    }

    public function test_logout_all_dispatches_logged_out_event(): void
    {
        Event::fake([LoggedOutEvent::class]);

        $data = $this->createAuthenticatedUser();

        $this->withHeader('Authorization', 'Bearer ' . $data['plainTextToken'])
            ->postJson('/neev/logoutAll');

        Event::assertDispatched(LoggedOutEvent::class, function (LoggedOutEvent $event) use ($data) {
            return $event->user->id === $data['user']->id;
        });
    }

    public function test_logout_all_does_not_delete_api_tokens(): void
    {
        $data = $this->createAuthenticatedUser();
        $user = $data['user'];

        // Create an API token manually (avoids Permission::count() which needs acl_permissions table)
        $plainText = Str::random(40);
        $apiToken = $user->accessTokens()->create([
            'name' => 'my-api-token',
            'token' => $plainText,
            'token_type' => AccessToken::api_token,
            'permissions' => ['*'],
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $data['plainTextToken'])
            ->postJson('/neev/logoutAll');

        // API token should still exist
        $this->assertDatabaseHas('access_tokens', [
            'id' => $apiToken->id,
            'token_type' => AccessToken::api_token,
        ]);
    }

    public function test_logout_all_returns_401_without_token(): void
    {
        $response = $this->postJson('/neev/logoutAll');

        $response->assertStatus(401);
    }
}
