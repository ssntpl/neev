<?php

namespace Ssntpl\Neev;

use Blade;
use Illuminate\Support\ServiceProvider;
use Route;
use Ssntpl\Neev\Commands\CleanOldLoginAttempts;
use Ssntpl\Neev\Commands\CleanOldPasswords;
use Ssntpl\Neev\Commands\CreatePermission;
use Ssntpl\Neev\Commands\CreateRole;
use Ssntpl\Neev\Commands\DownloadGeoLiteDb;
use Ssntpl\Neev\Commands\InstallNeev;
use Ssntpl\Neev\Http\Middleware\NeevAPIMiddleware;
use Ssntpl\Neev\Http\Middleware\NeevMiddleware;

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

        $this->publishes([
            __DIR__.'/../config/neev.php' => config_path('neev.php'),
        ], 'neev-config');

        $this->publishes([
            __DIR__.'/../database/migrations/create_users_table.php' => database_path('migrations/2025_01_01_000001_create_users_table.php'),
            __DIR__.'/../database/migrations/create_otp_table.php' => database_path('migrations/2025_01_01_000002_create_otp_table.php'),
            __DIR__.'/../database/migrations/create_passkeys_table.php' => database_path('migrations/2025_01_01_000003_create_passkeys_table.php'),
            __DIR__.'/../database/migrations/create_multi_factor_auths_table.php' => database_path('migrations/2025_01_01_000004_create_multi_factor_auths_table.php'),
            __DIR__.'/../database/migrations/create_recovery_codes_table.php' => database_path('migrations/2025_01_01_000005_create_recovery_codes_table.php'),
            __DIR__.'/../database/migrations/create_access_tokens_table.php' => database_path('migrations/2025_01_01_000006_create_access_tokens_table.php'),
        ], 'neev-migrations');
        
        $this->publishes([
            __DIR__.'/../database/migrations/create_teams_table.php' => database_path('migrations/2025_01_01_000007_create_teams_table.php'),
            __DIR__.'/../database/migrations/create_team_invitations_table.php' => database_path('migrations/2025_01_01_000008_create_team_invitations_table.php'),
        ], 'neev-team-migrations');

        $this->publishes([
            __DIR__.'/../database/migrations/create_domain_rules_table.php' => database_path('migrations/2025_01_01_000009_create_domain_rules_table.php'),
        ], 'neev-domain-federation-migrations');

        
        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/neev'),
        ], 'neev-views');
        
        $this->publishes([
            __DIR__.'/../routes/neev.php' => base_path('/routes/neev.php'),
        ], 'neev-routes');

        $this->loadRoutesFrom(
            file_exists(base_path('routes/neev.php'))
                ? base_path('routes/neev.php')
                : __DIR__ . '/../routes/neev.php'
        );

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'neev');

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
        $this->commands([
            InstallNeev::class,
            DownloadGeoLiteDb::class,
            CleanOldLoginAttempts::class,
            CreatePermission::class,
            CleanOldPasswords::class,
            CreateRole::class,
        ]);
    }
}
