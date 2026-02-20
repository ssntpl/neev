<?php

namespace Ssntpl\Neev;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Ssntpl\Neev\Commands\CleanOldLoginAttempts;
use Ssntpl\Neev\Commands\CleanOldPasswords;
use Ssntpl\Neev\Commands\DownloadGeoLiteDb;
use Ssntpl\Neev\Commands\InstallNeev;
use Ssntpl\Neev\Http\Middleware\BindContextMiddleware;
use Ssntpl\Neev\Http\Middleware\EnsureContextSSO;
use Ssntpl\Neev\Http\Middleware\EnsureTeamIsActive;
use Ssntpl\Neev\Http\Middleware\EnsureTenantMembership;
use Ssntpl\Neev\Http\Middleware\NeevAPIMiddleware;
use Ssntpl\Neev\Http\Middleware\NeevMiddleware;
use Ssntpl\Neev\Http\Middleware\ResolveTeamMiddleware;
use Ssntpl\Neev\Http\Middleware\TenantMiddleware;
use Ssntpl\Neev\Services\ContextManager;
use Ssntpl\Neev\Services\TenantResolver;
use Ssntpl\Neev\Services\TenantSSOManager;

class NeevServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middlewareGroup('neev:web', [
            TenantMiddleware::class,
            ResolveTeamMiddleware::class,
            NeevMiddleware::class,
            EnsureTenantMembership::class,
            BindContextMiddleware::class,
        ]);

        Route::middlewareGroup('neev:api', [
            TenantMiddleware::class,
            ResolveTeamMiddleware::class,
            NeevAPIMiddleware::class,
            EnsureTenantMembership::class,
            BindContextMiddleware::class,
        ]);

        Route::middlewareGroup('neev:tenant', [
            TenantMiddleware::class . ':required',
            ResolveTeamMiddleware::class,
            BindContextMiddleware::class,
        ]);

        Route::aliasMiddleware('neev:active-team', EnsureTeamIsActive::class);
        Route::aliasMiddleware('neev:tenant-member', EnsureTenantMembership::class);
        Route::aliasMiddleware('neev:resolve-team', ResolveTeamMiddleware::class);
        Route::aliasMiddleware('neev:ensure-sso', EnsureContextSSO::class);

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

            __DIR__.'/../database/migrations/2025_01_01_000005a_create_tenants_table.php' => database_path('migrations/2025_01_01_000005a_create_tenants_table.php'),

            __DIR__.'/../database/migrations/2025_01_01_000011_create_team_auth_settings_table.php' => database_path('migrations/2025_01_01_000011_create_team_auth_settings_table.php'),
            __DIR__.'/../database/migrations/2025_01_01_000012_create_tenant_auth_settings_table.php' => database_path('migrations/2025_01_01_000012_create_tenant_auth_settings_table.php'),
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

        if (config('neev.tenant_auth')) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/sso.php');
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'neev');

        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'neev');

        Blade::anonymousComponentPath(
            file_exists(resource_path('views/vendor/neev/components'))
                ? resource_path('views/vendor/neev/components')
                : __DIR__.'/../resources/views/components',
            'neev-component'
        );

        Blade::anonymousComponentPath(
            file_exists(resource_path('views/vendor/neev/layouts'))
                ? resource_path('views/vendor/neev/layouts')
                : __DIR__.'/../resources/views/layouts',
            'neev-layout'
        );
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/neev.php', 'neev');

        $this->app->singleton(ContextManager::class);
        $this->app->singleton(TenantResolver::class);
        $this->app->singleton(TenantSSOManager::class);

        $this->commands([
            InstallNeev::class,
            DownloadGeoLiteDb::class,
            CleanOldLoginAttempts::class,
            CleanOldPasswords::class,
        ]);
    }
}
