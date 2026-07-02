<?php

use Illuminate\Support\Facades\Route;
use Ssntpl\Neev\Http\Controllers\Auth\TenantSSOController;

/*
|--------------------------------------------------------------------------
| Tenant SSO Routes
|--------------------------------------------------------------------------
|
| These routes handle tenant-specific SSO authentication. They are always
| loaded; SSO behaviour is driven by each tenant's/team's auth settings
| (tenant_auth_settings / team_auth_settings tables).
|
*/

Route::prefix(config('neev.route_prefix', 'neev'))->group(function () {
    // Web routes for SSO flow — these URLs are registered as redirect
    // URIs at the identity providers.
    Route::middleware('web')->group(function () {
        Route::get('/sso/redirect', [TenantSSOController::class, 'redirect'])
            ->name('sso.redirect');
        Route::get('/sso/callback', [TenantSSOController::class, 'callback'])
            ->name('sso.callback');
    });

    // API route to get tenant auth configuration (public, no auth required)
    Route::get('/tenant/auth', [TenantSSOController::class, 'authConfig'])
        ->name('api.tenant.auth');
});
