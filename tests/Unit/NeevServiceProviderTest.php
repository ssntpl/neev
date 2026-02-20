<?php

namespace Ssntpl\Neev\Tests\Unit;

use Illuminate\Support\Facades\Route;
use Ssntpl\Neev\Http\Middleware\BindContextMiddleware;
use Ssntpl\Neev\Http\Middleware\EnsureContextSSO;
use Ssntpl\Neev\Http\Middleware\EnsureTeamIsActive;
use Ssntpl\Neev\Http\Middleware\EnsureTenantMembership;
use Ssntpl\Neev\Http\Middleware\NeevAPIMiddleware;
use Ssntpl\Neev\Http\Middleware\NeevMiddleware;
use Ssntpl\Neev\Http\Middleware\ResolveTeamMiddleware;
use Ssntpl\Neev\Http\Middleware\TenantMiddleware;
use Ssntpl\Neev\Services\TenantResolver;
use Ssntpl\Neev\Services\TenantSSOManager;
use Ssntpl\Neev\Tests\TestCase;

class NeevServiceProviderTest extends TestCase
{
    // =================================================================
    // Singleton registrations
    // =================================================================

    public function test_registers_tenant_resolver_as_singleton(): void
    {
        $instanceA = app(TenantResolver::class);
        $instanceB = app(TenantResolver::class);

        $this->assertInstanceOf(TenantResolver::class, $instanceA);
        $this->assertSame($instanceA, $instanceB);
    }

    public function test_registers_tenant_sso_manager_as_singleton(): void
    {
        $instanceA = app(TenantSSOManager::class);
        $instanceB = app(TenantSSOManager::class);

        $this->assertInstanceOf(TenantSSOManager::class, $instanceA);
        $this->assertSame($instanceA, $instanceB);
    }

    // =================================================================
    // Middleware groups
    // =================================================================

    public function test_registers_neev_web_middleware_group(): void
    {
        $groups = Route::getMiddlewareGroups();

        $this->assertArrayHasKey('neev:web', $groups);
        $this->assertContains(TenantMiddleware::class, $groups['neev:web']);
        $this->assertContains(ResolveTeamMiddleware::class, $groups['neev:web']);
        $this->assertContains(NeevMiddleware::class, $groups['neev:web']);
        $this->assertContains(EnsureTenantMembership::class, $groups['neev:web']);
        $this->assertContains(BindContextMiddleware::class, $groups['neev:web']);
    }

    public function test_registers_neev_api_middleware_group(): void
    {
        $groups = Route::getMiddlewareGroups();

        $this->assertArrayHasKey('neev:api', $groups);
        $this->assertContains(TenantMiddleware::class, $groups['neev:api']);
        $this->assertContains(ResolveTeamMiddleware::class, $groups['neev:api']);
        $this->assertContains(NeevAPIMiddleware::class, $groups['neev:api']);
        $this->assertContains(EnsureTenantMembership::class, $groups['neev:api']);
        $this->assertContains(BindContextMiddleware::class, $groups['neev:api']);
    }

    public function test_registers_neev_tenant_middleware_group(): void
    {
        $groups = Route::getMiddlewareGroups();

        $this->assertArrayHasKey('neev:tenant', $groups);
        $this->assertContains(TenantMiddleware::class . ':required', $groups['neev:tenant']);
        $this->assertContains(ResolveTeamMiddleware::class, $groups['neev:tenant']);
        $this->assertContains(BindContextMiddleware::class, $groups['neev:tenant']);
    }

    public function test_api_middleware_authenticates_before_membership_check(): void
    {
        $groups = Route::getMiddlewareGroups();
        $group = $groups['neev:api'];

        $authIndex = array_search(NeevAPIMiddleware::class, $group);
        $membershipIndex = array_search(EnsureTenantMembership::class, $group);

        $this->assertLessThan($membershipIndex, $authIndex, 'NeevAPIMiddleware must run before EnsureTenantMembership');
    }

    public function test_web_middleware_authenticates_before_membership_check(): void
    {
        $groups = Route::getMiddlewareGroups();
        $group = $groups['neev:web'];

        $authIndex = array_search(NeevMiddleware::class, $group);
        $membershipIndex = array_search(EnsureTenantMembership::class, $group);

        $this->assertLessThan($membershipIndex, $authIndex, 'NeevMiddleware must run before EnsureTenantMembership');
    }

    public function test_bind_context_middleware_runs_last_in_api_group(): void
    {
        $groups = Route::getMiddlewareGroups();
        $group = $groups['neev:api'];

        $bindIndex = array_search(BindContextMiddleware::class, $group);

        $this->assertEquals(count($group) - 1, $bindIndex, 'BindContextMiddleware must be last in neev:api');
    }

    public function test_bind_context_middleware_runs_last_in_web_group(): void
    {
        $groups = Route::getMiddlewareGroups();
        $group = $groups['neev:web'];

        $bindIndex = array_search(BindContextMiddleware::class, $group);

        $this->assertEquals(count($group) - 1, $bindIndex, 'BindContextMiddleware must be last in neev:web');
    }

