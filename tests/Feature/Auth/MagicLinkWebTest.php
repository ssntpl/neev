<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Ssntpl\Neev\Mail\LoginUsingLink;
use Ssntpl\Neev\Models\MagicLinkToken;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\MagicLink\MagicLinkManager;
use Ssntpl\Neev\Tests\TestCase;

/**
 * End-to-end coverage for the Blade magic-link flow: send -> open -> confirm.
 *
 * The API flow is covered by MagicLinkTest; these tests exist because the web
 * flow is a separate controller, route and view, and nothing else exercises it.
 */
class MagicLinkWebTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(array $state = []): User
    {
        return User::factory()->create($state);
    }

    private function magicLinkToken(User $user): array
    {
        return app(MagicLinkManager::class)->forWeb($user);
    }

    // -----------------------------------------------------------------
    // POST /login/link  (send)
    // -----------------------------------------------------------------

    public function test_send_login_link_mails_a_working_verify_url(): void
    {
        Mail::fake();

        $user = $this->createUser();

        $response = $this->post('/login/link', ['email' => $user->email]);

        $response->assertSessionHas('status', 'Login link has been sent.');
        $this->assertDatabaseCount('magic_link_tokens', 1);

        Mail::assertSent(LoginUsingLink::class, function (LoginUsingLink $mail) use ($user) {
            return $mail->hasTo($user->email)
                && str_contains($mail->url, '/login-link/verify')
                && str_contains($mail->url, 'token=');
        });
    }

    /**
     * The emailed link must be built from configuration, never from the request.
     *
     * `route()` derives its host from the Host header, which an unauthenticated
     * attacker controls: a forged Host would mail the user a genuine, working
     * single-use login token pointing at the attacker's server.
     */
    public function test_send_login_link_ignores_a_forged_host_header(): void
    {
        Mail::fake();

        $user = $this->createUser();

        $this->post('http://evil.attacker.net/login/link', ['email' => $user->email]);

        Mail::assertSent(LoginUsingLink::class, function (LoginUsingLink $mail) {
            return !str_contains($mail->url, 'evil.attacker.net')
                && str_starts_with($mail->url, rtrim(config('app.url'), '/') . '/login-link/verify');
        });
    }

    public function test_send_login_link_with_unknown_email_reports_an_error(): void
    {
        Mail::fake();

        $response = $this->post('/login/link', ['email' => 'nobody@example.com']);

        $response->assertSessionHasErrors('message');
        Mail::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // GET /login-link/verify  (open — must never consume)
    // -----------------------------------------------------------------

    public function test_get_renders_the_confirmation_page_without_consuming_the_token(): void
    {
        config(['neev.magic_link.require_confirmation' => true]);

        $user = $this->createUser();
        $link = $this->magicLinkToken($user);

        $response = $this->get('/login-link/verify?token=' . $link['token']);

        $response->assertOk();
        $response->assertSee('Please confirm that you want to sign in.', escape: false);
        $response->assertSee($link['token'], escape: false);

        // The whole point: an email scanner's prefetch must leave the link usable.
        $this->assertGuest();
        $this->assertDatabaseCount('magic_link_tokens', 1);
    }

    /**
     * The Blade route is the URL that actually lands in the inbox, so it is the
     * one a gateway prefetches — and some probe with HEAD rather than GET.
     * `Request::isMethod('get')` is false for HEAD, so guarding only the GET
     * branch would let a HEAD probe fall through and consume the link.
     */
    public function test_head_prefetch_does_not_consume_the_token(): void
    {
        $user = $this->createUser();
        $link = $this->magicLinkToken($user);

        $this->call('HEAD', '/login-link/verify?token=' . $link['token'])->assertOk();

        $this->assertGuest();
        $this->assertDatabaseCount('magic_link_tokens', 1);

        // The human who follows the same link still gets in.
        $this->post('/login-link/verify', ['token' => $link['token']])
            ->assertRedirect(config('neev.home'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_scanner_prefetch_followed_by_a_real_click_still_logs_the_user_in(): void
    {
        config(['neev.magic_link.require_confirmation' => true]);

        $user = $this->createUser();
        $link = $this->magicLinkToken($user);

        // Mail gateway prefetches the link...
        $this->get('/login-link/verify?token=' . $link['token'])->assertOk();

        // ...and the human who follows it is still able to sign in.
        $response = $this->post('/login-link/verify', ['token' => $link['token']]);

        $response->assertRedirect(config('neev.home'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_get_consumes_the_token_when_confirmation_is_disabled(): void
    {
        config(['neev.magic_link.require_confirmation' => false]);

        $user = $this->createUser();
        $link = $this->magicLinkToken($user);

        $response = $this->get('/login-link/verify?token=' . $link['token']);

        $response->assertRedirect(config('neev.home'));
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('magic_link_tokens', 0);
    }

    // -----------------------------------------------------------------
    // POST /login-link/verify  (confirm)
    // -----------------------------------------------------------------

    public function test_confirming_logs_in_and_consumes_the_token(): void
    {
        $user = $this->createUser();
        $link = $this->magicLinkToken($user);

        $response = $this->post('/login-link/verify', ['token' => $link['token']]);

        $response->assertRedirect(config('neev.home'));
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('magic_link_tokens', 0);

        $this->assertDatabaseHas('login_attempts', [
            'user_id' => $user->id,
            'method' => 'magic auth',
            'is_success' => true,
        ]);
    }

    public function test_confirming_twice_fails_the_second_time(): void
    {
        $user = $this->createUser();
        $link = $this->magicLinkToken($user);

        $this->post('/login-link/verify', ['token' => $link['token']])
            ->assertRedirect(config('neev.home'));

        $this->post('/logout');

        $this->post('/login-link/verify', ['token' => $link['token']])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('message');
    }

    public function test_expired_token_is_rejected(): void
    {
        $user = $this->createUser();
        $link = $this->magicLinkToken($user);
        $link['model']->forceFill(['expires_at' => now()->subMinute()])->save();

        $response = $this->post('/login-link/verify', ['token' => $link['token']]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('message');
        $this->assertGuest();
    }

    public function test_unknown_token_is_rejected(): void
    {
        $this->createUser();

        $response = $this->post('/login-link/verify', ['token' => 'not-a-real-token']);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('message');
        $this->assertGuest();
    }

    public function test_inactive_user_cannot_redeem_a_link(): void
    {
        $user = $this->createUser(['active' => false]);
        $link = $this->magicLinkToken($user);

        $response = $this->post('/login-link/verify', ['token' => $link['token']]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('message');
        $this->assertGuest();
    }

    public function test_generating_a_new_link_invalidates_the_previous_one(): void
    {
        $user = $this->createUser();

        $old = $this->magicLinkToken($user);
        $new = $this->magicLinkToken($user);

        $this->post('/login-link/verify', ['token' => $old['token']])
            ->assertSessionHasErrors('message');
        $this->assertGuest();

        $this->post('/login-link/verify', ['token' => $new['token']])
            ->assertRedirect(config('neev.home'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_already_authenticated_user_is_sent_home(): void
    {
        $user = $this->createUser();
        $link = $this->magicLinkToken($user);

        $response = $this->actingAs($user)->get('/login-link/verify?token=' . $link['token']);

        $response->assertRedirect(config('neev.home'));

        // The link is untouched — it was never redeemed.
        $this->assertDatabaseCount('magic_link_tokens', 1);
    }

    public function test_the_modern_web_login_flow_stays_on_the_confirmation_page_until_post_confirm(): void
    {
        config(['neev.magic_link.require_confirmation' => true]);

        $user = $this->createUser();
        $link = $this->magicLinkToken($user);

        $this->get('/login-link/verify?token=' . $link['token'])
            ->assertOk()
            ->assertSee('Please confirm that you want to sign in.', escape: false);

        $this->post('/login-link/verify', ['token' => $link['token']])
            ->assertRedirect(config('neev.home'));

        $this->assertAuthenticatedAs($user);
    }

    // -----------------------------------------------------------------
    // Token storage
    // -----------------------------------------------------------------

    public function test_only_the_token_hash_is_persisted(): void
    {
        $user = $this->createUser();
        $link = $this->magicLinkToken($user);

        $this->assertDatabaseMissing('magic_link_tokens', ['token' => $link['token']]);
        $this->assertDatabaseHas('magic_link_tokens', [
            'token' => MagicLinkToken::hashToken($link['token']),
        ]);
    }
}
