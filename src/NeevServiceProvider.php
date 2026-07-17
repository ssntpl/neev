<?php

namespace Ssntpl\Neev;

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Ssntpl\Neev\Commands\Auth\ConfigureAuthCommand;
use Ssntpl\Neev\Commands\Auth\ShowAuthCommand;
use Ssntpl\Neev\Commands\CleanExpiredMagicLinks;
use Ssntpl\Neev\Commands\CleanOldLoginAttempts;
use Ssntpl\Neev\Commands\CleanPendingMfaSetups;
use Ssntpl\Neev\Commands\Domain\AddDomainCommand;
use Ssntpl\Neev\Commands\Domain\ListDomainsCommand;
use Ssntpl\Neev\Commands\Domain\VerifyDomainCommand;
use Ssntpl\Neev\Commands\DownloadGeoLiteDb;
use Ssntpl\Neev\Commands\InstallNeev;
use Ssntpl\Neev\Commands\InstallUi;
use Ssntpl\Neev\Commands\Member\AddMemberCommand;
use Ssntpl\Neev\Commands\Member\ListMembersCommand;
use Ssntpl\Neev\Commands\Member\RemoveMemberCommand;
use Ssntpl\Neev\Commands\Team\ActivateTeamCommand;
use Ssntpl\Neev\Commands\Tenant\CreateTenantCommand;
use Ssntpl\Neev\Commands\Tenant\ListTenantsCommand;
use Ssntpl\Neev\Commands\Tenant\ShowTenantCommand;
use Ssntpl\Neev\Http\Middleware\BindContextMiddleware;
use Ssntpl\Neev\Http\Middleware\EnsureContextSSO;
use Ssntpl\Neev\Http\Middleware\EnsureEmailIsVerified;
use Ssntpl\Neev\Http\Middleware\EnsurePasswordNotExpired;
use Ssntpl\Neev\Http\Middleware\EnsureSpaRequestsAreStateful;
use Ssntpl\Neev\Http\Middleware\EnsureTeamIsActive;
use Ssntpl\Neev\Http\Middleware\EnsureTenantIsActive;
use Ssntpl\Neev\Http\Middleware\EnsureTenantMembership;
use Ssntpl\Neev\Http\Middleware\JwtLoginMiddleware;
use Ssntpl\Neev\Http\Middleware\NeevAPIMiddleware;
use Ssntpl\Neev\Http\Middleware\NeevMiddleware;
use Ssntpl\Neev\Http\Middleware\ResolveTeamMiddleware;
use Ssntpl\Neev\Http\Middleware\TenantMiddleware;
use Ssntpl\Neev\Services\ContextManager;
use Ssntpl\Neev\Services\MagicLink\MagicLinkManager;
use Ssntpl\Neev\Services\TenantResolver;
use Ssntpl\Neev\Services\TenantSSOManager;

class NeevServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // The SPA auth cookie must read identically whether it was set on a
        // plain API route or inside a 'web'-group redirect (OAuth/SSO
        // callbacks) — so it is never encrypted. It carries an already
        // opaque token; encryption adds nothing. The CSRF cookie is not
        // excepted here: its default name (XSRF-TOKEN) is shared with
        // Laravel's own web-session CSRF cookie, which must stay encrypted.
        EncryptCookies::except([
            config('neev.spa.cookie_name', 'neev_session'),
        ]);

        Relation::morphMap([
            'team' => \Ssntpl\Neev\Models\Team::getClass(),
            'tenant' => \Ssntpl\Neev\Models\Tenant::getClass(),
        ]);

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
            EnsureSpaRequestsAreStateful::class,
            NeevAPIMiddleware::class,
            EnsureTenantMembership::class,
            BindContextMiddleware::class,
        ]);

        // EnsureSpaRequestsAreStateful also runs here: the MFA verify step
        // is the one token-issuing route that must READ the SPA cookie
        // (it carries the step-up JWT between password and OTP).
        Route::middlewareGroup('neev:login', [
            TenantMiddleware::class,
            ResolveTeamMiddleware::class,
            EnsureSpaRequestsAreStateful::class,
            JwtLoginMiddleware::class,
            EnsureTenantMembership::class,
            BindContextMiddleware::class,
        ]);

        Route::middlewareGroup('neev:tenant', [
            TenantMiddleware::class . ':required',
            ResolveTeamMiddleware::class,
            BindContextMiddleware::class,
        ]);

        Route::aliasMiddleware('neev:active-team', EnsureTeamIsActive::class);
        Route::aliasMiddleware('neev:active-tenant', EnsureTenantIsActive::class);
        Route::aliasMiddleware('neev:tenant-member', EnsureTenantMembership::class);
        Route::aliasMiddleware('neev:resolve-team', ResolveTeamMiddleware::class);
        Route::aliasMiddleware('neev:ensure-sso', EnsureContextSSO::class);
        Route::aliasMiddleware('neev:password-not-expired', EnsurePasswordNotExpired::class);
        Route::aliasMiddleware('neev:verified-email', EnsureEmailIsVerified::class);

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

            __DIR__.'/../database/migrations/2025_01_01_000013_create_magic_link_tokens_table.php' => database_path('migrations/2025_01_01_000013_create_magic_link_tokens_table.php'),
        ], 'neev-migrations');

        // Blade starter kit: ejected into the app (app-owned from then on).
        $this->publishes([
            __DIR__.'/../stubs/blade/views' => resource_path('views/vendor/neev'),
        ], 'neev-blade-kit');

        // Email templates: ejected by the installer so the app owns them;
        // the package copies below remain as the headless fallback.
        $this->publishes([
            __DIR__.'/../resources/views/emails' => resource_path('views/vendor/neev/emails'),
        ], 'neev-mail');

        $this->publishes([
            __DIR__.'/../routes/neev.php' => base_path('/routes/neev.php'),
        ], 'neev-routes');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->loadRoutesFrom(
            file_exists(base_path('routes/neev.php'))
                ? base_path('routes/neev.php')
                : __DIR__ . '/../routes/neev.php'
        );

        $this->loadRoutesFrom(__DIR__ . '/../routes/sso.php');

        // The package view namespace holds only the email fallbacks; page
        // views live exclusively in the app once a kit is ejected
        // (resources/views/vendor/neev, which Laravel resolves first).
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'neev');

        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'neev');

        // Kit components/layouts are app-owned; only register the paths
        // when the kit has been ejected.
        if (is_dir(resource_path('views/vendor/neev/components'))) {
            Blade::anonymousComponentPath(resource_path('views/vendor/neev/components'), 'neev-component');
        }

        if (is_dir(resource_path('views/vendor/neev/layouts'))) {
            Blade::anonymousComponentPath(resource_path('views/vendor/neev/layouts'), 'neev-layout');
        }
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/neev.php', 'neev');

        $this->app->scoped(ContextManager::class);
        $this->app->scoped(TenantResolver::class);
        $this->app->singleton(TenantSSOManager::class);
        $this->app->singleton(MagicLinkManager::class);

        $this->commands([
            InstallNeev::class,
            InstallUi::class,
            DownloadGeoLiteDb::class,
            CleanOldLoginAttempts::class,
            CleanExpiredMagicLinks::class,
            CleanPendingMfaSetups::class,

            CreateTenantCommand::class,
            ListTenantsCommand::class,
            ShowTenantCommand::class,

            AddDomainCommand::class,
            VerifyDomainCommand::class,
            ListDomainsCommand::class,

            AddMemberCommand::class,
            RemoveMemberCommand::class,
            ListMembersCommand::class,

            ConfigureAuthCommand::class,
            ShowAuthCommand::class,

            ActivateTeamCommand::class,
        ]);
    }
}
