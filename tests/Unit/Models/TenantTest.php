<?php

namespace Ssntpl\Neev\Tests\Unit\Models;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Database\Factories\TenantAuthSettingsFactory;
use Ssntpl\Neev\Database\Factories\TenantFactory;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\Tenant;
use Ssntpl\Neev\Models\TenantAuthSettings;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;

class TenantTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
    }

    // -----------------------------------------------------------------
    // model() & getClass()
    // -----------------------------------------------------------------

    public function test_model_returns_configured_model_instance(): void
    {
        $instance = Tenant::model();

        $this->assertInstanceOf(Tenant::class, $instance);
    }

    public function test_get_class_returns_configured_class_string(): void
    {
        $class = Tenant::getClass();

        $this->assertSame(Tenant::class, $class);
    }

    public function test_model_respects_config_override(): void
    {
        config(['neev.tenant_model' => Tenant::class]);

        $this->assertSame(Tenant::class, Tenant::getClass());
    }

    // -----------------------------------------------------------------
    // CRUD
    // -----------------------------------------------------------------

    public function test_can_create_tenant(): void
    {
        $tenant = Tenant::create([
            'name' => 'Acme Corp',
            'slug' => 'acme-corp',
        ]);

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'name' => 'Acme Corp',
            'slug' => 'acme-corp',
        ]);
    }

    public function test_factory_creates_valid_tenant(): void
    {
        $tenant = TenantFactory::new()->create();

        $this->assertNotNull($tenant->id);
        $this->assertNotEmpty($tenant->name);
        $this->assertNotEmpty($tenant->slug);
    }

    public function test_slug_is_unique(): void
    {
        TenantFactory::new()->create(['slug' => 'unique-slug']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        TenantFactory::new()->create(['slug' => 'unique-slug']);
    }

    // -----------------------------------------------------------------
    // teams() relationship
    // -----------------------------------------------------------------

    public function test_teams_returns_has_many_relationship(): void
    {
        $tenant = TenantFactory::new()->create();

        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Relations\HasMany::class,
            $tenant->teams()
        );
    }

    public function test_teams_returns_teams_belonging_to_tenant(): void
    {
        $tenant = TenantFactory::new()->create();

        $team1 = TeamFactory::new()->create(['tenant_id' => $tenant->id]);
        $team2 = TeamFactory::new()->create(['tenant_id' => $tenant->id]);
        TeamFactory::new()->create(); // unrelated team

        $this->assertCount(2, $tenant->teams);
        $this->assertTrue($tenant->teams->contains($team1));
        $this->assertTrue($tenant->teams->contains($team2));
    }

    // -----------------------------------------------------------------
    // ContextContainerInterface
    // -----------------------------------------------------------------

    public function test_get_context_id_returns_id(): void
    {
        $tenant = TenantFactory::new()->create();

        $this->assertSame($tenant->id, $tenant->getContextId());
    }

    public function test_get_context_slug_returns_slug(): void
    {
        $tenant = TenantFactory::new()->create(['slug' => 'my-tenant']);

        $this->assertSame('my-tenant', $tenant->getContextSlug());
    }

    // -----------------------------------------------------------------
    // authSettings() relationship
    // -----------------------------------------------------------------

    public function test_auth_settings_returns_has_one_relationship(): void
    {
        $tenant = TenantFactory::new()->create();

        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Relations\HasOne::class,
            $tenant->authSettings()
        );
    }

    public function test_auth_settings_returns_related_settings(): void
    {
        $tenant = TenantFactory::new()->create();
        $settings = TenantAuthSettingsFactory::new()->create(['tenant_id' => $tenant->id]);

        $this->assertInstanceOf(TenantAuthSettings::class, $tenant->authSettings);
        $this->assertTrue($tenant->authSettings->is($settings));
    }

    public function test_auth_settings_returns_null_when_none_exist(): void
    {
        $tenant = TenantFactory::new()->create();

        $this->assertNull($tenant->authSettings);
    }

    // -----------------------------------------------------------------
    // IdentityProviderOwnerInterface
    // -----------------------------------------------------------------

    public function test_get_auth_method_returns_config_default(): void
    {
        $tenant = TenantFactory::new()->create();

        $this->assertSame('password', $tenant->getAuthMethod());
    }

    public function test_get_auth_method_returns_auth_settings_value(): void
    {
        $tenant = TenantFactory::new()->create();
        TenantAuthSettingsFactory::new()->sso()->create(['tenant_id' => $tenant->id]);

        $this->assertSame('sso', $tenant->fresh()->getAuthMethod());
    }

    public function test_requires_sso_returns_false_by_default(): void
    {
        $tenant = TenantFactory::new()->create();

        $this->assertFalse($tenant->requiresSSO());
    }

    public function test_requires_sso_returns_true_when_config_is_sso(): void
    {
        config(['neev.tenant_auth_options.default_method' => 'sso']);

        $tenant = TenantFactory::new()->create();

        $this->assertTrue($tenant->requiresSSO());
    }

    public function test_requires_sso_returns_true_when_auth_settings_is_sso(): void
    {
        $tenant = TenantFactory::new()->create();
        TenantAuthSettingsFactory::new()->sso()->create(['tenant_id' => $tenant->id]);

        $this->assertTrue($tenant->fresh()->requiresSSO());
    }

    public function test_has_sso_configured_returns_false_without_settings(): void
    {
        $tenant = TenantFactory::new()->create();

        $this->assertFalse($tenant->hasSSOConfigured());
    }

    public function test_has_sso_configured_delegates_to_auth_settings(): void
    {
        $tenant = TenantFactory::new()->create();
        TenantAuthSettingsFactory::new()->sso()->create(['tenant_id' => $tenant->id]);

        $this->assertTrue($tenant->fresh()->hasSSOConfigured());
    }

    public function test_get_sso_provider_returns_null_without_settings(): void
    {
        $tenant = TenantFactory::new()->create();

        $this->assertNull($tenant->getSSOProvider());
    }

    public function test_get_sso_provider_delegates_to_auth_settings(): void
    {
        $tenant = TenantFactory::new()->create();
        TenantAuthSettingsFactory::new()->sso('google')->create(['tenant_id' => $tenant->id]);

        $this->assertSame('google', $tenant->fresh()->getSSOProvider());
    }

    public function test_get_socialite_config_returns_null_without_settings(): void
    {
        $tenant = TenantFactory::new()->create();

        $this->assertNull($tenant->getSocialiteConfig());
    }

    public function test_get_socialite_config_delegates_to_auth_settings(): void
    {
        Route::get('/sso/callback', fn () => 'callback')->name('sso.callback');

        $tenant = TenantFactory::new()->create();
        TenantAuthSettingsFactory::new()->sso()->create([
            'tenant_id' => $tenant->id,
            'sso_client_id' => 'my-client-id',
            'sso_client_secret' => 'my-client-secret',
        ]);

        $config = $tenant->fresh()->getSocialiteConfig();

        $this->assertIsArray($config);
        $this->assertSame('my-client-id', $config['client_id']);
    }

    public function test_allows_auto_provision_returns_config_value(): void
    {
        $tenant = TenantFactory::new()->create();

        $this->assertFalse($tenant->allowsAutoProvision());

        config(['neev.tenant_auth_options.auto_provision' => true]);

        $this->assertTrue($tenant->allowsAutoProvision());
    }

    public function test_allows_auto_provision_delegates_to_auth_settings(): void
    {
        $tenant = TenantFactory::new()->create();
        TenantAuthSettingsFactory::new()->autoProvision()->create(['tenant_id' => $tenant->id]);

        $this->assertTrue($tenant->fresh()->allowsAutoProvision());
    }

    public function test_get_auto_provision_role_returns_config_value(): void
    {
        $tenant = TenantFactory::new()->create();

        $this->assertNull($tenant->getAutoProvisionRole());

        config(['neev.tenant_auth_options.auto_provision_role' => 'member']);

        $this->assertSame('member', $tenant->getAutoProvisionRole());
    }

    public function test_get_auto_provision_role_delegates_to_auth_settings(): void
    {
        $tenant = TenantFactory::new()->create();
        TenantAuthSettingsFactory::new()->autoProvision('admin')->create(['tenant_id' => $tenant->id]);

        $this->assertSame('admin', $tenant->fresh()->getAutoProvisionRole());
    }

    // -----------------------------------------------------------------
    // HasMembersInterface — hasMember()
    // -----------------------------------------------------------------

    public function test_has_member_returns_true_for_joined_user_in_tenant_team(): void
    {
        $tenant = TenantFactory::new()->create();
        $team = TeamFactory::new()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create();

        $team->allUsers()->attach($user->id, [
            'role' => 'member',
            'joined' => true,
            'action' => 'request_to_user',
        ]);

        $this->assertTrue($tenant->hasMember($user));
    }

    public function test_has_member_returns_false_for_non_member(): void
    {
        $tenant = TenantFactory::new()->create();
        TeamFactory::new()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create();

        $this->assertFalse($tenant->hasMember($user));
    }

    public function test_has_member_returns_false_for_non_joined_user(): void
    {
        $tenant = TenantFactory::new()->create();
        $team = TeamFactory::new()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create();

        $team->allUsers()->attach($user->id, [
            'role' => 'member',
            'joined' => false,
            'action' => 'request_to_user',
        ]);

        $this->assertFalse($tenant->hasMember($user));
    }

    // -----------------------------------------------------------------
    // ResolvableContextInterface
    // -----------------------------------------------------------------

    public function test_resolve_by_slug_returns_tenant(): void
    {
        $tenant = TenantFactory::new()->create(['slug' => 'acme']);

        $resolved = Tenant::resolveBySlug('acme');

        $this->assertNotNull($resolved);
        $this->assertTrue($resolved->is($tenant));
    }

    public function test_resolve_by_slug_returns_null_when_not_found(): void
    {
        $this->assertNull(Tenant::resolveBySlug('nonexistent'));
    }

    public function test_resolve_by_domain_returns_null(): void
    {
        // Tenant domain ownership is introduced in Phase 3
        $this->assertNull(Tenant::resolveByDomain('example.com'));
    }
}
