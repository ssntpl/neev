<?php

use Illuminate\Validation\Rules\Password;
use Ssntpl\Neev\Rules\PasswordHistory;
use Ssntpl\Neev\Rules\PasswordUserData;

return [
    /*
    |--------------------------------------------------------------------------
    | Identity
    |--------------------------------------------------------------------------
    */

    // Multi-tenant isolation. Users scoped to tenant. Same email can exist in different tenants.
    'tenant' => false,

    // Team sub-grouping. Optional in both tenant and non-tenant modes.
    'team' => false,

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    // Allow username-based login in addition to email.
    'support_username' => false,

    // OAuth social login providers (app-wide). Configure credentials in config/services.php.
    'oauth' => [
        // 'google',
        // 'github',
        // 'microsoft',
        // 'apple',
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-Factor Authentication
    |--------------------------------------------------------------------------
    */

    // Available MFA methods: 'authenticator' (TOTP), 'email' (OTP via email).
    'multi_factor_auth' => ['authenticator', 'email'],

    // Number of single-use recovery codes per user.
    'recovery_codes' => 8,

    /*
    |--------------------------------------------------------------------------
    | Verification
    |--------------------------------------------------------------------------
    */

    // Email verification method: 'link' or 'otp'.
    'email_verification_method' => 'link',

    // OTP length: 4, 6, or 8 digits.
    'otp_length' => 6,

    /*
    |--------------------------------------------------------------------------
    | Expiry
    |--------------------------------------------------------------------------
    */

    // Minutes before login access tokens expire.
    'login_token_expiry_minutes' => 1440,

    // Minutes before MFA JWT (temporary) tokens expire.
    'mfa_jwt_expiry_minutes' => 30,

    // Secret key for signing MFA JWTs. Falls back to APP_KEY if not set.
    'jwt_secret' => env('NEEV_JWT_SECRET'),

    // Minutes before magic links, password reset links expire.
    'url_expiry_time' => 60,

    // Minutes before email OTP codes expire.
    'otp_expiry_time' => 15,

    // Days before password expires. 0 = disabled.
    'password_expiry_days' => 90,

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting (Progressive Delay)
    |--------------------------------------------------------------------------
    */

    'login_throttle' => [
        // Failed attempts before progressive delay kicks in.
        'delay_after' => 3,
        // Maximum delay in seconds (exponential backoff caps here).
        'max_delay_seconds' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Login Tracking
    |--------------------------------------------------------------------------
    */

    // Store failed login attempts in database (true) or cache (false).
    'log_failed_logins' => false,

    // Days to retain login history records.
    'login_history_retention_days' => 30,

    /*
    |--------------------------------------------------------------------------
    | MaxMind GeoIP
    |--------------------------------------------------------------------------
    */

    'maxmind' => [
        'db_path' => 'app/geoip/GeoLite2-City.mmdb',
        'edition' => env('MAXMIND_EDITION', 'GeoLite2-City'),
        'license_key' => env('MAXMIND_LICENSE_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Post-Auth Redirect (Blade flows only)
    |--------------------------------------------------------------------------
    */

    'home' => '/dashboard',

    /*
    |--------------------------------------------------------------------------
    | Validation Rules
    |--------------------------------------------------------------------------
    */

    'password' => [
        'required',
        'confirmed',
        Password::min(8)->max(72)->letters()->mixedCase()->numbers()->symbols(),
        PasswordHistory::notReused(5),
        PasswordUserData::notContain(['name', 'email']),
    ],

    'username' => [
        'required',
        'string',
        'min:3',
        'max:20',
        'regex:/^(?![._])(?!.*[._]{2})[a-zA-Z0-9._]+(?<![._])$/',
        'unique:users,username',
    ],

    /*
    |--------------------------------------------------------------------------
    | Team Slugs (only when team=true)
    |--------------------------------------------------------------------------
    */

    'slug' => [
        'min_length' => 2,
        'max_length' => 63,
        'reserved' => ['www', 'api', 'admin', 'app', 'mail', 'ftp', 'cdn', 'assets', 'static'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    */

    'tenant_model' => Ssntpl\Neev\Models\Tenant::class,
    'team_model' => Ssntpl\Neev\Models\Team::class,
    'user_model' => Ssntpl\Neev\Models\User::class,
];
