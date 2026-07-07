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
    | Routes
    |--------------------------------------------------------------------------
    |
    | URL prefix for every machine-facing route the package registers: the
    | API namespace, OAuth redirect/callback, and tenant SSO endpoints
    | (e.g. 'auth' gives /auth/login, /auth/oauth/{service}/callback,
    | /auth/sso/callback). Blade UI pages (/login, /account/...) stay at
    | the root — they are end-user URLs, not part of the API namespace.
    |
    | Changing this also changes the OAuth/SSO callback URLs registered
    | with your identity providers — update those app registrations too.
    | Route NAMES are unaffected.
    |
    */
    'route_prefix' => env('NEEV_ROUTE_PREFIX', 'neev'),

    /*
    |--------------------------------------------------------------------------
    | Frontend UI
    |--------------------------------------------------------------------------
    |
    | Which starter kit drives the frontend. 'blade' registers the Blade
    | page routes (/login, /account/..., rendered from the app-owned views
    | the installer ejected). null runs the package headless: only the API,
    | OAuth/SSO, and email flows are active, and you build the frontend
    | yourself (see docs/rfcs/002-starter-kits.md).
    |
    | Set by `php artisan neev:install` / `php artisan neev:ui`.
    |
    */
    'ui' => env('NEEV_UI'),

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

    // Minutes before password reset links expire.
    'url_expiry_time' => 60,

    // Minutes before email OTP codes expire.
    'otp_expiry_time' => 15,

    // Days before password expires. 0 = disabled.
    'password_expiry_days' => 90,

    /*
    |--------------------------------------------------------------------------
    | Magic Link (Passwordless) Authentication
    |--------------------------------------------------------------------------
    |
    | Neev ships two magic-link drivers:
    |   - 'signed_url' : Laravel temporary signed URLs (stateless, legacy default).
    |                    Links remain valid until they expire and are replayable.
    |   - 'stateful'   : Server-side, single-use, revocable opaque tokens with
    |                    optional browser binding and confirmation-required flows.
    |
    | Neev is UI-agnostic: it manages token lifecycle, validation and security
    | policy only. Host applications own all routing, deep-link and UI concerns.
    |
    */

    'magic_link' => [
        // Minutes before a magic link expires. Recommended: 5-15 minutes.
        'expires_in' => env('NEEV_MAGIC_LINK_EXPIRY', 10),

        'bind_to_browser' => false,

        'require_confirmation' => false,

        // Channel-aware link generation. Neev builds the URL; it never renders
        // UI or handles deep-link routing — the host app does.
        //
        // Add your own channels here (e.g. 'desktop') — no code changes needed.
        // A channel with a 'scheme'/'universal_link' is built as a deep link;
        // otherwise it is a web URL built from 'base_url' + 'path'.
        'channels' => [
            'web' => [
                'base_url' => env('APP_URL'),
                // Path appended to base_url for the redemption link.
                'path' => '/login-link',
            ],
            'mobile' => [
                // Custom URL scheme for native deep links (e.g. "myapp://login").
                'scheme' => env('NEEV_MOBILE_SCHEME'),
                // HTTPS universal/app link fallback.
                'universal_link' => env('NEEV_MOBILE_UNIVERSAL_LINK'),
            ],
            // 'desktop' => [
            //     'scheme' => env('NEEV_DESKTOP_SCHEME'), // e.g. "myapp-desktop://login"
            // ],
        ],
    ],

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