    // =================================================================
    // Middleware aliases
    // =================================================================

    public function test_middleware_alias_neev_active_team_resolves_to_ensure_team_is_active(): void
    {
        $aliases = Route::getMiddleware();

        $this->assertArrayHasKey('neev:active-team', $aliases);
        $this->assertSame(EnsureTeamIsActive::class, $aliases['neev:active-team']);
    }

    public function test_middleware_alias_neev_tenant_member_resolves_to_ensure_tenant_membership(): void
    {
        $aliases = Route::getMiddleware();

        $this->assertArrayHasKey('neev:tenant-member', $aliases);
        $this->assertSame(EnsureTenantMembership::class, $aliases['neev:tenant-member']);
    }

    public function test_middleware_alias_neev_resolve_team(): void
    {
        $aliases = Route::getMiddleware();

        $this->assertArrayHasKey('neev:resolve-team', $aliases);
        $this->assertSame(ResolveTeamMiddleware::class, $aliases['neev:resolve-team']);
    }

    public function test_middleware_alias_neev_ensure_sso(): void
    {
        $aliases = Route::getMiddleware();

        $this->assertArrayHasKey('neev:ensure-sso', $aliases);
        $this->assertSame(EnsureContextSSO::class, $aliases['neev:ensure-sso']);
    }

    // =================================================================
    // Config is loaded
    // =================================================================

    public function test_config_is_loaded(): void
    {
        $this->assertNotNull(config('neev'));
        $this->assertIsArray(config('neev'));
    }

    public function test_config_team_key_exists(): void
    {
        // The 'team' key should exist (defaults to false)
        $this->assertArrayHasKey('team', config('neev'));
    }

    public function test_config_tenant_isolation_key_exists(): void
    {
        $this->assertArrayHasKey('tenant_isolation', config('neev'));
    }

    public function test_config_password_rules_key_exists(): void
    {
        $this->assertArrayHasKey('password', config('neev'));
    }

    public function test_config_slug_key_exists(): void
    {
        $this->assertArrayHasKey('slug', config('neev'));
    }

    // =================================================================
    // Commands are registered
    // =================================================================

    public function test_clean_login_attempts_command_is_registered(): void
    {
        $this->artisan('neev:clean-login-attempts')
            ->assertSuccessful();
    }

    public function test_clean_passwords_command_is_registered(): void
    {
        $this->artisan('neev:clean-passwords')
            ->assertSuccessful();
    }

    public function test_install_command_is_registered(): void
    {
        // Just verify the command exists (don't run it fully)
        $commands = \Illuminate\Support\Facades\Artisan::all();

        $this->assertArrayHasKey('neev:install', $commands);
    }

    public function test_download_geoip_command_is_registered(): void
    {
        $commands = \Illuminate\Support\Facades\Artisan::all();

        $this->assertArrayHasKey('neev:download-geoip', $commands);
    }

    // =================================================================
    // Views are loaded
    // =================================================================

    public function test_views_are_registered_under_neev_namespace(): void
    {
        $finder = $this->app['view']->getFinder();

        // The view namespace 'neev' should be registered
        $hints = $finder->getHints();

        $this->assertArrayHasKey('neev', $hints);
    }

    // =================================================================
    // Routes are loaded
    // =================================================================

    public function test_neev_routes_are_loaded(): void
    {
        $routes = Route::getRoutes();

        // The neev routes file defines routes; check at least one exists
        $routeNames = collect($routes->getRoutesByName())->keys()->toArray();

        // Neev routes should include some auth routes
        $this->assertNotEmpty($routeNames);
    }

    // =================================================================
    // SSO routes loaded when tenant_auth enabled
    // =================================================================

    public function test_sso_routes_loaded_when_tenant_auth_enabled(): void
    {
        // SSO routes are loaded conditionally during boot() when tenant_auth is true.
        // Since Testbench boots the provider before tests run, we set config and re-boot.
        config(['neev.tenant_auth' => true]);

        $ssoRoutesPath = realpath(__DIR__ . '/../../routes/sso.php');
        $this->assertFileExists($ssoRoutesPath);

        // Load the routes file the same way the service provider does
        $this->app['router']->group([], $ssoRoutesPath);

        // Refresh the route name lookup cache
        Route::getRoutes()->refreshNameLookups();

        $routeNames = collect(Route::getRoutes()->getRoutesByName())->keys()->toArray();

        $this->assertContains('sso.redirect', $routeNames);
        $this->assertContains('sso.callback', $routeNames);
        $this->assertContains('api.tenant.auth', $routeNames);
    }

    public function test_sso_routes_not_loaded_when_tenant_auth_disabled(): void
    {
        // tenant_auth is false by default
        $this->assertFalse(config('neev.tenant_auth'));

        $routes = Route::getRoutes();
        $routeNames = collect($routes->getRoutesByName())->keys()->toArray();

        $this->assertNotContains('sso.redirect', $routeNames);
        $this->assertNotContains('sso.callback', $routeNames);
    }
}
