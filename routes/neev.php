<?php

use Illuminate\Support\Facades\Route;
use Ssntpl\Neev\Http\Controllers\Auth\AuthController;
use Ssntpl\Neev\Http\Controllers\Auth\OAuthController;
use Ssntpl\Neev\Http\Controllers\Auth\PasskeyController;
use Ssntpl\Neev\Http\Controllers\Auth\UserAuthController;
use Ssntpl\Neev\Http\Controllers\RoleController;
use Ssntpl\Neev\Http\Controllers\TeamApiController;
use Ssntpl\Neev\Http\Controllers\TeamController;
use Ssntpl\Neev\Http\Controllers\UserApiController;
use Ssntpl\Neev\Http\Controllers\UserController;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\User;

Route::bind('user', fn($value) => User::model()->findOrFail($value));

Route::bind('team', fn($value) => Team::model()->findOrFail($value));

//web routes
Route::middleware('web')->group(function () {
    Route::get('/register', [UserAuthController::class, 'registerCreate'])
        ->name('register');

    Route::post('/register', [UserAuthController::class, 'registerStore']);

    Route::get('/login', [UserAuthController::class, 'loginCreate'])
        ->name('login');
    
    Route::put('/login', [UserAuthController::class, 'loginPassword'])
        ->name('login.password');

    Route::post('/login/link', [UserAuthController::class, 'sendLoginLink'])
        ->name('login.link.send');

    Route::get('/login/{id}', [UserAuthController::class, 'loginUsingLink'])
        ->name('login.link');

    Route::get('/otp/mfa/{method}', [UserAuthController::class, 'verifyMFAOTPCreate'])
        ->name('otp.mfa.create');

    Route::post('/otp/mfa', [UserAuthController::class, 'verifyMFAOTPStore'])
        ->name('otp.mfa.store');

    Route::post('/otp/mfa/send', [UserAuthController::class, 'emailOTPSend'])
        ->name('otp.mfa.send');

    Route::post('/login', [UserAuthController::class, 'loginStore']);

    Route::get('/forgot-password', [UserAuthController::class, 'forgotPasswordCreate'])
        ->name('password.request');

    Route::post('/forgot-password', [UserAuthController::class, 'forgotPasswordLink'])
        ->name('password.email');
    
    Route::get('/update-password/{id}/{hash}', [UserAuthController::class, 'updatePasswordCreate'])
        ->name('reset.request');
    
    Route::post('/update-password', [UserAuthController::class, 'updatePasswordStore'])
        ->name('password.update');

    Route::post('/passkeys/login/options',[PasskeyController::class,'generateLoginOptions'])
        ->name('passkeys.login.options');

    Route::post('/passkeys/login',[PasskeyController::class,'loginViaWeb'])
        ->name('passkeys.login');

    //OAuth
    Route::get('/oauth/{service}', [OAuthController::class, 'redirect'])
        ->name('oauth.redirect');
    Route::get('/oauth/{service}/callback', [OAuthController::class, 'callback'])
        ->name('oauth.callback');

    Route::get('/email/verify/{id}/{hash}', [UserAuthController::class, 'emailVerifyStore'])
        ->name('verification.verify');
    
    Route::middleware( ['neev:web'])->group(function () {
        Route::get('/email/verify', [UserAuthController::class, 'emailVerifyCreate'])
            ->name('verification.notice');
        
        Route::get('/email/send', [UserAuthController::class, 'emailVerifySend'])
            ->name('verification.send');
        
        Route::get('/email/change', [UserAuthController::class, 'emailChangeCreate'])
            ->name('email.change');
        
        Route::put('/email/change', [UserAuthController::class, 'emailChangeStore'])
            ->name('email.update');
    
        Route::post('/logout', [UserAuthController::class, 'destroy'])
            ->name('logout');
        
        Route::prefix('account')->group(function (){
            Route::get('/profile', [UserController::class, 'profile'])
                ->name('account.profile');
            Route::get('/emails', [UserController::class, 'emails'])
                ->name('account.emails');
            Route::get('/security', [UserController::class, 'security'])
                ->name('account.security');
            Route::get('/tokens', [UserController::class, 'tokens'])
                ->name('account.tokens');
            Route::get('/teams', [UserController::class, 'teams'])
                ->name('account.teams');
            Route::get('/sessions', [UserController::class, 'sessions'])
                ->name('account.sessions');
            Route::get('/loginAttempts', [UserController::class, 'loginAttempts'])
                ->name('account.loginAttempts');
    
            Route::post('/multiFactorAuth', [UserController::class, 'addMultiFactorAuth'])
                ->name('multi.auth');
            Route::put('/multiFactorAuth', [UserController::class, 'preferedMultiFactorAuth'])
                ->name('multi.prefered');
            Route::get('/recovery/codes', [UserController::class, 'recoveryCodes'])
                ->name('recovery.codes');
            Route::post('/recovery/codes', [UserController::class, 'generateRecoveryCodes'])
                ->name('recovery.generate');
    
            Route::post('/passkeys/register/options',[PasskeyController::class,'generateRegistrationOptions'])
                ->name('passkeys.register.options');
            Route::post('/passkeys/register',[PasskeyController::class,'registerViaWeb'])
                ->name('passkeys.register');
            Route::delete('/passkeys',[PasskeyController::class,'deletePasskey'])
                ->name('passkeys.delete');
            
            Route::put('/profileUpdate', [UserController::class, 'profileUpdate'])
                ->name('profile.update');
            Route::post('/change-password', [UserController::class, 'changePassword'])
                ->name('password.change');
            Route::post('/emails', [UserController::class, 'addEmail'])
                ->name('emails.add');
            Route::delete('/emails', [UserController::class, 'deleteEmail'])
                ->name('emails.delete');
            Route::put('/emails', [UserController::class, 'primaryEmail'])
                ->name('emails.primary');
            Route::delete('/accountDelete', [UserController::class, 'accountDelete'])
                ->name('account.delete');
            Route::post('/logoutSessions', [UserAuthController::class, 'destroyAll'])
                ->name('logout.sessions');
            Route::post('/tokens/store', [UserController::class, 'tokenStore'])
                ->name('tokens.store');
            Route::delete('/tokens/delete', [UserController::class, 'tokenDelete'])
                ->name('tokens.delete');
            Route::delete('/tokens/deleteAll', [UserController::class, 'tokenDeleteAll'])
                ->name('tokens.deleteAll');
            Route::put('/tokens/update', [UserController::class, 'tokenUpdate'])
                ->name('tokens.update');
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
            Route::get('/{team}/domain', [TeamController::class, 'domain'])
                ->name('teams.domain');
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
            Route::put('/roles/change', [RoleController::class, 'roleChange'])
                ->name('teams.roles.change');
            Route::put('/members/invite/action', [TeamController::class, 'inviteAction'])
                ->name('teams.invite.action');
            Route::post('/members/request', [TeamController::class, 'request'])
                ->name('teams.request');
            Route::put('/members/request/action', [TeamController::class, 'requestAction'])
                ->name('teams.request.action');
            Route::put('/owner/change', [TeamController::class, 'ownerChange'])
                ->name('teams.owner.change');
            Route::post('/{team}/domain', [TeamController::class, 'federateDomain']);
            Route::put('/{domain}/domain', [TeamController::class, 'updateDomain']);
            Route::delete('/{domain}/domain', [TeamController::class, 'deleteDomain']);
            Route::put('/{domain}/domain/rules', [TeamController::class, 'updateDomainRule'])
                ->name('domain.rules');
            Route::put('/domain/primary', [TeamController::class, 'primaryDomain'])
                ->name('domain.primary');
        });
    });
});

