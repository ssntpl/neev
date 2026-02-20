<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Ssntpl\Neev\Database\Factories\TeamAuthSettingsFactory;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\TenantResolver;
use Ssntpl\Neev\Services\TenantSSOManager;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class TenantSSOTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    /**
     * Set tenant_auth config BEFORE service provider boot
     * so that SSO routes are loaded.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('neev.tenant_auth', true);
        $app['config']->set('neev.tenant_isolation', true);
        $app['config']->set('neev.dashboard_url', '/dashboard');
        $app['config']->set('neev.frontend_url', 'http://localhost');
        $app['config']->set('neev.tenant_auth_options.sso_providers', ['entra', 'google']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(
            dirname(__DIR__, 3) . '/vendor/ssntpl/laravel-acl/database/migrations'
        );
    }

    private function setCurrentTenant($tenant): void
    {
        $resolver = Mockery::mock(TenantResolver::class);
        $resolver->shouldReceive('current')->andReturn($tenant);
        $resolver->shouldReceive('resolvedContext')->andReturn($tenant);
        $resolver->shouldReceive('currentDomain')->andReturn(null);
        $resolver->shouldReceive('resolve')->andReturn($tenant);
        $this->app->instance(TenantResolver::class, $resolver);
    }

    // -----------------------------------------------------------------
    // GET /api/tenant/auth — auth config
    // -----------------------------------------------------------------

    public function test_auth_config_returns_password_when_no_tenant(): void
    {
        $this->setCurrentTenant(null);

        $response = $this->getJson('/api/tenant/auth');

        $response->assertOk()
            ->assertJsonPath('auth_method', 'password')
            ->assertJsonPath('sso_enabled', false);
    }

    public function test_auth_config_returns_sso_when_tenant_has_sso(): void
    {
        $team = TeamFactory::new()->create();
        TeamAuthSettingsFactory::new()->sso('entra')->create([
            'team_id' => $team->id,
            'auth_method' => 'sso',
        ]);

        $this->setCurrentTenant($team);

        $response = $this->getJson('/api/tenant/auth');

        $response->assertOk()
            ->assertJsonPath('auth_method', 'sso')
            ->assertJsonPath('sso_enabled', true)
            ->assertJsonPath('sso_provider', 'entra');
    }

    public function test_auth_config_returns_password_when_tenant_uses_password(): void
    {
        $team = TeamFactory::new()->create();
        TeamAuthSettingsFactory::new()->create([
            'team_id' => $team->id,
            'auth_method' => 'password',
        ]);

        $this->setCurrentTenant($team);

        $response = $this->getJson('/api/tenant/auth');

        $response->assertOk()
            ->assertJsonPath('auth_method', 'password');
    }

    // -----------------------------------------------------------------
    // GET /sso/redirect — redirect to SSO provider
    // -----------------------------------------------------------------

    public function test_redirect_returns_error_when_no_tenant(): void
    {
        $this->setCurrentTenant(null);

        $response = $this->getJson('/sso/redirect');

        $response->assertStatus(400)
            ->assertJsonPath('status', 'error');
    }

    public function test_redirect_redirects_to_login_when_sso_not_required(): void
    {
        $team = TeamFactory::new()->create();
        TeamAuthSettingsFactory::new()->create([
            'team_id' => $team->id,
            'auth_method' => 'password',
        ]);

        $this->setCurrentTenant($team);

        $response = $this->get('/sso/redirect');

        $response->assertRedirect(route('login'));
    }

    public function test_redirect_returns_error_when_sso_not_configured(): void
    {
        $team = TeamFactory::new()->create();
        TeamAuthSettingsFactory::new()->create([
            'team_id' => $team->id,
            'auth_method' => 'sso',
        ]);

        $this->setCurrentTenant($team);

        $response = $this->getJson('/sso/redirect');

        // Controller calls handleError which returns 400 for JSON
        $response->assertStatus(400);
    }

    // -----------------------------------------------------------------
    // GET /sso/callback — handle SSO callback
    // -----------------------------------------------------------------

    public function test_callback_returns_error_when_no_tenant(): void
    {
        $this->setCurrentTenant(null);

        $response = $this->getJson('/sso/callback');

        $response->assertStatus(400)
            ->assertJsonPath('status', 'error');
    }

    public function test_callback_redirects_to_login_when_no_code(): void
    {
        $team = TeamFactory::new()->create();
        $this->setCurrentTenant($team);

        $response = $this->get('/sso/callback');

        $response->assertRedirect(route('login'));
    }

    public function test_callback_handles_oauth_error_response(): void
    {
        $team = TeamFactory::new()->create();
        $this->setCurrentTenant($team);

        $response = $this->get('/sso/callback?error=access_denied&error_description=User+cancelled');

        $response->assertRedirect(route('login'));
    }

    public function test_callback_successful_web_login(): void
    {
        $team = TeamFactory::new()->create();
        TeamAuthSettingsFactory::new()
            ->sso('entra')
            ->autoProvision('member')
            ->create(['team_id' => $team->id]);

        $user = User::factory()->create();
        $team->users()->attach($user, ['joined' => true, 'role' => 'member']);

        $this->setCurrentTenant($team);

        // Mock the SSO Manager to return a mock user
        $ssoUser = Mockery::mock(SocialiteUser::class);
        $ssoUser->shouldReceive('getEmail')->andReturn($user->email->email);
        $ssoUser->shouldReceive('getName')->andReturn($user->name);
        $ssoUser->shouldReceive('getId')->andReturn('sso-123');
        $ssoUser->shouldReceive('getAvatar')->andReturn(null);
        $ssoUser->shouldReceive('getNickname')->andReturn(null);

        $manager = Mockery::mock(TenantSSOManager::class);
        $manager->shouldReceive('handleCallback')->andReturn($ssoUser);
        $manager->shouldReceive('findOrCreateUser')->andReturn($user);
        $manager->shouldReceive('ensureMembership')->once();
        $this->app->instance(TenantSSOManager::class, $manager);

        $response = $this->get('/sso/callback?code=auth-code-123');

        $response->assertRedirect('/dashboard');
    }

    public function test_callback_successful_spa_login_with_redirect_uri(): void
    {
        $team = TeamFactory::new()->create();
        TeamAuthSettingsFactory::new()
            ->sso('entra')
            ->autoProvision('member')
            ->create(['team_id' => $team->id]);

        $user = User::factory()->create();
        $team->users()->attach($user, ['joined' => true, 'role' => 'member']);

        $this->setCurrentTenant($team);

        // Mock the SSO Manager
        $ssoUser = Mockery::mock(SocialiteUser::class);
        $ssoUser->shouldReceive('getEmail')->andReturn($user->email->email);
        $ssoUser->shouldReceive('getName')->andReturn($user->name);

        $manager = Mockery::mock(TenantSSOManager::class);
        $manager->shouldReceive('handleCallback')->andReturn($ssoUser);
        $manager->shouldReceive('findOrCreateUser')->andReturn($user);
        $manager->shouldReceive('ensureMembership')->once();
        $this->app->instance(TenantSSOManager::class, $manager);

        // Store redirect_uri in session (simulating the redirect step)
        session(['sso_redirect_uri' => 'http://localhost/app']);

        $response = $this->get('/sso/callback?code=auth-code-123');

        $response->assertRedirect();
        $this->assertStringContainsString('http://localhost/app', $response->headers->get('Location'));
        $this->assertStringContainsString('token=', $response->headers->get('Location'));
    }

    public function test_redirect_with_valid_sso_redirects_to_provider(): void
    {
        $team = TeamFactory::new()->create();
        TeamAuthSettingsFactory::new()
            ->sso('entra')
            ->create([
                'team_id' => $team->id,
                'auth_method' => 'sso',
            ]);

        $this->setCurrentTenant($team);

        // Mock the SSO Manager to return a mock Socialite driver
        $driver = Mockery::mock(\Laravel\Socialite\Contracts\Provider::class);
        $driver->shouldReceive('with')->andReturnSelf();
        $driver->shouldReceive('redirect')->andReturn(redirect('https://login.microsoftonline.com/oauth'));

        $manager = Mockery::mock(TenantSSOManager::class);
        $manager->shouldReceive('buildSocialiteDriver')
            ->with($team)
            ->andReturn($driver);
        $this->app->instance(TenantSSOManager::class, $manager);

        $response = $this->get('/sso/redirect');

        $response->assertRedirect();
        $this->assertStringContainsString('login.microsoftonline.com', $response->headers->get('Location'));
    }

    public function test_redirect_stores_redirect_uri_in_session(): void
    {
        $team = TeamFactory::new()->create();
        TeamAuthSettingsFactory::new()
            ->sso('entra')
            ->create([
                'team_id' => $team->id,
                'auth_method' => 'sso',
            ]);

        // Add a verified domain for the team to validate redirect_uri
        $team->domains()->create([
            'domain' => 'app.example.com',
            'verified_at' => now(),
            'is_primary' => true,
        ]);

        $this->setCurrentTenant($team);

        $driver = Mockery::mock(\Laravel\Socialite\Contracts\Provider::class);
        $driver->shouldReceive('with')->andReturnSelf();
        $driver->shouldReceive('redirect')->andReturn(redirect('https://login.microsoftonline.com/oauth'));

        $manager = Mockery::mock(TenantSSOManager::class);
        $manager->shouldReceive('buildSocialiteDriver')->andReturn($driver);
        $this->app->instance(TenantSSOManager::class, $manager);

        $response = $this->get('/sso/redirect?redirect_uri=' . urlencode('https://app.example.com/dashboard'));

        $response->assertRedirect();
        $this->assertEquals('https://app.example.com/dashboard', session('sso_redirect_uri'));
    }

    public function test_redirect_handles_socialite_exception(): void
    {
        $team = TeamFactory::new()->create();
        TeamAuthSettingsFactory::new()
            ->sso('entra')
            ->create([
                'team_id' => $team->id,
                'auth_method' => 'sso',
            ]);

        $this->setCurrentTenant($team);

        $manager = Mockery::mock(TenantSSOManager::class);
        $manager->shouldReceive('buildSocialiteDriver')
            ->andThrow(new Exception('Connection failed'));
        $this->app->instance(TenantSSOManager::class, $manager);

        $response = $this->get('/sso/redirect');

        $response->assertRedirect(route('login'));
    }

    // -----------------------------------------------------------------
    // GET /sso/callback — handle SSO callback
    // -----------------------------------------------------------------

    public function test_callback_handles_sso_manager_exception(): void
    {
        $team = TeamFactory::new()->create();
        $this->setCurrentTenant($team);

        $manager = Mockery::mock(TenantSSOManager::class);
        $manager->shouldReceive('handleCallback')
            ->andThrow(new Exception('Provider error'));
        $this->app->instance(TenantSSOManager::class, $manager);

        $response = $this->get('/sso/callback?code=auth-code-123');

        $response->assertRedirect(route('login'));
    }
}
