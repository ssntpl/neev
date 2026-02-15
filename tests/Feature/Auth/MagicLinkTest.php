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
            'email' => $user->email->email,
        ]);

        $response->assertOk();
        $response->assertJson([
            'status' => 'Success',
            'message' => 'Login link has been sent.',
        ]);

        Mail::assertSent(LoginUsingLink::class, function (LoginUsingLink $mail) use ($user) {
            return $mail->hasTo($user->email->email);
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
            'status' => 'Failed',
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
        $email = $user->email;

        $signedUrl = URL::temporarySignedRoute(
            'loginUsingLink',
            now()->addMinutes(60),
            ['id' => $email->id]
        );

        $response = $this->getJson($signedUrl);

        $response->assertOk();
        $response->assertJsonStructure(['status', 'token', 'email_verified']);
        $response->assertJson(['status' => 'Success']);
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_login_using_link_returns_email_verified_status(): void
    {
        $user = $this->createUser();
        $email = $user->email;

        // Email is verified by default from factory
        $signedUrl = URL::temporarySignedRoute(
            'loginUsingLink',
            now()->addMinutes(60),
            ['id' => $email->id]
        );

        $response = $this->getJson($signedUrl);

        $response->assertOk();
        $response->assertJson(['email_verified' => true]);
    }

    public function test_login_using_link_with_invalid_signature_returns_403(): void
    {
        $user = $this->createUser();
        $email = $user->email;

        // Build a URL without a valid signature
        $url = route('loginUsingLink', ['id' => $email->id]) . '?signature=invalidsignature';

        $response = $this->getJson($url);

        $response->assertStatus(403);
        $response->assertJson([
            'status' => 'Failed',
            'message' => 'Invalid or expired verification link.',
        ]);
    }

    public function test_login_using_link_with_expired_signature_returns_403(): void
    {
        $user = $this->createUser();
        $email = $user->email;

        $signedUrl = URL::temporarySignedRoute(
            'loginUsingLink',
            now()->subMinutes(1), // Already expired
            ['id' => $email->id]
        );

        $response = $this->getJson($signedUrl);

        $response->assertStatus(403);
        $response->assertJson([
            'status' => 'Failed',
            'message' => 'Invalid or expired verification link.',
        ]);
    }

    public function test_login_using_link_creates_login_attempt(): void
    {
        $user = $this->createUser();
        $email = $user->email;

        $signedUrl = URL::temporarySignedRoute(
            'loginUsingLink',
            now()->addMinutes(60),
            ['id' => $email->id]
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
        $email = $user->email;

        $signedUrl = URL::temporarySignedRoute(
            'loginUsingLink',
            now()->addMinutes(60),
            ['id' => $email->id]
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
        $email = $user->email;

        $signedUrl = URL::temporarySignedRoute(
            'loginUsingLink',
            now()->addMinutes(60),
            ['id' => $email->id]
        );

        $response = $this->getJson($signedUrl);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email']);
    }
}
