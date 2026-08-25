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
    // -----------------------------------------------------------------
    // A magic link proves inbox control
    // -----------------------------------------------------------------

    /**
     * The link was mailed to the address and came back signed, which proves
     * the same thing our verification mail proves — so following it verifies
     * the address rather than being turned away for lacking verification.
     */
    public function test_login_using_link_verifies_a_previously_unverified_email(): void
    {
        $user = User::factory()->unverified()->create();

        $signedUrl = URL::temporarySignedRoute(
            'loginUsingLink',
            now()->addMinutes(60),
            ['id' => $user->id]
        );

        $this->getJson('/neev/loginUsingLink?' . parse_url($signedUrl, PHP_URL_QUERY))
            ->assertOk()
            ->assertJsonPath('email_verified', true);

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_web_login_link_verifies_a_previously_unverified_email(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute(
            'login.link',
            now()->addMinutes(60),
            ['id' => $user->id]
        );

        $this->get($url)->assertRedirect(config('neev.home'));

        $this->assertNotNull($user->refresh()->email_verified_at);
        $this->assertAuthenticatedAs($user);
    }

    /**
     * Sending the link is what lets an unverified account prove ownership,
     * so it must not itself require a verified address.
     */
    public function test_send_login_link_is_available_to_an_unverified_email(): void
    {
        Mail::fake();

        $user = User::factory()->unverified()->create();

        $this->postJson('/neev/sendLoginLink', ['email' => $user->email])
            ->assertOk()
            ->assertJson(['message' => 'Login link has been sent.']);

        Mail::assertSent(LoginUsingLink::class, fn (LoginUsingLink $mail) => $mail->hasTo($user->email));
    }

    public function test_web_send_login_link_is_available_to_an_unverified_email(): void
    {
        Mail::fake();

        $user = User::factory()->unverified()->create();

        $this->post('/login/link', ['email' => $user->email])
            ->assertRedirect()
            ->assertSessionHas('status', 'Login link has been sent.');

        Mail::assertSent(LoginUsingLink::class, fn (LoginUsingLink $mail) => $mail->hasTo($user->email));
    }

    /** The magic-link session is a full login, not one parked at the notice. */
    public function test_web_login_link_reaches_home_and_not_the_verification_notice(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute(
            'login.link',
            now()->addMinutes(60),
            ['id' => $user->id]
        );

        $this->get($url)->assertRedirect(config('neev.home'));

        // The now-verified session passes the email-verification gate.
        $this->get(route('verification.notice'))->assertRedirect(config('neev.home'));
    }

}
