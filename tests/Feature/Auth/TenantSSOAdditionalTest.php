<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Database\Factories\TeamAuthSettingsFactory;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Services\TenantResolver;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;
use Mockery;

class TenantSSOAdditionalTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('neev.tenant_auth', true);
        $app['config']->set('neev.tenant_isolation', true);
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
        $this->app->instance(TenantResolver::class, $resolver);
    }

    // -----------------------------------------------------------------
    // Missing test case: authConfig with SSO configured but auth_method is password
    // -----------------------------------------------------------------

    public function test_auth_config_returns_sso_disabled_when_tenant_has_sso_config_but_uses_password(): void
    {
        $team = TeamFactory::new()->create();
        TeamAuthSettingsFactory::new()->sso('entra')->create([
            'team_id' => $team->id,
            'auth_method' => 'password', // SSO configured but not used
        ]);

        $this->setCurrentTenant($team);

        $response = $this->getJson('/api/tenant/auth');

        $response->assertOk()
            ->assertJsonPath('auth_method', 'password')
            ->assertJsonPath('sso_enabled', false);
        // Provider may not be returned when auth_method is password
    }

    // -----------------------------------------------------------------
    // Missing test case: redirect with invalid redirect_uri
    // -----------------------------------------------------------------

    public function test_redirect_ignores_invalid_redirect_uri(): void
    {
        $team = TeamFactory::new()->create();
        TeamAuthSettingsFactory::new()
            ->sso('entra')
            ->create([
                'team_id' => $team->id,
                'auth_method' => 'sso',
            ]);

        $this->setCurrentTenant($team);

        $driver = Mockery::mock(\Laravel\Socialite\Contracts\Provider::class);
        $driver->shouldReceive('with')->andReturnSelf();
        $driver->shouldReceive('redirect')->andReturn(redirect('https://login.microsoftonline.com/oauth'));

        $manager = Mockery::mock(\Ssntpl\Neev\Services\TenantSSOManager::class);
        $manager->shouldReceive('buildSocialiteDriver')->andReturn($driver);
        $this->app->instance(\Ssntpl\Neev\Services\TenantSSOManager::class, $manager);

        // Invalid redirect_uri (external domain)
        $response = $this->get('/sso/redirect?redirect_uri=' . urlencode('https://malicious.com/steal'));

        $response->assertRedirect();
        // Should not store invalid redirect_uri in session
        $this->assertNull(session('sso_redirect_uri'));
    }

    // -----------------------------------------------------------------
    // Missing test case: redirect with email login hint
    // -----------------------------------------------------------------

    public function test_redirect_passes_email_as_login_hint(): void
    {
        $team = TeamFactory::new()->create();
        TeamAuthSettingsFactory::new()
            ->sso('entra')
            ->create([
                'team_id' => $team->id,
                'auth_method' => 'sso',
            ]);

        $this->setCurrentTenant($team);

        $driver = Mockery::mock(\Laravel\Socialite\Contracts\Provider::class);
        $driver->shouldReceive('with')->with(['login_hint' => 'user@example.com'])->andReturnSelf();
        $driver->shouldReceive('redirect')->andReturn(redirect('https://login.microsoftonline.com/oauth'));

        $manager = Mockery::mock(\Ssntpl\Neev\Services\TenantSSOManager::class);
        $manager->shouldReceive('buildSocialiteDriver')->andReturn($driver);
        $this->app->instance(\Ssntpl\Neev\Services\TenantSSOManager::class, $manager);

        $response = $this->get('/sso/redirect?email=user@example.com');

        $response->assertRedirect();
    }
}
