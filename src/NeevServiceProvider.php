<?php

namespace Ssntpl\Neev;

use Blade;
use Illuminate\Support\ServiceProvider;
use Route;
use Ssntpl\Neev\Commands\CleanOldLoginAttempts;
use Ssntpl\Neev\Commands\CleanOldPasswords;
use Ssntpl\Neev\Commands\DownloadGeoLiteDb;
use Ssntpl\Neev\Commands\InstallNeev;
use Ssntpl\Neev\Http\Middleware\EnsureTeamIsActive;
use Ssntpl\Neev\Http\Middleware\EnsureTenantMembership;
use Ssntpl\Neev\Http\Middleware\NeevAPIMiddleware;
use Ssntpl\Neev\Http\Middleware\NeevMiddleware;
use Ssntpl\Neev\Http\Middleware\TenantMiddleware;
use Ssntpl\Neev\Services\TenantResolver;
use Ssntpl\Neev\Services\TenantSSOManager;

class NeevServiceProvider extends ServiceProvider
{
    public function boot()
    {
        Route::middlewareGroup('neev:web', [
            NeevMiddleware::class,
        ]);
        Route::middlewareGroup('neev:api', [
            NeevAPIMiddleware::class,
        ]);

        // Tenant isolation middleware groups
        Route::middlewareGroup('neev:tenant', [
            TenantMiddleware::class,
        ]);
        Route::middlewareGroup('neev:tenant-api', [
            TenantMiddleware::class,
            EnsureTenantMembership::class,
            NeevAPIMiddleware::class,
        ]);
        Route::middlewareGroup('neev:tenant-web', [
            TenantMiddleware::class,
            EnsureTenantMembership::class,
            NeevMiddleware::class,
        ]);

        // Team activation middleware - blocks access for waitlisted/inactive teams
        Route::aliasMiddleware('neev:active-team', EnsureTeamIsActive::class);

        // Tenant membership middleware - ensures user belongs to current tenant
        Route::aliasMiddleware('neev:tenant-member', EnsureTenantMembership::class);

        $this->publishes([
            __DIR__.'/../config/neev.php' => config_path('neev.php'),
        ], 'neev-config');

        $this->publishes([
            __DIR__.'/../database/migrations/2025_01_01_000001_create_users_table.php' => database_path('migrations/2025_01_01_000001_create_users_table.php'),
            __DIR__.'/../database/migrations/2025_01_01_000002_create_otp_table.php' => database_path('migrations/2025_01_01_000002_create_otp_table.php'),
            __DIR__.'/../database/migrations/2025_01_01_000003_create_passkeys_table.php' => database_path('migrations/2025_01_01_000003_create_passkeys_table.php'),
            __DIR__.'/../database/migrations/2025_01_01_000004_create_multi_factor_auths_table.php' => database_path('migrations/2025_01_01_000004_create_multi_factor_auths_table.php'),
            __DIR__.'/../database/migrations/2025_01_01_000005_create_recovery_codes_table.php' => database_path('migrations/2025_01_01_000005_create_recovery_codes_table.php'),
            __DIR__.'/../database/migrations/2025_01_01_000006_create_access_tokens_table.php' => database_path('migrations/2025_01_01_000006_create_access_tokens_table.php'),
            __DIR__.'/../database/migrations/2025_01_01_000007_create_teams_table.php' => database_path('migrations/2025_01_01_000007_create_teams_table.php'),
            __DIR__.'/../database/migrations/2025_01_01_000008_create_team_invitations_table.php' => database_path('migrations/2025_01_01_000008_create_team_invitations_table.php'),
            __DIR__.'/../database/migrations/2025_01_01_000009_create_domains_table.php' => database_path('migrations/2025_01_01_000009_create_domains_table.php'),

            __DIR__.'/../database/migrations/2025_01_01_000011_create_team_auth_settings_table.php' => database_path('migrations/2025_01_01_000011_create_team_auth_settings_table.php'),
        ], 'neev-migrations');
        
        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/neev'),
        ], 'neev-views');
        
        $this->publishes([
            __DIR__.'/../routes/neev.php' => base_path('/routes/neev.php'),
        ], 'neev-routes');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->loadRoutesFrom(
            file_exists(base_path('routes/neev.php'))
                ? base_path('routes/neev.php')
                : __DIR__ . '/../routes/neev.php'
        );

        // Always load SSO routes when tenant_auth is enabled
        if (config('neev.tenant_auth')) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/sso.php');
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'neev');

        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'neev');

        Blade::anonymousComponentPath(
            file_exists(resource_path('views/vendor/neev/components')) 
                ? resource_path('views/vendor/neev/components')
                : __DIR__.'/../resources/views/components', 
            'neev-component');

        Blade::anonymousComponentPath(
            file_exists(resource_path('views/vendor/neev/layouts'))
                ? resource_path('views/vendor/neev/layouts')
                : __DIR__.'/../resources/views/layouts', 
            'neev-layout');
    }

    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/neev.php', 'neev');

        // Register TenantResolver as a singleton
        $this->app->singleton(TenantResolver::class, function ($app) {
            return new TenantResolver();
        });

        // Register TenantSSOManager as a singleton
        $this->app->singleton(TenantSSOManager::class, function ($app) {
            return new TenantSSOManager();
        });

        $this->commands([
            InstallNeev::class,
            DownloadGeoLiteDb::class,
            CleanOldLoginAttempts::class,
            CleanOldPasswords::class,
        ]);
    }
}
