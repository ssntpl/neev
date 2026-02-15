<?php

namespace Ssntpl\Neev\Tests\Unit\Models;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Ssntpl\Neev\Database\Factories\TeamAuthSettingsFactory;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Tests\TestCase;

class TeamAuthSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Set a valid 32-byte key for the encrypted cast on sso_client_secret
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
    }

    /**
     * Helper to create a team for use with TeamAuthSettingsFactory.
     */
    private function createTeam(): Team
    {
        return TeamFactory::new()->create();
    }

    // -----------------------------------------------------------------
    // isSSO()
    // -----------------------------------------------------------------

    public function test_is_sso_returns_true_when_auth_method_is_sso(): void
    {
        $team = $this->createTeam();
        $settings = TeamAuthSettingsFactory::new()->sso()->create(['team_id' => $team->id]);

        $this->assertTrue($settings->isSSO());
    }

    public function test_is_sso_returns_false_when_auth_method_is_password(): void
    {
        $team = $this->createTeam();
        $settings = TeamAuthSettingsFactory::new()->create(['team_id' => $team->id]);

        $this->assertFalse($settings->isSSO());
    }

    // -----------------------------------------------------------------
    // isPassword()
    // -----------------------------------------------------------------

    public function test_is_password_returns_true_when_auth_method_is_password(): void
    {
        $team = $this->createTeam();
        $settings = TeamAuthSettingsFactory::new()->create(['team_id' => $team->id]);

        $this->assertTrue($settings->isPassword());
    }

    public function test_is_password_returns_false_when_auth_method_is_sso(): void
    {
        $team = $this->createTeam();
        $settings = TeamAuthSettingsFactory::new()->sso()->create(['team_id' => $team->id]);

        $this->assertFalse($settings->isPassword());
    }

    // -----------------------------------------------------------------
    // hasSSOConfigured()
    // -----------------------------------------------------------------

    public function test_has_sso_configured_returns_true_when_all_fields_filled(): void
    {
        $team = $this->createTeam();
        $settings = TeamAuthSettingsFactory::new()->sso()->create(['team_id' => $team->id]);

        $this->assertTrue($settings->hasSSOConfigured());
    }

    public function test_has_sso_configured_returns_false_when_auth_method_is_password(): void
    {
        $team = $this->createTeam();
        $settings = TeamAuthSettingsFactory::new()->create(['team_id' => $team->id]);

        $this->assertFalse($settings->hasSSOConfigured());
    }

    public function test_has_sso_configured_returns_false_when_missing_provider(): void
    {
        $team = $this->createTeam();
        $settings = TeamAuthSettingsFactory::new()->create([
            'team_id' => $team->id,
            'auth_method' => 'sso',
            'sso_provider' => null,
            'sso_client_id' => 'test-id',
            'sso_client_secret' => 'test-secret',
        ]);

        $this->assertFalse($settings->hasSSOConfigured());
    }

    public function test_has_sso_configured_returns_false_when_missing_client_id(): void
    {
        $team = $this->createTeam();
        $settings = TeamAuthSettingsFactory::new()->create([
            'team_id' => $team->id,
            'auth_method' => 'sso',
            'sso_provider' => 'entra',
            'sso_client_id' => null,
            'sso_client_secret' => 'test-secret',
        ]);

        $this->assertFalse($settings->hasSSOConfigured());
    }

    public function test_has_sso_configured_returns_false_when_missing_client_secret(): void
    {
        $team = $this->createTeam();
        $settings = TeamAuthSettingsFactory::new()->create([
            'team_id' => $team->id,
            'auth_method' => 'sso',
            'sso_provider' => 'entra',
            'sso_client_id' => 'test-id',
            'sso_client_secret' => null,
        ]);

        $this->assertFalse($settings->hasSSOConfigured());
    }

    // -----------------------------------------------------------------
    // getSocialiteConfig()
    // -----------------------------------------------------------------

    public function test_get_socialite_config_builds_correct_array(): void
    {
        Route::get('/sso/callback', fn () => 'callback')->name('sso.callback');

        $team = $this->createTeam();
        $settings = TeamAuthSettingsFactory::new()->sso('google')->create([
            'team_id' => $team->id,
            'sso_client_id' => 'google-client-id',
            'sso_client_secret' => 'google-client-secret',
            'sso_tenant_id' => null,
        ]);

        $config = $settings->getSocialiteConfig();

        $this->assertIsArray($config);
        $this->assertSame('google-client-id', $config['client_id']);
        $this->assertSame('google-client-secret', $config['client_secret']);
        $this->assertArrayHasKey('redirect', $config);
        $this->assertStringContainsString('sso/callback', $config['redirect']);
    }

    public function test_get_socialite_config_includes_tenant_for_entra_provider(): void
    {
        Route::get('/sso/callback', fn () => 'callback')->name('sso.callback');

        $team = $this->createTeam();
        $settings = TeamAuthSettingsFactory::new()->sso('entra')->create([
            'team_id' => $team->id,
            'sso_tenant_id' => 'my-entra-tenant-id',
        ]);

        $config = $settings->getSocialiteConfig();

        $this->assertArrayHasKey('tenant', $config);
        $this->assertSame('my-entra-tenant-id', $config['tenant']);
    }

    public function test_get_socialite_config_does_not_include_tenant_for_non_entra_provider(): void
    {
        Route::get('/sso/callback', fn () => 'callback')->name('sso.callback');

        $team = $this->createTeam();
        $settings = TeamAuthSettingsFactory::new()->sso('google')->create([
            'team_id' => $team->id,
            'sso_tenant_id' => 'some-tenant-id',
        ]);

        $config = $settings->getSocialiteConfig();

        $this->assertArrayNotHasKey('tenant', $config);
    }

    public function test_get_socialite_config_merges_extra_config(): void
    {
        Route::get('/sso/callback', fn () => 'callback')->name('sso.callback');

        $team = $this->createTeam();
        $settings = TeamAuthSettingsFactory::new()->sso('okta')->create([
            'team_id' => $team->id,
            'sso_extra_config' => [
                'base_url' => 'https://mycompany.okta.com',
                'custom_param' => 'value',
            ],
        ]);

        $config = $settings->getSocialiteConfig();

        $this->assertArrayHasKey('base_url', $config);
        $this->assertSame('https://mycompany.okta.com', $config['base_url']);
        $this->assertArrayHasKey('custom_param', $config);
        $this->assertSame('value', $config['custom_param']);
    }

    // -----------------------------------------------------------------
    // allowsAutoProvision()
    // -----------------------------------------------------------------

    public function test_allows_auto_provision_returns_true_when_enabled(): void
    {
        $team = $this->createTeam();
        $settings = TeamAuthSettingsFactory::new()->autoProvision()->create(['team_id' => $team->id]);

        $this->assertTrue($settings->allowsAutoProvision());
    }

    public function test_allows_auto_provision_returns_false_when_disabled(): void
    {
        $team = $this->createTeam();
        $settings = TeamAuthSettingsFactory::new()->create([
            'team_id' => $team->id,
            'auto_provision' => false,
        ]);

        $this->assertFalse($settings->allowsAutoProvision());
    }

    // -----------------------------------------------------------------
    // getAutoProvisionRole()
    // -----------------------------------------------------------------

    public function test_get_auto_provision_role_returns_role(): void
    {
        $team = $this->createTeam();
        $settings = TeamAuthSettingsFactory::new()->autoProvision('editor')->create(['team_id' => $team->id]);

        $this->assertSame('editor', $settings->getAutoProvisionRole());
    }

    public function test_get_auto_provision_role_returns_null_when_not_set(): void
    {
        $team = $this->createTeam();
        $settings = TeamAuthSettingsFactory::new()->create([
            'team_id' => $team->id,
            'auto_provision_role' => null,
        ]);

        $this->assertNull($settings->getAutoProvisionRole());
    }

    // -----------------------------------------------------------------
    // sso_client_secret encrypted cast
    // -----------------------------------------------------------------

    public function test_sso_client_secret_is_encrypted_in_database(): void
    {
        $team = $this->createTeam();
        $plainSecret = 'my-super-secret-key';

        $settings = TeamAuthSettingsFactory::new()->sso()->create([
            'team_id' => $team->id,
            'sso_client_secret' => $plainSecret,
        ]);

        // Read the raw value from the database
        $rawValue = DB::table('team_auth_settings')
            ->where('id', $settings->id)
            ->value('sso_client_secret');

        // Raw value should NOT match plaintext (it should be encrypted)
        $this->assertNotSame($plainSecret, $rawValue);

        // But the model should decrypt it back
        $settings->refresh();
        $this->assertSame($plainSecret, $settings->sso_client_secret);
    }

    // -----------------------------------------------------------------
    // Relationship: team()
    // -----------------------------------------------------------------

    public function test_team_relationship(): void
    {
        $team = $this->createTeam();
        $settings = TeamAuthSettingsFactory::new()->create(['team_id' => $team->id]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class, $settings->team());
        $this->assertInstanceOf(Team::class, $settings->team);
        $this->assertSame($team->id, $settings->team->id);
    }
}
