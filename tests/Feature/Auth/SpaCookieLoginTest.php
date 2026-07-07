<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\MagicLink\MagicLinkManager;
use Ssntpl\Neev\Services\SpaCsrfToken;
use Ssntpl\Neev\Tests\TestCase;

class SpaCookieLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'neev.spa.stateful' => ['app.example.com'],
            'neev.password' => ['required', 'confirmed'],
        ]);
    }

    private function authCookie($response)
    {
        return collect($response->headers->getCookies())
            ->firstWhere(fn ($c) => $c->getName() === config('neev.spa.cookie_name', 'neev_session'));
    }

    private function csrfHeaders(): array
    {
        $token = app(SpaCsrfToken::class)->issue();

        return ['header' => ['X-XSRF-TOKEN' => $token], 'cookie' => ['XSRF-TOKEN' => $token]];
    }

    // -----------------------------------------------------------------
    // Login sets the cookie, strips the token
    // -----------------------------------------------------------------

    public function test_spa_login_sets_cookie_and_omits_token_from_body(): void
    {
        $user = User::factory()->create(['password' => 'password123']);

        $response = $this->withHeader('Origin', 'https://app.example.com')
            ->postJson('/neev/login', ['email' => $user->email, 'password' => 'password123']);

        $response->assertOk();
        $response->assertJsonPath('auth_state', 'authenticated');
        $response->assertJsonMissingPath('token');

        $cookie = $this->authCookie($response);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertStringContainsString('|', $cookie->getValue());
    }

    public function test_non_spa_login_keeps_token_in_body_and_sets_no_cookie(): void
    {
        $user = User::factory()->create(['password' => 'password123']);

        $response = $this->postJson('/neev/login', ['email' => $user->email, 'password' => 'password123']);

        $response->assertOk();
        $this->assertNotEmpty($response->json('token'));
        $this->assertNull($this->authCookie($response));
    }

    public function test_spa_registration_sets_cookie_and_omits_token(): void
    {
        $response = $this->withHeader('Origin', 'https://app.example.com')
            ->postJson('/neev/register', [
                'name' => 'SPA User',
                'email' => 'spa@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ]);

        $response->assertOk();
        $response->assertJsonMissingPath('token');
        $this->assertNotNull($this->authCookie($response));
    }

    public function test_spa_magic_link_login_sets_cookie(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $link = app(MagicLinkManager::class)->forWeb($user);

        $response = $this->withHeader('Origin', 'https://app.example.com')
            ->getJson('/neev/loginUsingLink?token=' . $link['token']);

        $response->assertOk();
        $response->assertJsonMissingPath('token');
        $this->assertNotNull($this->authCookie($response));
    }

    // -----------------------------------------------------------------
    // MFA hand-off: JWT cookie, replaced on verification
    // -----------------------------------------------------------------

    public function test_spa_mfa_flow_carries_jwt_then_login_token_in_cookie(): void
    {
        $user = User::factory()->create(['password' => 'password123']);
        $user->addMultiFactorAuth('email');

        // Step 1: password login -> mfa_required, JWT in cookie, no token in body.
        $step1 = $this->withHeader('Origin', 'https://app.example.com')
            ->postJson('/neev/login', ['email' => $user->email, 'password' => 'password123']);

        $step1->assertOk();
        $step1->assertJsonPath('auth_state', 'mfa_required');
        $step1->assertJsonMissingPath('token');

        $jwtCookie = $this->authCookie($step1);
        $this->assertNotNull($jwtCookie);
        // A JWT, not an {id}|{plaintext} token.
        $this->assertStringNotContainsString('|', $jwtCookie->getValue());

        // Step 2: verify the emailed OTP; cookie promotes the JWT, response
        // replaces it with the real login token.
        $otp = '123456';
        $auth = $user->multiFactorAuths()->where('method', 'email')->first();
        $auth->otp = $otp;
        $auth->expires_at = now()->addMinutes(10);
        $auth->save();

        $csrf = $this->csrfHeaders();
        $step2 = $this->withCredentials()
            ->withHeader('Origin', 'https://app.example.com')
            ->withHeaders($csrf['header'])
            ->withUnencryptedCookie('neev_session', $jwtCookie->getValue())
            ->withUnencryptedCookies($csrf['cookie'])
            ->postJson('/neev/mfa/otp/verify', ['auth_method' => 'email', 'otp' => $otp]);

        $step2->assertOk();
        $step2->assertJsonPath('auth_state', 'authenticated');
        $step2->assertJsonMissingPath('token');

        $loginCookie = $this->authCookie($step2);
        $this->assertNotNull($loginCookie);
        $this->assertStringContainsString('|', $loginCookie->getValue());
    }

    // -----------------------------------------------------------------
    // Logout clears the cookie
    // -----------------------------------------------------------------

    public function test_spa_logout_expires_the_cookie(): void
    {
        $user = User::factory()->create();
        $token = $user->createLoginToken(1440)->plainTextToken;
        $csrf = $this->csrfHeaders();

        $response = $this->withCredentials()
            ->withHeader('Origin', 'https://app.example.com')
            ->withHeaders($csrf['header'])
            ->withUnencryptedCookie('neev_session', $token)
            ->withUnencryptedCookies($csrf['cookie'])
            ->postJson('/neev/logout');

        $response->assertOk();

        $cookie = $this->authCookie($response);
        $this->assertNotNull($cookie);
        $this->assertSame('', (string) $cookie->getValue());
        $this->assertLessThan(time(), $cookie->getExpiresTime());
    }

    public function test_bearer_logout_does_not_touch_cookies(): void
    {
        $user = User::factory()->create();
        $token = $user->createLoginToken(1440)->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/logout');

        $response->assertOk();
        $this->assertNull($this->authCookie($response));
    }

    // -----------------------------------------------------------------
    // End-to-end: cookie from login authenticates the next request
    // -----------------------------------------------------------------

    public function test_cookie_issued_at_login_authenticates_subsequent_requests(): void
    {
        $user = User::factory()->create(['password' => 'password123']);

        $login = $this->withHeader('Origin', 'https://app.example.com')
            ->postJson('/neev/login', ['email' => $user->email, 'password' => 'password123']);

        $cookieValue = $this->authCookie($login)->getValue();

        $this->withCredentials()
            ->withHeader('Origin', 'https://app.example.com')
            ->withUnencryptedCookie('neev_session', $cookieValue)
            ->getJson('/neev/users')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }
}
