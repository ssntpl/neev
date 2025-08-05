<?php

namespace Ssntpl\Neev;

use Blade;
use Illuminate\Support\ServiceProvider;
use Ssntpl\Neev\Commands\CleanupOldLoginHistory;
use Ssntpl\Neev\Commands\DownloadGeoLiteDb;
use Ssntpl\Neev\Commands\InstallNeev;

class NeevServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->publishes([
            __DIR__.'/../config/neev.php' => config_path('neev.php'),
        ], 'neev-config');

        $this->publishes([
            __DIR__ . '/../database/migrations/create_login_history_table.php' => database_path('migrations/2025_01_01_000000_create_login_history_table.php'),
            __DIR__.'/../database/migrations/create_otp_table.php' => database_path('migrations/2025_01_01_000001_create_otp_table.php'),
            __DIR__.'/../database/migrations/create_emails_table.php' => database_path('migrations/2025_01_01_000004_create_emails_table.php'),
            __DIR__.'/../database/migrations/create_passkeys_table.php' => database_path('migrations/2025_01_01_000005_create_passkeys_table.php'),
        ], 'neev-migrations');

        $this->publishes([
            __DIR__.'/../database/migrations/create_teams_table.php' => database_path('migrations/2025_01_01_000002_create_teams_table.php'),
        ], 'neev-team-migrations');
        
        $this->publishes([
            __DIR__.'/../database/migrations/create_roles_table.php' => database_path('migrations/2025_01_01_000003_create_role_table.php'),
        ], 'neev-role-migrations');

        $this->loadRoutesFrom(__DIR__.'/../routes/neev.php');
        
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'neev');
        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'neev-component');
        Blade::anonymousComponentPath(__DIR__.'/../resources/views/layouts', 'neev-layout');
    }

    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/neev.php', 'neev');
        $this->commands([InstallNeev::class, DownloadGeoLiteDb::class, CleanupOldLoginHistory::class]);
    }
}
