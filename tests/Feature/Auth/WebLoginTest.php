<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Ssntpl\Neev\Models\MultiFactorAuth;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class WebLoginTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('neev.home', '/dashboard');
    }

    // -----------------------------------------------------------------
    // PUT /login — check email / show password form
    // -----------------------------------------------------------------

    public function test_login_password_with_valid_email_returns_ok(): void
    {
        $user = User::factory()->create();

        $response = $this->put('/login', [
            'email' => $user->email,
        ]);

        // Should return the login-password view (200)
        $response->assertOk();
    }

    public function test_login_password_with_invalid_email_returns_error(): void
    {
        $response = $this->put('/login', [
            'email' => 'nonexistent@example.com',
        ]);

        // checkEmail returns null, controller throws ValidationException
        $response->assertStatus(302);
    }

    public function test_login_password_without_email_returns_validation_error(): void
    {
        $response = $this->put('/login', []);

        // LoginRequest rules require email
        $response->assertStatus(302);
    }

    // -----------------------------------------------------------------
    // POST /login — authenticate with password
    // -----------------------------------------------------------------

    public function test_web_login_with_valid_credentials_redirects(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect('/dashboard');
    }

    public function test_web_login_with_wrong_email_returns_error(): void
    {
        $response = $this->post('/login', [
            'email' => 'ghost@example.com',
            'password' => 'password',
        ]);

        $response->assertRedirect();
    }

    public function test_web_login_redirects_to_email_verification_when_required(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['email_verified_at' => null])->save();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('verification.notice'));
    }

    /**
     * Verification is checked before the intended destination is honoured, so
     * an unverified account cannot use `redirect` to skip past the notice.
     */
    public function test_verification_notice_wins_over_an_intended_redirect(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['email_verified_at' => null])->save();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'redirect' => '/settings',
        ])->assertRedirect(route('verification.notice'));
    }

    public function test_a_verified_user_is_sent_to_the_intended_redirect(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'redirect' => '/settings',
        ])->assertRedirect('/settings');
    }

    /** An off-site `redirect` is ignored in favour of the configured home. */
    public function test_an_absolute_redirect_is_not_followed(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'redirect' => 'https://evil.example.com/steal',
        ])->assertRedirect(config('neev.home'));
    }

    /**
     * A protocol-relative URL starts with a slash but is off-site: the browser
     * reads `//evil.example.com` as `https://evil.example.com`. The "starts
     * with /" check on its own would wave it through.
     *
     * @return array<string, array{string}>
     */
    public static function offSiteRedirects(): array
    {
        return [
            'protocol-relative' => ['//evil.example.com/steal'],
            'protocol-relative with path' => ['//evil.example.com'],
            // Browsers normalise a backslash to a forward slash in the
            // authority, so this is the same trick with a different byte.
            'backslash authority' => ['/\\evil.example.com'],
            'absolute http' => ['http://evil.example.com'],
            'scheme relative to root' => ['/'],
            'empty' => [''],
        ];
    }

    #[DataProvider('offSiteRedirects')]
    public function test_an_unsafe_redirect_falls_back_to_home(string $redirect): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'redirect' => $redirect,
        ])->assertRedirect(config('neev.home'));
    }

    /** A non-string `redirect` is discarded rather than crashing the flow. */
    public function test_an_array_redirect_falls_back_to_home(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'redirect' => ['/settings'],
        ])->assertRedirect(config('neev.home'));
    }

    // -----------------------------------------------------------------
    // The intended destination survives an MFA challenge
    // -----------------------------------------------------------------

    /**
     * Give the user one active email factor holding a known code, so the
     * password step hands off to the MFA challenge.
     */
    private function userWithEmailMfa(string $otp = '654321'): User
    {
        $user = User::factory()->create();
        $user->multiFactorAuths()->create([
            'method' => 'email',
            'status' => MultiFactorAuth::STATUS_ACTIVE,
            'preferred' => true,
            'otp' => $otp,
            'expires_at' => now()->addMinutes(15),
        ]);

        return $user;
    }

    /**
     * The password step redirects to the challenge rather than the intended
     * page, so the destination has to be parked until the second factor is
     * satisfied — otherwise MFA users always land on home.
     */
    public function test_the_intended_redirect_survives_an_mfa_challenge(): void
    {
        $this->enableMFA();
        $user = $this->userWithEmailMfa();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'redirect' => '/settings',
        ])->assertRedirect(route('otp.mfa.create', 'email'));

        $this->assertSame('/settings', session('mfa_redirect'));

        $this->post(route('otp.mfa.store'), [
            'email' => $user->email,
            'auth_method' => 'email',
            'otp' => '654321',
        ])->assertRedirect('/settings');
    }

    /** The parked destination is consumed, so a later login does not reuse it. */
    public function test_the_parked_redirect_is_cleared_once_used(): void
    {
        $this->enableMFA();
        $user = $this->userWithEmailMfa();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'redirect' => '/settings',
        ]);

        $this->post(route('otp.mfa.store'), [
            'email' => $user->email,
            'auth_method' => 'email',
            'otp' => '654321',
        ]);

        $this->assertNull(session('mfa_redirect'));
    }

    /** An unsafe destination is never parked, so it cannot be reached later. */
    public function test_an_unsafe_redirect_is_not_parked_across_the_challenge(): void
    {
        $this->enableMFA();
        $user = $this->userWithEmailMfa();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'redirect' => '//evil.example.com',
        ])->assertRedirect(route('otp.mfa.create', 'email'));

        $this->assertNull(session('mfa_redirect'));

        $this->post(route('otp.mfa.store'), [
            'email' => $user->email,
            'auth_method' => 'email',
            'otp' => '654321',
        ])->assertRedirect(config('neev.home'));
    }

    /**
     * A destination left over from an earlier login attempt that carried no
     * `redirect` must not be honoured — the second login clears it.
     */
    public function test_a_second_login_without_a_redirect_forgets_the_earlier_one(): void
    {
        $this->enableMFA();
        $user = $this->userWithEmailMfa();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'redirect' => '/settings',
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertNull(session('mfa_redirect'));

        $this->post(route('otp.mfa.store'), [
            'email' => $user->email,
            'auth_method' => 'email',
            'otp' => '654321',
        ])->assertRedirect(config('neev.home'));
    }

    // -----------------------------------------------------------------
    // Passwordless options on the password page
    // -----------------------------------------------------------------

    /**
     * A magic link and an OAuth provider each prove control of the address,
     * so the page offers them to an unverified account too — otherwise the
     * only way out of "unverified" would be the password the user may not
     * have.
     */
    public function test_password_page_offers_magic_link_and_oauth_to_an_unverified_account(): void
    {
        config(['neev.oauth' => ['google']]);

        $user = User::factory()->unverified()->create();

        $response = $this->put('/login', ['email' => $user->email]);

        $response->assertOk();
        $response->assertSee(route('login.link.send'), false);
        $response->assertSee(route('oauth.redirect', 'google'), false);
    }

    public function test_password_page_offers_magic_link_and_oauth_to_a_verified_account(): void
    {
        config(['neev.oauth' => ['google']]);

        $user = User::factory()->create();

        $response = $this->put('/login', ['email' => $user->email]);

        $response->assertOk();
        $response->assertSee(route('login.link.send'), false);
        $response->assertSee(route('oauth.redirect', 'google'), false);
    }
}
