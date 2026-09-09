<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\SpaCsrfToken;
use Ssntpl\Neev\Tests\TestCase;

class SpaCookieModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['neev.spa.stateful' => ['app.example.com', '*.wild.example.com', 'localhost:3000']]);
    }

    private function createAuthenticatedUser(): array
    {
        $user = User::factory()->create();
        $newToken = $user->createLoginToken(config('neev.login_token_expiry_minutes', 1440));

        return [
            'user' => $user,
            'plainTextToken' => $newToken->plainTextToken,
        ];
    }

    /**
     * A cookie-mode session whose idle window is far enough along that
     * the next request renews it.
     *
     * @return array{user: User, plainTextToken: string}
     */
    private function createStaleSession(): array
    {
        $data = $this->createAuthenticatedUser();

        // 6 hours left of the 24 hour window: past the half-way point.
        $data['user']->accessTokens()->latest('id')->first()
            ->forceFill(['expires_at' => now()->addMinutes(360)])->saveQuietly();

        return $data;
    }

    private function authCookie($response): ?\Symfony\Component\HttpFoundation\Cookie
    {
        return collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === 'neev_session');
    }

    private function csrfPair(): array
    {
        $token = app(SpaCsrfToken::class)->issue();

        return [
            'cookie' => [config('neev.spa.csrf_cookie_name', 'XSRF-TOKEN') => $token],
            'header' => [config('neev.spa.csrf_header_name', 'X-XSRF-TOKEN') => $token],
        ];
    }

    // -----------------------------------------------------------------
    // /neev/csrf-cookie endpoint
    // -----------------------------------------------------------------

    public function test_csrf_cookie_endpoint_returns_204_with_signed_cookie(): void
    {
        $response = $this->get('/neev/csrf-cookie');

        $response->assertNoContent();
        $cookie = collect($response->headers->getCookies())
            ->firstWhere(fn ($c) => $c->getName() === 'XSRF-TOKEN');

        $this->assertNotNull($cookie);
        $this->assertFalse($cookie->isHttpOnly());
        $this->assertTrue(app(SpaCsrfToken::class)->validate($cookie->getValue(), $cookie->getValue()));
    }

    // -----------------------------------------------------------------
    // Cookie -> bearer promotion
    // -----------------------------------------------------------------

    public function test_stateful_origin_cookie_is_promoted_to_bearer(): void
    {
        $data = $this->createAuthenticatedUser();

        $response = $this->withCredentials()->withHeader('Origin', 'https://app.example.com')
            ->withUnencryptedCookie('neev_session', $data['plainTextToken'])
            ->getJson('/neev/users');

        $response->assertOk();
        $response->assertJsonPath('data.id', $data['user']->id);
    }

    public function test_wildcard_and_port_patterns_match(): void
    {
        $data = $this->createAuthenticatedUser();

        $this->withCredentials()->withHeader('Origin', 'https://sub.wild.example.com')
            ->withUnencryptedCookie('neev_session', $data['plainTextToken'])
            ->getJson('/neev/users')
            ->assertOk();

        $this->withCredentials()->withHeader('Origin', 'http://localhost:3000')
            ->withUnencryptedCookie('neev_session', $data['plainTextToken'])
            ->getJson('/neev/users')
            ->assertOk();
    }

    public function test_non_stateful_origin_is_ignored(): void
    {
        $data = $this->createAuthenticatedUser();

        $response = $this->withCredentials()->withHeader('Origin', 'https://evil.com')
            ->withUnencryptedCookie('neev_session', $data['plainTextToken'])
            ->getJson('/neev/users');

        $response->assertUnauthorized();
    }

    public function test_no_origin_or_referer_is_ignored(): void
    {
        $data = $this->createAuthenticatedUser();

        $this->withCredentials()
            ->withUnencryptedCookie('neev_session', $data['plainTextToken'])
            ->getJson('/neev/users')
            ->assertUnauthorized();
    }

    public function test_referer_fallback_matches_stateful_host(): void
    {
        $data = $this->createAuthenticatedUser();

        $this->withCredentials()->withHeader('Referer', 'https://app.example.com/dashboard')
            ->withUnencryptedCookie('neev_session', $data['plainTextToken'])
            ->getJson('/neev/users')
            ->assertOk();
    }

    public function test_existing_bearer_header_wins_over_cookie(): void
    {
        $data = $this->createAuthenticatedUser();
        $other = $this->createAuthenticatedUser();

        $response = $this->withCredentials()->withHeader('Origin', 'https://app.example.com')
            ->withHeader('Authorization', 'Bearer ' . $data['plainTextToken'])
            ->withUnencryptedCookie('neev_session', $other['plainTextToken'])
            ->getJson('/neev/users');

        $response->assertOk();
        $response->assertJsonPath('data.id', $data['user']->id);
    }

    public function test_empty_stateful_list_disables_spa_mode(): void
    {
        config(['neev.spa.stateful' => []]);
        $data = $this->createAuthenticatedUser();

        $this->withCredentials()->withHeader('Origin', 'https://app.example.com')
            ->withUnencryptedCookie('neev_session', $data['plainTextToken'])
            ->getJson('/neev/users')
            ->assertUnauthorized();
    }

    // -----------------------------------------------------------------
    // Sliding session cookie
    // -----------------------------------------------------------------

    public function test_cookie_is_re_issued_when_the_session_slides(): void
    {
        $data = $this->createStaleSession();

        $response = $this->withCredentials()->withHeader('Origin', 'https://app.example.com')
            ->withUnencryptedCookie('neev_session', $data['plainTextToken'])
            ->getJson('/neev/users');

        $response->assertOk();

        // Without this the browser drops the cookie at the deadline it
        // was first issued with, however active the session is.
        $cookie = $this->authCookie($response);

        $this->assertNotNull($cookie);
        $this->assertEquals($data['plainTextToken'], $cookie->getValue());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertEqualsWithDelta(
            now()->addMinutes(config('neev.login_token_expiry_minutes'))->getTimestamp(),
            $cookie->getExpiresTime(),
            120
        );
    }

    public function test_no_cookie_is_sent_while_the_session_is_inside_its_half_life(): void
    {
        // A freshly issued session has its full window left, so responses
        // carry no Set-Cookie header at all.
        $data = $this->createAuthenticatedUser();

        $response = $this->withCredentials()->withHeader('Origin', 'https://app.example.com')
            ->withUnencryptedCookie('neev_session', $data['plainTextToken'])
            ->getJson('/neev/users');

        $response->assertOk();
        $this->assertNull($this->authCookie($response));
    }

    public function test_logout_still_clears_the_cookie_on_a_sliding_session(): void
    {
        $data = $this->createStaleSession();
        $csrf = $this->csrfPair();

        $response = $this->withCredentials()->withHeader('Origin', 'https://app.example.com')
            ->withHeaders($csrf['header'])
            ->withUnencryptedCookie('neev_session', $data['plainTextToken'])
            ->withUnencryptedCookies($csrf['cookie'])
            ->postJson('/neev/logout');

        $response->assertOk();

        // The renewal must not overwrite the cookie logout cleared.
        $cookie = $this->authCookie($response);

        $this->assertNotNull($cookie);
        $this->assertEmpty($cookie->getValue());
        $this->assertLessThan(now()->getTimestamp(), $cookie->getExpiresTime());
    }

    public function test_bearer_callers_are_never_sent_a_cookie(): void
    {
        $data = $this->createStaleSession();

        $response = $this->withHeader('Authorization', 'Bearer ' . $data['plainTextToken'])
            ->getJson('/neev/users');

        $response->assertOk();
        $this->assertNull($this->authCookie($response));
    }

    public function test_an_actively_used_session_outlives_its_original_deadline(): void
    {
        $data = $this->createAuthenticatedUser();
        $token = $data['user']->accessTokens()->latest('id')->first();
        $originalDeadline = $token->expires_at;

        // Come back with 6 hours left, twice over: the deadline the user
        // started with passes while they are still working.
        $this->travelTo($originalDeadline->copy()->subHours(6));
        $this->withCredentials()->withHeader('Origin', 'https://app.example.com')
            ->withUnencryptedCookie('neev_session', $data['plainTextToken'])
            ->getJson('/neev/users')
            ->assertOk();

        $this->travelTo($originalDeadline->copy()->addHours(6));
        $this->withCredentials()->withHeader('Origin', 'https://app.example.com')
            ->withUnencryptedCookie('neev_session', $data['plainTextToken'])
            ->getJson('/neev/users')
            ->assertOk();

        $this->travelBack();
    }

    public function test_an_idle_session_expires_and_reports_why(): void
    {
        $data = $this->createAuthenticatedUser();

        $this->travelTo(now()->addMinutes(config('neev.login_token_expiry_minutes') + 1));

        $response = $this->withCredentials()->withHeader('Origin', 'https://app.example.com')
            ->withUnencryptedCookie('neev_session', $data['plainTextToken'])
            ->getJson('/neev/users');

        $response->assertUnauthorized();
        $response->assertJsonPath('code', 'token_expired');

        $this->travelBack();
    }

    public function test_a_session_is_not_slid_past_its_absolute_lifetime(): void
    {
        config(['neev.login_token_max_lifetime_minutes' => 43200]);

        $data = $this->createAuthenticatedUser();
        $token = $data['user']->accessTokens()->latest('id')->first();

        // Keep it alive across the whole 30 days, then step past. Each visit
        // is anchored a minute inside the current deadline: arriving exactly
        // on it is a coin toss the wall clock, not the sliding, decides.
        for ($day = 1; $day <= 30; $day++) {
            $this->travelTo($token->refresh()->expires_at->copy()->subMinute());

            $this->withCredentials()->withHeader('Origin', 'https://app.example.com')
                ->withUnencryptedCookie('neev_session', $data['plainTextToken'])
                ->getJson('/neev/users')
                ->assertOk();
        }

        $this->travelTo($token->refresh()->expires_at->copy()->addMinute());

        $this->withCredentials()->withHeader('Origin', 'https://app.example.com')
            ->withUnencryptedCookie('neev_session', $data['plainTextToken'])
            ->getJson('/neev/users')
            ->assertUnauthorized();

        $this->travelBack();
    }

    // -----------------------------------------------------------------
    // CSRF enforcement on state-changing methods
    // -----------------------------------------------------------------

    public function test_post_without_csrf_token_is_rejected_with_419(): void
    {
        $data = $this->createAuthenticatedUser();

        $response = $this->withCredentials()->withHeader('Origin', 'https://app.example.com')
            ->withUnencryptedCookie('neev_session', $data['plainTextToken'])
            ->postJson('/neev/logout');

        $response->assertStatus(419);
    }

    public function test_post_with_valid_csrf_pair_proceeds(): void
    {
        $data = $this->createAuthenticatedUser();
        $csrf = $this->csrfPair();

        $response = $this->withCredentials()->withHeader('Origin', 'https://app.example.com')
            ->withHeaders($csrf['header'])
            ->withUnencryptedCookie('neev_session', $data['plainTextToken'])
            ->withUnencryptedCookies($csrf['cookie'])
            ->postJson('/neev/logout');

        $response->assertOk();
    }

    public function test_post_with_mismatched_csrf_pair_is_rejected(): void
    {
        $data = $this->createAuthenticatedUser();
        $csrf = $this->csrfPair();
        $other = app(SpaCsrfToken::class)->issue();

        $response = $this->withCredentials()->withHeader('Origin', 'https://app.example.com')
            ->withHeader('X-XSRF-TOKEN', $other)
            ->withUnencryptedCookie('neev_session', $data['plainTextToken'])
            ->withUnencryptedCookies($csrf['cookie'])
            ->postJson('/neev/logout');

        $response->assertStatus(419);
    }

    public function test_post_with_unsigned_forged_token_is_rejected(): void
    {
        // Subdomain cookie injection: attacker plants a matching pair,
        // but cannot sign the token with the app key.
        $data = $this->createAuthenticatedUser();
        $forged = 'forgedvalue.deadbeefdeadbeefdeadbeef';

        $response = $this->withCredentials()->withHeader('Origin', 'https://app.example.com')
            ->withHeader('X-XSRF-TOKEN', $forged)
            ->withUnencryptedCookie('neev_session', $data['plainTextToken'])
            ->withUnencryptedCookies(['XSRF-TOKEN' => $forged])
            ->postJson('/neev/logout');

        $response->assertStatus(419);
    }

    public function test_get_requests_skip_csrf_but_still_authenticate(): void
    {
        $data = $this->createAuthenticatedUser();

        $this->withCredentials()->withHeader('Origin', 'https://app.example.com')
            ->withUnencryptedCookie('neev_session', $data['plainTextToken'])
            ->getJson('/neev/users')
            ->assertOk();
    }

    public function test_bearer_callers_are_exempt_from_csrf(): void
    {
        // No Origin header (mobile/server-to-server): CSRF never applies.
        $data = $this->createAuthenticatedUser();

        $this->withCredentials()->withHeader('Authorization', 'Bearer ' . $data['plainTextToken'])
            ->postJson('/neev/logout')
            ->assertOk();
    }
}
