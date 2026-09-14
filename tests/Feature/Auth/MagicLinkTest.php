<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Ssntpl\Neev\Mail\EmailOTP;
use Ssntpl\Neev\Mail\LoginUsingLink;
use Ssntpl\Neev\Models\LoginAttempt;
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

    /**
     * A deactivated account is refused before the MFA hand-off records an
     * attempt, mails a code or mints a JWT for it — the same answer it gets
     * without MFA, at the same point.
     */
    public function test_login_using_link_for_an_inactive_mfa_enrolled_user_is_refused_before_the_challenge(): void
    {
        Mail::fake();

        $user = $this->createUser(['active' => false]);
        $user->addMultiFactorAuth('email');

        $signedUrl = URL::temporarySignedRoute('loginUsingLink', now()->addMinutes(60), ['id' => $user->id]);

        $this->getJson($signedUrl)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertSame(0, $user->loginAttempts()->count());
        Mail::assertNothingSent();
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

    // -----------------------------------------------------------------
    // A magic link is a first factor, not a way around the second
    // -----------------------------------------------------------------

    /**
     * An enrolled account used to get a full login token straight from the
     * link, with `mfa_options: null`. It now stops where a password login
     * stops, and the same challenge completes it.
     */
    public function test_login_using_link_stops_at_mfa_for_an_enrolled_account(): void
    {
        Mail::fake();

        $user = $this->createUser();
        $user->addMultiFactorAuth('email');

        $signedUrl = URL::temporarySignedRoute('loginUsingLink', now()->addMinutes(60), ['id' => $user->id]);

        $step1 = $this->getJson($signedUrl);

        $step1->assertOk()
            ->assertJsonPath('auth_state', 'mfa_required')
            ->assertJsonPath('mfa_options', ['email']);
        // The short-lived MFA JWT, not an {id}|{plaintext} login token.
        $this->assertStringNotContainsString('|', $step1->json('token'));
        $this->assertSame(0, $user->loginTokens()->count());
        $this->assertDatabaseHas('login_attempts', [
            'user_id' => $user->id,
            'method' => LoginAttempt::MagicAuth,
            'multi_factor_method' => 'email',
            'is_success' => false,
        ]);
        Mail::assertSent(EmailOTP::class, fn (EmailOTP $mail) => $mail->hasTo($user->email));

        // The challenge the password flow uses completes this one too.
        $auth = $user->multiFactorAuths()->where('method', 'email')->first();
        $auth->issueOtp('123456', 10);

        $step2 = $this->withHeader('Authorization', 'Bearer ' . $step1->json('token'))
            ->postJson('/neev/mfa/otp/verify', ['auth_method' => 'email', 'otp' => '123456']);

        $step2->assertOk()->assertJsonPath('auth_state', 'authenticated');
        $this->assertStringContainsString('|', $step2->json('token'));
    }

    /**
     * The web flow signed the session in but never put the account where the
     * challenge page looks for it, so an enrolled user bounced between the
     * challenge and the login page. It now lands on the challenge, and
     * protected routes stay closed until it is answered.
     */
    public function test_web_login_link_stops_at_the_mfa_challenge_for_an_enrolled_account(): void
    {
        Mail::fake();
        $this->enableMFA();

        Route::middleware(['web', 'neev:web'])
            ->get('/mfa-protected', fn () => response('PROTECTED-PAYLOAD'));

        $user = $this->createUser();
        $user->addMultiFactorAuth('email');

        $url = URL::temporarySignedRoute('login.link', now()->addMinutes(60), ['id' => $user->id]);

        $this->get($url)->assertRedirect(route('otp.mfa.create', 'email'));
        $this->assertSame($user->email, session('email'));

        $this->get('/mfa-protected')->assertRedirect(route('otp.mfa.create', 'email'));
        $this->get(route('otp.mfa.create', 'email'))->assertOk();

        // Answering the challenge opens the gate.
        $user->multiFactorAuths()->where('method', 'email')->first()->issueOtp('123456', 10);
        $this->post('/otp/mfa', ['email' => $user->email, 'auth_method' => 'email', 'otp' => '123456'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();
        $this->get('/mfa-protected')->assertOk()->assertSee('PROTECTED-PAYLOAD');
    }

}
