<?php

namespace Ssntpl\Neev\Tests\Feature\Account;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;

class SessionRevokeTest extends TestCase
{
    use RefreshDatabase;

    private function createAuthenticatedUser(): array
    {
        $user = User::factory()->create();
        $newToken = $user->createLoginToken(config('neev.login_token_expiry_minutes', 1440));

        return [
            'user' => $user,
            'plainTextToken' => $newToken->plainTextToken,
            'accessToken' => $newToken->accessToken,
        ];
    }

    public function test_user_can_revoke_another_session(): void
    {
        $data = $this->createAuthenticatedUser();
        $otherSession = $data['user']->createLoginToken(1440)->accessToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $data['plainTextToken'])
            ->deleteJson('/neev/sessions/' . $otherSession->id);

        $response->assertOk();
        $response->assertJson(['message' => 'Session has been revoked.']);
        $this->assertDatabaseMissing('access_tokens', ['id' => $otherSession->id]);
    }

    public function test_user_cannot_revoke_current_session(): void
    {
        $data = $this->createAuthenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $data['plainTextToken'])
            ->deleteJson('/neev/sessions/' . $data['accessToken']->id);

        $response->assertStatus(400);
        $this->assertDatabaseHas('access_tokens', ['id' => $data['accessToken']->id]);
    }

    public function test_user_cannot_revoke_another_users_session(): void
    {
        $data = $this->createAuthenticatedUser();
        $otherUser = User::factory()->create();
        $foreignSession = $otherUser->createLoginToken(1440)->accessToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $data['plainTextToken'])
            ->deleteJson('/neev/sessions/' . $foreignSession->id);

        $response->assertNotFound();
        $this->assertDatabaseHas('access_tokens', ['id' => $foreignSession->id]);
    }

    public function test_revoking_unknown_session_returns_404(): void
    {
        $data = $this->createAuthenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $data['plainTextToken'])
            ->deleteJson('/neev/sessions/999999');

        $response->assertNotFound();
    }

    public function test_revoked_session_token_no_longer_authenticates(): void
    {
        $data = $this->createAuthenticatedUser();
        $other = $data['user']->createLoginToken(1440);

        $this->withHeader('Authorization', 'Bearer ' . $data['plainTextToken'])
            ->deleteJson('/neev/sessions/' . $other->accessToken->id)
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer ' . $other->plainTextToken)
            ->getJson('/neev/users')
            ->assertUnauthorized();
    }
}
