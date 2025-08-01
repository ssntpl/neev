<?php

use Illuminate\Support\Facades\Route;
use Ssntpl\Neev\Http\Controllers\Auth\UserAuthController;
use Ssntpl\Neev\Http\Controllers\ManagementController;
use Ssntpl\Neev\Http\Controllers\RoleController;
use Ssntpl\Neev\Http\Controllers\TeamController;
use Ssntpl\Neev\Http\Controllers\UserController;

Route::middleware('web')->group(function () {
    Route::get('register', [UserAuthController::class, 'registerCreate'])
        ->name('register');

    Route::post('register', [UserAuthController::class, 'registerStore']);

    Route::get('login', [UserAuthController::class, 'loginCreate'])
        ->name('login');
    
    Route::put('login', [UserAuthController::class, 'loginPassword'])
        ->name('login.password');

    Route::post('login', [UserAuthController::class, 'loginStore']);

    Route::get('forgot-password', [UserAuthController::class, 'forgotPasswordCreate'])
        ->name('password.request');

    Route::post('forgot-password', [UserAuthController::class, 'forgotPasswordLink'])
        ->name('password.email');
    
    Route::get('update-password/{id}/{hash}', [UserAuthController::class, 'updatePasswordCreate'])
        ->name('reset.request');
    
    Route::post('update-password', [UserAuthController::class, 'updatePasswordStore'])
        ->name('password.update');

    Route::get('/email/verify', [UserAuthController::class, 'emailVerifyCreate'])
        ->name('verification.notice');
    
    Route::get('/email/send', [UserAuthController::class, 'emailVerifySend'])
        ->name('verification.send');
    
    Route::get('/email/verify/{id}/{hash}', [UserAuthController::class, 'emailVerifyStore'])
        ->name('verification.verify');
});

Route::middleware('web')->group(function () {
    Route::post('/logout', [UserAuthController::class, 'destroy'])
        ->name('logout');

    Route::prefix('management')->group(function (){
        Route::get('/profile', [ManagementController::class, 'profile'])
            ->name('management.profile');
        Route::delete('/permissions', [RoleController::class, 'deletePermission'])
            ->name('permissions.delete');
        Route::post('/permissions', [RoleController::class, 'storePermission'])
            ->name('permissions.store');
    });
    
    Route::prefix('account')->group(function (){
        Route::get('/profile', [UserController::class, 'profile'])
            ->name('account.profile');
        Route::get('/security', [UserController::class, 'security'])
            ->name('account.security');
        Route::get('/tokens', [UserController::class, 'tokens'])
            ->name('account.tokens');
        Route::get('/teams', [UserController::class, 'teams'])
            ->name('account.teams');
        Route::get('/sessions', [UserController::class, 'sessions'])
            ->name('account.sessions');
        Route::get('/loginHistory', [UserController::class, 'loginHistory'])
            ->name('account.loginHistory');
        
        Route::put('/profileUpdate', [UserController::class, 'profileUpdate'])
            ->name('profile.update');
        Route::post('change-password', [UserController::class, 'changePassword'])
            ->name('password.change');
        Route::delete('/accountDelete', [UserController::class, 'accountDelete'])
            ->name('account.delete');
        Route::post('/logoutSessions', [UserAuthController::class, 'destroyAll'])
            ->name('logout.sessions');
    });

    Route::prefix('teams')->group(function (){
        Route::get('/{team}/profile', [TeamController::class, 'profile'])
            ->name('teams.profile');
        Route::put('/switch', [TeamController::class, 'switch'])
            ->name('teams.switch');
        Route::get('/create', [TeamController::class, 'create'])
            ->name('teams.create');
        Route::get('/{team}/members', [TeamController::class, 'members'])
            ->name('teams.members');
        Route::get('/{team}/roles', [TeamController::class, 'roles'])
            ->name('teams.roles');
        Route::get('/{team}/settings', [TeamController::class, 'settings'])
            ->name('teams.settings');

        Route::post('/create', [TeamController::class, 'store'])
            ->name('teams.store');
        Route::put('/update', [TeamController::class, 'update'])
            ->name('teams.update');
        Route::delete('/delete', [TeamController::class, 'delete'])
            ->name('teams.delete');
        Route::put('/members/invite', [TeamController::class, 'inviteMember'])
            ->name('teams.invite');
        Route::delete('/members/leave', [TeamController::class, 'leave'])
            ->name('teams.leave');
        Route::put('/members/invite/action', [TeamController::class, 'inviteAction'])
            ->name('teams.invite.action');
        Route::post('/members/request', [TeamController::class, 'request'])
            ->name('teams.request');
        Route::put('/members/request/action', [TeamController::class, 'requestAction'])
            ->name('teams.request.action');
        Route::put('/owner/change', [TeamController::class, 'ownerChange'])
            ->name('teams.owner.change');
        Route::put('/role/change', [TeamController::class, 'roleChange'])
            ->name('teams.role.change');
        Route::post('/roles/store', [RoleController::class, 'store'])
            ->name('teams.roles.store');
        Route::delete('/roles', [RoleController::class, 'delete'])
            ->name('teams.roles.delete');
        Route::put('/roles/permissions/update', [RoleController::class, 'updatePermission'])
            ->name('roles.permissions.update');
    });
});
