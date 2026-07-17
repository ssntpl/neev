<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Ssntpl\Neev\Events\EmailVerified;
use Ssntpl\Neev\Exceptions\MagicLinkUnverifiedException;
use Ssntpl\Neev\Mail\LoginUsingLink;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\MagicLink\MagicLinkManager;
use Ssntpl\Neev\Tests\TestCase;

/**
 * Magic links and unverified email addresses.
 *
 * Previously a link was mailed to an unverified address and every redemption
 * of it failed as "invalid or expired" — a dead end. Now the send is refused
 * outright, unless `magic_link.allow_unverified_users` is on, in which case
 * redeeming the link verifies the address.
 */
class MagicLinkUnverifiedUserTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): MagicLinkManager
    {
        return app(MagicLinkManager::class);
    }

    private function unverifiedUser(): User
    {
        return User::factory()->create(['email_verified_at' => null]);
    }

    // -----------------------------------------------------------------
    // Default: refuse to send
    // -----------------------------------------------------------------

    public function test_api_send_to_unverified_user_is_refused(): void
    {
        Mail::fake();

        $user = $this->unverifiedUser();

        $response = $this->postJson('/neev/sendLoginLink', ['email' => $user->email]);

        $response->assertStatus(403);
        $response->assertJson(['message' => 'Please verify your email address before using a login link.']);

        // No dead link is minted, and nothing is mailed.
        Mail::assertNothingSent();
        $this->assertDatabaseCount('magic_link_tokens', 0);
    }

    public function test_blade_send_to_unverified_user_shows_an_error(): void
    {
        Mail::fake();

        $user = $this->unverifiedUser();

        $this->post('/login/link', ['email' => $user->email])
            ->assertSessionHasErrors('message');

        Mail::assertNothingSent();
        $this->assertDatabaseCount('magic_link_tokens', 0);
    }

    public function test_generate_for_unverified_user_throws(): void
    {
        $this->expectException(MagicLinkUnverifiedException::class);

        $this->manager()->forWeb($this->unverifiedUser());
    }

    public function test_refusing_to_send_leaves_an_existing_link_intact(): void
    {
        $user = User::factory()->create();
        $existing = $this->manager()->forWeb($user);

        // The address loses verification after a link was already issued.
        $user->forceFill(['email_verified_at' => null])->save();

        try {
            $this->manager()->forWeb($user);
            $this->fail('Expected the send to be refused.');
        } catch (MagicLinkUnverifiedException) {
            // Expected.
        }

        // A refused send must not cost the user the link already in their inbox.
        $this->assertDatabaseCount('magic_link_tokens', 1);
        $this->assertNotNull(
            \Ssntpl\Neev\Models\MagicLinkToken::findByToken($existing['token']),
            'The existing link must survive a refused send.'
        );
    }

    public function test_verified_users_are_unaffected(): void
    {
        Mail::fake();

        $user = User::factory()->create();

        $this->postJson('/neev/sendLoginLink', ['email' => $user->email])->assertOk();

        Mail::assertSent(LoginUsingLink::class);
    }

    // -----------------------------------------------------------------
    // allow_unverified_users => true
    // -----------------------------------------------------------------

    public function test_when_allowed_the_link_is_sent(): void
    {
        config(['neev.magic_link.allow_unverified_users' => true]);
        Mail::fake();

        $user = $this->unverifiedUser();

        $this->postJson('/neev/sendLoginLink', ['email' => $user->email])->assertOk();

        Mail::assertSent(LoginUsingLink::class);
        $this->assertDatabaseCount('magic_link_tokens', 1);
    }

    public function test_when_allowed_redeeming_logs_in_and_verifies_the_email(): void
    {
        config(['neev.magic_link.allow_unverified_users' => true]);

        $user = $this->unverifiedUser();
        $link = $this->manager()->forWeb($user);

        $response = $this->postJson('/neev/loginUsingLink', ['token' => $link['token']]);

        $response->assertOk();
        $response->assertJson([
            'auth_state' => 'authenticated',
            'email_verified' => true,
        ]);

        $this->assertNotNull($user->fresh()->email_verified_at, 'Following the link proves inbox control.');
    }

    public function test_when_allowed_redeeming_fires_the_email_verified_event(): void
    {
        config(['neev.magic_link.allow_unverified_users' => true]);

        $fired = [];
        $this->app['events']->listen(EmailVerified::class, function ($event) use (&$fired) {
            $fired[] = $event;
        });

        $user = $this->unverifiedUser();
        $link = $this->manager()->forWeb($user);

        $this->postJson('/neev/loginUsingLink', ['token' => $link['token']])->assertOk();

        $this->assertCount(1, $fired, 'EmailVerified should fire exactly once.');
    }

    public function test_when_allowed_the_blade_flow_verifies_and_logs_in(): void
    {
        config(['neev.magic_link.allow_unverified_users' => true]);

        $user = $this->unverifiedUser();
        $link = $this->manager()->forWeb($user);

        $this->post('/login-link/verify', ['token' => $link['token']])
            ->assertRedirect(config('neev.home'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_an_already_verified_user_is_not_reverified(): void
    {
        $fired = [];
        $this->app['events']->listen(EmailVerified::class, function ($event) use (&$fired) {
            $fired[] = $event;
        });

        $user = User::factory()->create();
        $verifiedAt = $user->email_verified_at;

        $link = $this->manager()->forWeb($user);
        $this->postJson('/neev/loginUsingLink', ['token' => $link['token']])->assertOk();

        $this->assertCount(0, $fired);
        $this->assertEquals($verifiedAt, $user->fresh()->email_verified_at);
    }

    // -----------------------------------------------------------------
    // Send input validation
    // -----------------------------------------------------------------

    public function test_send_without_an_email_returns_a_validation_error(): void
    {
        $this->postJson('/neev/sendLoginLink', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_send_with_a_non_string_email_returns_a_validation_error(): void
    {
        $this->postJson('/neev/sendLoginLink', ['email' => ['a@example.com']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }
}
