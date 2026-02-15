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

        $app['config']->set('neev.dashboard_url', '/dashboard');
    }

    // -----------------------------------------------------------------
    // PUT /login — check email / show password form
    // -----------------------------------------------------------------

    public function test_login_password_with_valid_email_returns_ok(): void
    {
        $user = User::factory()->create();

        $response = $this->put('/login', [
            'email' => $user->email->email,
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
            'email' => $user->email->email,
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
        config(['neev.email_verified' => true]);

        $user = User::factory()->create();
        $email = $user->email;
        $email->verified_at = null;
        $email->save();

        $response = $this->post('/login', [
            'email' => $email->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('verification.notice'));
    }
}