//APIs
Route::prefix('/neev')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/sendLoginLink', [AuthController::class, 'sendLoginLink']);
    Route::get('/loginUsingLink', [AuthController::class, 'loginUsingLink'])->name('loginUsingLink');
    Route::post('/email/otp/send', [AuthController::class, 'sendEmailOTP']);
    Route::post('/email/otp/verify', [AuthController::class, 'verifyEmailOTP']);
    Route::post('/forgotPassword', [AuthController::class, 'forgotPassword']);
    Route::get('/passkeys/login/options',[PasskeyController::class,'generateLoginOptions']);
    Route::post('/passkeys/login',[PasskeyController::class,'loginViaAPI']);

    Route::middleware('neev:api')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/logoutAll', [AuthController::class, 'logoutAll']);

        Route::post('/email/send', [AuthController::class, 'sendMailVerificationLink']);
        Route::get('/email/verify', [AuthController::class, 'emailVerify'])->name('mail.verify');
        Route::post('/email/update', [UserApiController::class, 'emailUpdate']);
        Route::post('/mfa/add', [UserApiController::class, 'addMultiFactorAuthentication']);
        Route::delete('/mfa/delete', [UserApiController::class, 'deleteMultiFactorAuthentication']);
        Route::post('/mfa/otp/verify', [AuthController::class, 'verifyMFAOTP']);
        Route::get('/recoveryCodes', [UserApiController::class, 'getRecoveryCodes']);
        Route::post('/recoveryCodes', [UserApiController::class, 'generateRecoveryCodes']);

        Route::get('/users', [UserApiController::class, 'getUser']);
        Route::put('/users', [UserApiController::class, 'updateUser']);
        Route::delete('/users', [UserApiController::class, 'deleteUser']);
        Route::post('/emails', [UserApiController::class, 'addEmail']);
        Route::delete('/emails', [UserApiController::class, 'deleteEmail']);
        Route::put('/emails', [UserApiController::class, 'primaryEmail']);
        Route::get('/sessions', [UserApiController::class, 'sessions']);
        Route::get('/loginAttempts', [UserApiController::class, 'loginAttempts']);
        Route::put('/changePassword', [UserApiController::class, 'changePassword']);

        Route::get('/passkeys/register/options',[PasskeyController::class,'generateRegistrationOptions']);
        Route::post('/passkeys/register',[PasskeyController::class,'registerViaAPI']);
        Route::delete('/passkeys',[PasskeyController::class,'deletePasskeyViaAPI']);
        Route::put('/passkeys',[PasskeyController::class,'updatePasskeyName']);

        Route::get('/apiTokens', [UserApiController::class, 'getApiTokens']);
        Route::post('/apiTokens', [UserApiController::class, 'addApiTokens']);
        Route::put('/apiTokens', [UserApiController::class, 'updateApiTokens']);
        Route::delete('/apiTokens', [UserApiController::class, 'deleteApiTokens']);
        Route::delete('/apiTokens/deleteAll', [UserApiController::class, 'deleteAllApiTokens']);
        
        Route::get('/teams', [TeamApiController::class, 'teams']);
        Route::get('/teams/{id}', [TeamApiController::class, 'getTeam']);
        Route::post('/teams', [TeamApiController::class, 'createTeam']);
        Route::put('/teams', [TeamApiController::class, 'updateTeam']);
        Route::delete('/teams', [TeamApiController::class, 'deleteTeam']);
        Route::post('/changeTeamOwner', [TeamApiController::class, 'changeTeamOwner']);
        Route::post('/teams/inviteUser', [TeamApiController::class, 'inviteMember']);
        Route::put('/teams/inviteUser', [TeamApiController::class, 'inviteAction']);
        Route::put('/teams/leave', [TeamApiController::class, 'leave']);
        Route::post('/teams/request', [TeamApiController::class, 'request']);
        Route::put('/teams/request', [TeamApiController::class, 'requestAction']);
        
        Route::get('/domains', [TeamApiController::class, 'getDomains']);
        Route::post('/domains', [TeamApiController::class, 'domainFederate']);
        Route::put('/domains', [TeamApiController::class, 'updateDomain']);
        Route::delete('/domains', [TeamApiController::class, 'deleteDomain']);
        Route::put('/domains/rules', [TeamApiController::class, 'updateDomainRule']);
        Route::get('/domains/rules', [TeamApiController::class, 'getDomainRule']);
        Route::put('/domains/primary', [TeamApiController::class, 'primaryDomain']);
        
        Route::put('/role/change', [RoleController::class, 'requestAction']);
    });
});