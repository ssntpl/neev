<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Ssntpl\Neev\Mail\LoginUsingLink;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\MagicLink\MagicLinkManager;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class MagicLinkTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    private function createUser(array $state = []): User
    {
        return User::factory()->create($state);
    }

    /**
     * Generate a magic link for the user and return its plain token.
     */
    private function magicLinkToken(User $user): array
    {
        return app(MagicLinkManager::class)->forWeb($user);
    }

    // -----------------------------------------------------------------
    // POST /neev/sendLoginLink
    // -----------------------------------------------------------------

    public function test_send_login_link_dispatches_mail_and_returns_success(): void
    {
        Mail::fake();

        $user = $this->createUser();

        $response = $this->postJson('/neev/sendLoginLink', [
            'email' => $user->email,
        ]);

        $response->assertOk();
        $response->assertJson([
            'message' => 'Login link has been sent.',
        ]);

        Mail::assertSent(LoginUsingLink::class, function (LoginUsingLink $mail) use ($user) {
            return $mail->hasTo($user->email);
        });
    }

    public function test_send_login_link_returns_401_for_non_existent_email(): void
    {
        Mail::fake();

        $response = $this->postJson('/neev/sendLoginLink', [
            'email' => 'nobody@example.com',
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'message' => 'Credentials are wrong.',
        ]);

        Mail::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // GET /neev/loginUsingLink
    // -----------------------------------------------------------------

    public function test_login_using_link_with_valid_token_returns_token(): void
    {
        $user = $this->createUser();

        $link = $this->magicLinkToken($user);

        $response = $this->getJson('/neev/loginUsingLink?token=' . $link['token']);

        $response->assertOk();
        $response->assertJson([
            'auth_state' => 'authenticated',
            'expires_in' => config('neev.login_token_expiry_minutes', 1440),
            'email_verified' => true,
        ]);
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_login_using_link_returns_email_verified_status(): void
    {
        $user = $this->createUser();

        // Email is verified by default from factory
        $link = $this->magicLinkToken($user);

        $response = $this->getJson('/neev/loginUsingLink?token=' . $link['token']);

        $response->assertOk();
        $response->assertJson([
            'auth_state' => 'authenticated',
            'email_verified' => true,
        ]);
    }

    public function test_login_using_link_with_invalid_token_returns_403(): void
    {
        $this->createUser();

        $response = $this->getJson('/neev/loginUsingLink?token=not-a-real-token');

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'Invalid or expired verification link.',
        ]);
    }

    public function test_login_using_link_with_expired_token_returns_403(): void
    {
        $user = $this->createUser();

        $link = $this->magicLinkToken($user);
        $link['model']->forceFill(['expires_at' => now()->subMinute()])->save();

        $response = $this->getJson('/neev/loginUsingLink?token=' . $link['token']);

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'Invalid or expired verification link.',
        ]);
    }

    public function test_login_using_link_is_single_use(): void
    {
        $user = $this->createUser();

        $link = $this->magicLinkToken($user);

        $this->getJson('/neev/loginUsingLink?token=' . $link['token'])->assertOk();

        // Replay: the token row was deleted on consumption.
        $this->getJson('/neev/loginUsingLink?token=' . $link['token'])->assertStatus(403);
    }

    public function test_generating_a_new_link_invalidates_the_previous_one(): void
    {
        $user = $this->createUser();

        $old = $this->magicLinkToken($user);
        $new = $this->magicLinkToken($user);

        $this->getJson('/neev/loginUsingLink?token=' . $old['token'])->assertStatus(403);
        $this->getJson('/neev/loginUsingLink?token=' . $new['token'])->assertOk();
    }

    public function test_login_using_link_creates_login_attempt(): void
    {
        $user = $this->createUser();

        $link = $this->magicLinkToken($user);

        $this->getJson('/neev/loginUsingLink?token=' . $link['token']);

        $this->assertDatabaseHas('login_attempts', [
            'user_id' => $user->id,
            'method' => 'magic auth',
            'is_success' => true,
        ]);
    }

    public function test_login_using_link_creates_access_token(): void
    {
        $user = $this->createUser();

        $link = $this->magicLinkToken($user);

        $this->getJson('/neev/loginUsingLink?token=' . $link['token']);

        $this->assertDatabaseHas('access_tokens', [
            'user_id' => $user->id,
            'token_type' => 'login',
        ]);
    }

    public function test_login_using_link_for_inactive_user_returns_validation_error(): void
    {
        $user = $this->createUser(['active' => false]);

        $link = $this->magicLinkToken($user);

        $response = $this->getJson('/neev/loginUsingLink?token=' . $link['token']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email']);
    }
}
