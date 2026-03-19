<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Ssntpl\Neev\Mail\LoginUsingLink;
use Ssntpl\Neev\Models\User;
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

    public function test_login_using_link_with_valid_signature_returns_token(): void
    {
        $user = $this->createUser();

        $signedUrl = URL::temporarySignedRoute(
            'loginUsingLink',
            now()->addMinutes(60),
            ['id' => $user->id]
        );

        $response = $this->getJson($signedUrl);

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
        $signedUrl = URL::temporarySignedRoute(
            'loginUsingLink',
            now()->addMinutes(60),
            ['id' => $user->id]
        );

        $response = $this->getJson($signedUrl);

        $response->assertOk();
        $response->assertJson([
            'auth_state' => 'authenticated',
            'email_verified' => true,
        ]);
    }

    public function test_login_using_link_with_invalid_signature_returns_403(): void
    {
        $user = $this->createUser();

        // Build a URL without a valid signature
        $url = route('loginUsingLink', ['id' => $user->id]) . '?signature=invalidsignature';

        $response = $this->getJson($url);

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'Invalid or expired verification link.',
        ]);
    }

    public function test_login_using_link_with_expired_signature_returns_403(): void
    {
        $user = $this->createUser();

        $signedUrl = URL::temporarySignedRoute(
            'loginUsingLink',
            now()->subMinutes(1), // Already expired
            ['id' => $user->id]
        );

        $response = $this->getJson($signedUrl);

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'Invalid or expired verification link.',
        ]);
    }

    public function test_login_using_link_creates_login_attempt(): void
    {
        $user = $this->createUser();

        $signedUrl = URL::temporarySignedRoute(
            'loginUsingLink',
            now()->addMinutes(60),
            ['id' => $user->id]
        );

        $this->getJson($signedUrl);

        $this->assertDatabaseHas('login_attempts', [
            'user_id' => $user->id,
            'method' => 'magic auth',
            'is_success' => true,
        ]);
    }

    public function test_login_using_link_creates_access_token(): void
    {
        $user = $this->createUser();

        $signedUrl = URL::temporarySignedRoute(
            'loginUsingLink',
            now()->addMinutes(60),
            ['id' => $user->id]
        );

        $this->getJson($signedUrl);

        $this->assertDatabaseHas('access_tokens', [
            'user_id' => $user->id,
            'token_type' => 'login',
        ]);
    }

    public function test_login_using_link_for_inactive_user_returns_validation_error(): void
    {
        $user = $this->createUser(['active' => false]);

        $signedUrl = URL::temporarySignedRoute(
            'loginUsingLink',
            now()->addMinutes(60),
            ['id' => $user->id]
        );

        $response = $this->getJson($signedUrl);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email']);
    }
}
