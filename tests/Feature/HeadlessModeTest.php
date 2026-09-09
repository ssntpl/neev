<?php

namespace Ssntpl\Neev\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Ssntpl\Neev\Mail\TeamInvitation;
use Ssntpl\Neev\Mail\VerifyUserEmail;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\EmailLinks;
use Ssntpl\Neev\Tests\TestCase;

class HeadlessModeTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('neev.ui', null);
        $app['config']->set('neev.team', true);
    }

    // -----------------------------------------------------------------
    // Route registration
    // -----------------------------------------------------------------

    public function test_blade_page_routes_are_not_registered(): void
    {
        $this->get('/login')->assertNotFound();
        $this->get('/register')->assertNotFound();
        $this->assertFalse(Route::has('login'));
        $this->assertFalse(Route::has('account.security'));
    }

    public function test_machine_facing_routes_remain_registered(): void
    {
        // API namespace
        $this->assertTrue(Route::has('neev.csrf-cookie'));
        $this->assertTrue(Route::has('mail.verify'));
        // OAuth web redirect/callback (IdP contracts)
        $this->assertTrue(Route::has('oauth.redirect'));
        $this->assertTrue(Route::has('oauth.callback'));
        // Tenant SSO
        $this->assertTrue(Route::has('sso.callback'));
    }

    public function test_api_login_works_headless(): void
    {
        config(['neev.password' => ['required']]);
        $user = User::factory()->create(['password' => 'password123']);

        $this->postJson('/neev/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertOk()
            ->assertJsonPath('auth_state', 'authenticated');
    }

    // -----------------------------------------------------------------
    // Email links point at machine-facing routes, not Blade pages
    // -----------------------------------------------------------------

    protected function registerHeadlessUser(string $email = 'headless@example.com'): void
    {
        config(['neev.password' => ['required', 'confirmed'], 'neev.team' => false]);

        $this->postJson('/neev/register', [
            'name' => 'Headless User',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertOk();
    }

    /**
     * Verification finishes on the click, so the default headless link points
     * straight at the API route that does it — no page of the app's needed.
     */
    public function test_registration_verification_email_links_to_the_api_verify_route(): void
    {
        Mail::fake();

        $this->registerHeadlessUser();

        Mail::assertSent(VerifyUserEmail::class, function (VerifyUserEmail $mail) {
            return str_starts_with($mail->url, route('mail.verify') . '?')
                && str_contains($mail->url, 'signature=');
        });
    }

    /**
     * An app that wants the link to land on its own page overrides EmailLinks
     * rather than reaching into the service that sends the mail.
     */
    public function test_app_can_point_the_verification_link_at_its_own_page(): void
    {
        Mail::fake();

        $this->app->bind(EmailLinks::class, FrontendEmailLinks::class);

        $this->registerHeadlessUser();

        Mail::assertSent(VerifyUserEmail::class, function (VerifyUserEmail $mail) {
            return str_starts_with($mail->url, 'https://app.example.com/verify-email?')
                && str_contains($mail->url, 'signature=');
        });
    }

    public function test_new_user_team_invitation_links_to_frontend_register(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $team = \Ssntpl\Neev\Models\Team::model()->forceCreate([
            'name' => 'Headless Team',
            'user_id' => $owner->id,
            'is_public' => false,
            'activated_at' => now(),
        ]);
        $team->addMember($owner);
        $token = $owner->createLoginToken(1440)->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/teams/inviteUser', [
                'team_id' => $team->id,
                'email' => 'newcomer@example.com',
            ])->assertOk();

        Mail::assertSent(TeamInvitation::class, function (TeamInvitation $mail) {
            return str_starts_with($mail->url, config('app.url') . '/register?')
                && str_contains($mail->url, 'invitation_id=');
        });
    }
}

/**
 * A stand-in for the override an app writes when it wants email links to land
 * on its own frontend instead of finishing on the package's routes.
 */
class FrontendEmailLinks extends EmailLinks
{
    public function base(): string
    {
        return 'https://app.example.com';
    }

    public function verificationUrl(\Ssntpl\Neev\Models\User $user, \DateTimeInterface $expiresAt): string
    {
        return $this->page('/verify-email', parent::verificationUrl($user, $expiresAt));
    }
}
