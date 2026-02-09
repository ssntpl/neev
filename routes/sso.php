<?php

use Illuminate\Support\Facades\Route;
use Ssntpl\Neev\Http\Controllers\Auth\TenantSSOController;

/*
|--------------------------------------------------------------------------
| Tenant SSO Routes
|--------------------------------------------------------------------------
|
| These routes handle tenant-specific SSO authentication. They are loaded
| automatically when tenant_auth is enabled in the neev config.
|
*/

// Web routes for SSO flow
Route::middleware('web')->group(function () {
    Route::get('/sso/redirect', [TenantSSOController::class, 'redirect'])
        ->name('sso.redirect');
    Route::get('/sso/callback', [TenantSSOController::class, 'callback'])
        ->name('sso.callback');
});

// API route to get tenant auth configuration (public, no auth required)
Route::get('/api/tenant/auth', [TenantSSOController::class, 'authConfig'])
    ->name('api.tenant.auth');
