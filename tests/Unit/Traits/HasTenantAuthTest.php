<?php

namespace Ssntpl\Neev\Tests\Unit\Traits;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Database\Factories\TeamAuthSettingsFactory;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Models\TeamAuthSettings;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class HasTenantAuthTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    // -----------------------------------------------------------------
    // authSettings()
    // -----------------------------------------------------------------

    public function test_auth_settings_returns_related_settings(): void
    {
        $team = TeamFactory::new()->create();

        TeamAuthSettingsFactory::new()->create([
            'team_id' => $team->id,
            'auth_method' => 'sso',
        ]);

        $settings = $team->authSettings;

        $this->assertNotNull($settings);
        $this->assertInstanceOf(TeamAuthSettings::class, $settings);
        $this->assertEquals('sso', $settings->auth_method);
    }

    public function test_auth_settings_returns_null_when_no_settings(): void
    {
        $team = TeamFactory::new()->create();

        $this->assertNull($team->authSettings);
    }

    // -----------------------------------------------------------------
    // getAuthMethod()
    // -----------------------------------------------------------------

    public function test_get_auth_method_returns_password_when_tenant_auth_disabled(): void
    {
        config(['neev.tenant_auth' => false]);

        $team = TeamFactory::new()->create();

        TeamAuthSettingsFactory::new()->sso()->create([
            'team_id' => $team->id,
        ]);

        $this->assertEquals('password', $team->getAuthMethod());
    }

    public function test_get_auth_method_returns_auth_method_from_settings_when_enabled(): void
    {
        $this->enableTenantAuth();

        $team = TeamFactory::new()->create();

        TeamAuthSettingsFactory::new()->sso()->create([
            'team_id' => $team->id,
        ]);

        $team->load('authSettings');

        $this->assertEquals('sso', $team->getAuthMethod());
    }

    public function test_get_auth_method_returns_config_default_when_no_settings(): void
    {
        $this->enableTenantAuth();
        config(['neev.tenant_auth_options.default_method' => 'password']);

        $team = TeamFactory::new()->create();

        $this->assertEquals('password', $team->getAuthMethod());
    }

    public function test_get_auth_method_returns_password_default_when_no_settings_and_no_config(): void
    {
        $this->enableTenantAuth();

        $team = TeamFactory::new()->create();

        // Without explicit config, default should be 'password'
        $this->assertEquals('password', $team->getAuthMethod());
    }

    // -----------------------------------------------------------------
    // requiresSSO()
    // -----------------------------------------------------------------

    public function test_requires_sso_returns_true_when_method_is_sso(): void
    {
        $this->enableTenantAuth();

        $team = TeamFactory::new()->create();

        TeamAuthSettingsFactory::new()->sso()->create([
            'team_id' => $team->id,
        ]);

        $team->load('authSettings');

        $this->assertTrue($team->requiresSSO());
    }

    public function test_requires_sso_returns_false_when_method_is_password(): void
    {
        $this->enableTenantAuth();

        $team = TeamFactory::new()->create();

        TeamAuthSettingsFactory::new()->create([
            'team_id' => $team->id,
            'auth_method' => 'password',
        ]);

        $team->load('authSettings');

        $this->assertFalse($team->requiresSSO());
    }

    public function test_requires_sso_returns_false_when_tenant_auth_disabled(): void
    {
        config(['neev.tenant_auth' => false]);

        $team = TeamFactory::new()->create();

        TeamAuthSettingsFactory::new()->sso()->create([
            'team_id' => $team->id,
        ]);

        // Even with SSO settings, disabled tenant_auth means password
        $this->assertFalse($team->requiresSSO());
    }

    // -----------------------------------------------------------------
    // allowsPassword()
    // -----------------------------------------------------------------

    public function test_allows_password_returns_true_when_method_is_password(): void
    {
        $this->enableTenantAuth();

        $team = TeamFactory::new()->create();

        TeamAuthSettingsFactory::new()->create([
            'team_id' => $team->id,
            'auth_method' => 'password',
        ]);

        $team->load('authSettings');

        $this->assertTrue($team->allowsPassword());
    }

    public function test_allows_password_returns_false_when_method_is_sso(): void
    {
        $this->enableTenantAuth();

        $team = TeamFactory::new()->create();

        TeamAuthSettingsFactory::new()->sso()->create([
            'team_id' => $team->id,
        ]);

        $team->load('authSettings');

        $this->assertFalse($team->allowsPassword());
    }

    public function test_allows_password_returns_true_when_tenant_auth_disabled(): void
    {
        config(['neev.tenant_auth' => false]);

        $team = TeamFactory::new()->create();

        // Even with SSO configured, disabled tenant_auth defaults to password
        $this->assertTrue($team->allowsPassword());
    }

    // -----------------------------------------------------------------
    // hasSSOConfigured()
    // -----------------------------------------------------------------

    public function test_has_sso_configured_returns_false_when_no_settings(): void
    {
        $team = TeamFactory::new()->create();

        $this->assertFalse($team->hasSSOConfigured());
    }

    public function test_has_sso_configured_returns_true_when_fully_configured(): void
    {
        $team = TeamFactory::new()->create();

        TeamAuthSettingsFactory::new()->sso()->create([
            'team_id' => $team->id,
        ]);

        $team->load('authSettings');

        $this->assertTrue($team->hasSSOConfigured());
    }

    public function test_has_sso_configured_returns_false_when_method_is_password(): void
    {
        $team = TeamFactory::new()->create();

        TeamAuthSettingsFactory::new()->create([
            'team_id' => $team->id,
            'auth_method' => 'password',
        ]);

        $team->load('authSettings');

        $this->assertFalse($team->hasSSOConfigured());
    }

    public function test_has_sso_configured_returns_false_when_missing_client_id(): void
    {
        $team = TeamFactory::new()->create();

        TeamAuthSettingsFactory::new()->create([
            'team_id' => $team->id,
            'auth_method' => 'sso',
            'sso_provider' => 'entra',
            'sso_client_id' => null,
            'sso_client_secret' => 'test-secret',
        ]);

        $team->load('authSettings');

        $this->assertFalse($team->hasSSOConfigured());
    }

    // -----------------------------------------------------------------
    // getSSOProvider()
    // -----------------------------------------------------------------

    public function test_get_sso_provider_returns_provider_from_settings(): void
    {
        $team = TeamFactory::new()->create();

        TeamAuthSettingsFactory::new()->sso('google')->create([
            'team_id' => $team->id,
        ]);

        $team->load('authSettings');

        $this->assertEquals('google', $team->getSSOProvider());
    }

    public function test_get_sso_provider_returns_null_when_no_settings(): void
    {
        $team = TeamFactory::new()->create();

        $this->assertNull($team->getSSOProvider());
    }

    // -----------------------------------------------------------------
    // allowsAutoProvision()
    // -----------------------------------------------------------------

    public function test_allows_auto_provision_returns_false_when_tenant_auth_disabled(): void
    {
        config(['neev.tenant_auth' => false]);

        $team = TeamFactory::new()->create();

        TeamAuthSettingsFactory::new()->autoProvision()->create([
            'team_id' => $team->id,
        ]);

        $team->load('authSettings');

        $this->assertFalse($team->allowsAutoProvision());
    }

    public function test_allows_auto_provision_returns_setting_value_when_enabled(): void
    {
        $this->enableTenantAuth();

        $team = TeamFactory::new()->create();

        TeamAuthSettingsFactory::new()->autoProvision()->create([
            'team_id' => $team->id,
        ]);

        $team->load('authSettings');

        $this->assertTrue($team->allowsAutoProvision());
    }

    public function test_allows_auto_provision_returns_false_when_not_configured(): void
    {
        $this->enableTenantAuth();

        $team = TeamFactory::new()->create();

        TeamAuthSettingsFactory::new()->create([
            'team_id' => $team->id,
            'auto_provision' => false,
        ]);

        $team->load('authSettings');

        $this->assertFalse($team->allowsAutoProvision());
    }

    public function test_allows_auto_provision_falls_back_to_config_when_no_settings(): void
    {
        $this->enableTenantAuth();
        config(['neev.tenant_auth_options.auto_provision' => true]);

        $team = TeamFactory::new()->create();

        $this->assertTrue($team->allowsAutoProvision());
    }

    public function test_allows_auto_provision_config_fallback_defaults_to_false(): void
    {
        $this->enableTenantAuth();

        $team = TeamFactory::new()->create();

        $this->assertFalse($team->allowsAutoProvision());
    }

    // -----------------------------------------------------------------
    // getAutoProvisionRole()
    // -----------------------------------------------------------------

    public function test_get_auto_provision_role_returns_role_from_settings(): void
    {
        $team = TeamFactory::new()->create();

        TeamAuthSettingsFactory::new()->autoProvision('editor')->create([
            'team_id' => $team->id,
        ]);

        $team->load('authSettings');

        $this->assertEquals('editor', $team->getAutoProvisionRole());
    }

    public function test_get_auto_provision_role_falls_back_to_config(): void
    {
        config(['neev.tenant_auth_options.auto_provision_role' => 'viewer']);

        $team = TeamFactory::new()->create();

        $this->assertEquals('viewer', $team->getAutoProvisionRole());
    }

    public function test_get_auto_provision_role_returns_null_when_no_settings_and_no_config(): void
    {
        $team = TeamFactory::new()->create();

        $this->assertNull($team->getAutoProvisionRole());
    }

    // -----------------------------------------------------------------
    // getSocialiteConfig()
    // -----------------------------------------------------------------

    public function test_get_socialite_config_returns_null_when_no_settings(): void
    {
        $team = TeamFactory::new()->create();

        $this->assertNull($team->getSocialiteConfig());
    }

    public function test_get_socialite_config_returns_config_array_when_settings_exist(): void
    {
        // Register the sso.callback route since SSO routes are only loaded when tenant_auth is enabled at boot
        \Illuminate\Support\Facades\Route::get('/sso/callback', fn () => null)->name('sso.callback');

        $team = TeamFactory::new()->create();

        TeamAuthSettingsFactory::new()->sso()->create([
            'team_id' => $team->id,
        ]);

        $team->load('authSettings');

        $config = $team->getSocialiteConfig();

        $this->assertIsArray($config);
        $this->assertArrayHasKey('client_id', $config);
        $this->assertArrayHasKey('client_secret', $config);
        $this->assertArrayHasKey('redirect', $config);
    }
}
