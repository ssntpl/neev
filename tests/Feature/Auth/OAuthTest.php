<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\SocialiteServiceProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class OAuthTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    protected function getPackageProviders($app): array
    {
        return array_merge(parent::getPackageProviders($app), [
            SocialiteServiceProvider::class,
        ]);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('neev.oauth', ['google']);
        $app['config']->set('neev.home', '/dashboard');
        $app['config']->set('services.google', [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'redirect' => 'http://localhost/oauth/google/callback',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(
            dirname(__DIR__, 3) . '/vendor/ssntpl/laravel-acl/database/migrations'
        );
    }

    private function mockSocialiteRedirect(string $service = 'google'): void
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('with')->andReturnSelf();
        $provider->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.com/oauth'));

        Socialite::shouldReceive('driver')
            ->with($service)
            ->andReturn($provider);
    }

    private function mockSocialiteUser(string $email, string $name = 'Test User'): void
    {
        $socialiteUser = Mockery::mock(SocialiteUser::class);
        $socialiteUser->email = $email;
        $socialiteUser->name = $name;
        $socialiteUser->shouldReceive('getId')->andReturn('oauth-123');
        $socialiteUser->shouldReceive('getNickname')->andReturn(null);
        $socialiteUser->shouldReceive('getAvatar')->andReturn(null);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($socialiteUser);

        Socialite::shouldReceive('driver')
            ->with('google')
            ->andReturn($provider);
    }

    // -----------------------------------------------------------------
    // GET /oauth/{service} — redirect to provider
    // -----------------------------------------------------------------

    public function test_redirect_to_configured_oauth_provider(): void
    {
        $this->mockSocialiteRedirect();

        $response = $this->get('/neev/oauth/google');

        $response->assertRedirect();
        $this->assertStringContainsString('accounts.google.com', $response->headers->get('Location'));
    }

    public function test_redirect_passes_login_hint_when_email_provided(): void
    {
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('with')
            ->with(Mockery::on(function ($params) {
                return isset($params['login_hint']) && $params['login_hint'] === 'user@example.com';
            }))
            ->andReturnSelf();
        $provider->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.com/oauth'));

        Socialite::shouldReceive('driver')
            ->with('google')
            ->andReturn($provider);

        $response = $this->get('/neev/oauth/google?email=user@example.com');

        $response->assertRedirect();
    }

    public function test_redirect_returns_404_for_unconfigured_service(): void
    {
        $response = $this->get('/neev/oauth/github');

        $response->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // GET /oauth/{service}/callback — handle callback
    // -----------------------------------------------------------------

    public function test_callback_logs_in_existing_user(): void
    {
        $user = User::factory()->create();

        $this->mockSocialiteUser($user->email);

        $response = $this->get('/neev/oauth/google/callback?code=test-auth-code');

        $response->assertRedirect('/dashboard');
        // No stateful host configured: no SPA cookie.
        $this->assertNull(
            collect($response->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === 'neev_session')
        );
    }

    public function test_callback_on_stateful_host_also_issues_spa_cookie(): void
    {
        // Test requests hit "localhost" — declaring it stateful makes the
        // callback a same-origin SPA monolith flow.
        config(['neev.spa.stateful' => ['localhost']]);

        $user = User::factory()->create();
        $this->mockSocialiteUser($user->email);

        $response = $this->get('/neev/oauth/google/callback?code=test-auth-code');

        $response->assertRedirect('/dashboard');

        $cookie = collect($response->headers->getCookies())
            ->firstWhere(fn ($c) => $c->getName() === 'neev_session');
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertStringContainsString('|', $cookie->getValue());

        // The cookie-borne token authenticates API calls.
        $this->withCredentials()
            ->withHeader('Origin', 'http://localhost')
            ->withUnencryptedCookie('neev_session', $cookie->getValue())
            ->getJson('/neev/users')
            ->assertOk();
    }

    public function test_callback_creates_new_user_when_email_not_found(): void
    {
        $this->mockSocialiteUser('newuser@example.com', 'New OAuth User');

        $countBefore = User::count();

        $this->get('/neev/oauth/google/callback?code=test-auth-code');

        // User should be created regardless of login outcome
        $this->assertEquals($countBefore + 1, User::count());

        $user = User::where('email', 'newuser@example.com')->first();
        $this->assertNotNull($user);
    }

    public function test_callback_redirects_to_login_when_no_code(): void
    {
        $response = $this->get('/neev/oauth/google/callback');

        $response->assertRedirect(route('login'));
    }

    public function test_callback_redirects_to_login_for_unverified_email(): void
    {
        $user = User::factory()->unverified()->create();

        $this->mockSocialiteUser($user->email);

        $response = $this->get('/neev/oauth/google/callback?code=test-auth-code');

        $response->assertRedirect(route('login'));
    }

    public function test_callback_creates_team_when_teams_enabled(): void
    {
        $this->enableTeams();

        $this->mockSocialiteUser('teamuser@example.com', 'Team User');

        $this->get('/neev/oauth/google/callback?code=test-auth-code');

        $user = User::where('email', 'teamuser@example.com')->first();
        $this->assertNotNull($user);
        $this->assertGreaterThan(0, Team::where('user_id', $user->id)->count());
    }

    public function test_callback_returns_404_for_unconfigured_service(): void
    {
        $response = $this->get('/neev/oauth/github/callback?code=test-code');

        $response->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // GET /oauth/{service}/callback — domain federation
    // -----------------------------------------------------------------

    public function test_callback_with_domain_federation_skips_team_creation_for_verified_domain(): void
    {
        $this->enableTeams();
        $this->enableDomainFederation();

        $owner = User::factory()->create();
        $team = Team::forceCreate([
            'name' => 'Domain Team',
            'user_id' => $owner->id,
            'is_public' => false,
        ]);

        $team->domains()->create([
            'domain' => 'verified-corp.com',
            'verified_at' => now(),
            'is_primary' => true,
        ]);

        $this->mockSocialiteUser('newuser@verified-corp.com', 'Corp User');

        $this->get('/neev/oauth/google/callback?code=test-auth-code');

        $user = User::where('email', 'newuser@verified-corp.com')->first();
        $this->assertNotNull($user);

        // User should NOT have their own team since their domain matches a verified one
        $this->assertEquals(0, Team::where('user_id', $user->id)->count());
    }

    public function test_callback_with_domain_federation_creates_team_for_unverified_domain(): void
    {
        $this->enableTeams();
        $this->enableDomainFederation();

        $this->mockSocialiteUser('newuser@unknown-domain.com', 'Indie User');

        $this->get('/neev/oauth/google/callback?code=test-auth-code');

        $user = User::where('email', 'newuser@unknown-domain.com')->first();
        $this->assertNotNull($user);

        // User should have their own team since their domain is not verified
        $this->assertEquals(1, Team::where('user_id', $user->id)->count());
    }

}
