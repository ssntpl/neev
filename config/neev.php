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

    // WebAuthn relying party ID — the domain passkeys are bound to (e.g. "example.com").
    'relying_party_id' => parse_url(config('app.url'), PHP_URL_HOST),

    // Origins permitted to complete WebAuthn ceremonies. List every allowed origin for multi-origin setups.
    'allowed_origins' => [
        config('app.url'),
    ],

    /*
    |--------------------------------------------------------------------------
    | SPA Cookie Mode
    |--------------------------------------------------------------------------
    |
    | Same-origin web SPAs can authenticate via an HttpOnly cookie instead of
    | storing the bearer token in JS-accessible storage. Requests whose
    | Origin/Referer host matches the stateful list have their auth cookie
    | promoted to an Authorization header and, for state-changing methods,
    | must pass a signed double-submit CSRF check. Everything else falls
    | through to the plain bearer-token path unchanged.
    |
    | 'stateful' supports exact hosts ("app.example.com"), host:port
    | ("localhost:3000"), and prefix wildcards ("*.example.com"). An empty
    | list disables SPA cookie mode entirely.
    |
    | The auth cookie is automatically excluded from Laravel's cookie
    | encryption (neev registers EncryptCookies::except for it) so it reads
    | identically on API routes and inside web-group redirects; it carries
    | an already-opaque token. The CSRF token is HMAC-signed to APP_KEY
    | instead of encrypted. Only if you route neev's endpoints through a
    | web-style stack yourself must the CSRF cookie name also be excepted —
    | and consider renaming it then, since XSRF-TOKEN is shared with
    | Laravel's own web-session CSRF cookie.
    |
    */
    'spa' => [
        'stateful' => array_filter(explode(',', (string) env('NEEV_SPA_STATEFUL_DOMAINS', ''))),
        'cookie_name' => env('NEEV_SPA_COOKIE_NAME', 'neev_session'),
        'csrf_cookie_name' => env('NEEV_SPA_CSRF_COOKIE_NAME', 'XSRF-TOKEN'),
        'csrf_header_name' => env('NEEV_SPA_CSRF_HEADER_NAME', 'X-XSRF-TOKEN'),
        'cookie_secure' => (bool) env('NEEV_SPA_COOKIE_SECURE', true),
        'cookie_same_site' => env('NEEV_SPA_COOKIE_SAME_SITE', 'lax'),
        'cookie_domain' => env('NEEV_SPA_COOKIE_DOMAIN'),
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

    // Days to keep unverified (pending) MFA setups before neev:clean-pending-mfa-setups deletes them.
    'mfa_pending_setup_retention_days' => 2,

    /*
    |--------------------------------------------------------------------------
    | Verification
    |--------------------------------------------------------------------------
    */

    // OTP length: 4, 6, or 8 digits (used for MFA email OTP).
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
