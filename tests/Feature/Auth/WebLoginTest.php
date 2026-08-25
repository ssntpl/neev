<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
