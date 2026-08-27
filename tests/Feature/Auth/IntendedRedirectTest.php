<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Ssntpl\Neev\Mail\VerifyUserEmail;
use Ssntpl\Neev\Models\MultiFactorAuth;
use Ssntpl\Neev\Services\AuthService;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

/**
 * A guest bounced off a protected page must land back on that page once the
 * login flow completes, not on the configured home.
 */
class IntendedRedirectTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('neev.home', '/dashboard');
    }

    protected function defineRoutes($router): void
    {
        $router->middleware(['web', 'neev:web'])->group(function ($router) {
            $router->get('/reports', fn () => 'reports')->name('reports');
        });

        $router->middleware(['web', 'neev:web', 'neev-verified-email'])->group(function ($router) {
            $router->get('/billing', fn () => 'billing')->name('billing');
        });
    }

    public function test_a_guest_is_bounced_to_login_with_the_destination_remembered(): void
    {
        $this->get('/reports')
            ->assertRedirect(route('login'));

        $this->assertSame(url('/reports'), session('url.intended'));
    }

    public function test_login_returns_the_user_to_the_page_they_asked_for(): void
    {
        $user = User::factory()->create();

        $this->get('/reports')->assertRedirect(route('login'));

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(url('/reports'));

        $this->assertNull(session('url.intended'));
    }

    /** A second login must not reuse the destination from the first. */
    public function test_the_remembered_destination_is_consumed(): void
    {
        $user = User::factory()->create();

        $this->get('/reports');
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->post('/logout');

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect('/dashboard');
    }

    /** An explicit form value beats whatever the middleware stashed. */
    public function test_an_explicit_redirect_wins_over_the_remembered_destination(): void
    {
        $user = User::factory()->create();

        $this->get('/reports');

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'redirect' => '/settings',
        ])->assertRedirect('/settings');
    }

    /** Sending the user back to /login would just bounce them in a circle. */
    public function test_an_auth_page_is_never_used_as_the_destination(): void
    {
        $user = User::factory()->create();

        session(['url.intended' => url('/login')]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect('/dashboard');
    }

    /** A destination on another host is dropped. */
    public function test_an_offsite_destination_is_dropped(): void
    {
        $user = User::factory()->create();

        session(['url.intended' => 'https://evil.example.com/steal']);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect('/dashboard');
    }

    public function test_the_destination_survives_an_mfa_challenge(): void
    {
        $this->enableMFA();

        $user = User::factory()->create();
        $user->multiFactorAuths()->create([
            'method' => 'email',
            'status' => MultiFactorAuth::STATUS_ACTIVE,
            'preferred' => true,
            'otp' => '654321',
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->get('/reports')->assertRedirect(route('login'));

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('otp.mfa.create', 'email'));

        $this->post(route('otp.mfa.store'), [
            'email' => $user->email,
            'auth_method' => 'email',
            'otp' => '654321',
        ])->assertRedirect(url('/reports'));
    }

    /** Verifying the address finishes the journey the user started. */
    public function test_the_destination_survives_email_verification(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get('/billing')
            ->assertRedirect(route('verification.notice'));

        $this->assertSame(url('/billing'), session('url.intended'));

        Mail::fake();
        app(AuthService::class)->sendEmailVerification($user);

        $otp = null;
        Mail::assertSent(VerifyUserEmail::class, function (VerifyUserEmail $mail) use (&$otp) {
            $otp = $mail->otp;
            return true;
        });

        $this->actingAs($user)
            ->post(route('email.verify.otp'), ['otp' => $otp])
            ->assertRedirect(url('/billing'));
    }
}
