# Configuration Reference

Complete reference for all Neev configuration options in `config/neev.php`.

---

## Identity

### Multi-Tenant Isolation

```php
'tenant' => false,
```

Enable multi-tenant isolation. When enabled:
- Users are scoped to a tenant (isolated identity)
- The same email can exist in different tenants
- The tenant is resolved before authentication (X-Tenant header, subdomain, or custom domain via the `domains` table)

### Team Management

```php
'team' => false,
```

Enable team/organization sub-grouping. Teams are optional in both tenant and non-tenant modes. When enabled:
- Users can create teams
- Team invitations are available
- Team switching is enabled
- Requires `teams`, `memberships`, `team_invitations` tables
- The team routes are registered — `/teams/*` and `/account/teams` on the web,
  `/neev/teams/*`, `/neev/domains/*` and `/neev/changeTeamOwner` on the API

With `team => false` those routes are never registered, so the paths answer 404
and `route('teams.create')` throws. Wrap any link to them in
`@if (config('neev.team'))`. See [Web Routes](./web-routes.md#team-routes).

The two flags combine into four valid modes:

| `tenant` | `team` | Mode |
|----------|--------|------|
| `false` | `false` | Simple app |
| `false` | `true` | Collaboration (teams as workspaces) |
| `true` | `false` | Multi-tenant isolated |
| `true` | `true` | Enterprise SaaS (teams inside tenants) |

The `neev:install` wizard asks exactly these two questions and sets the flags for you.

> **Where did `identity_strategy` and `tenant_isolation` go?** They were collapsed into the single `tenant` flag: `tenant = true` always means isolated identity with strict scoping (no longer configurable). Subdomain suffix and custom-domain options were removed — the package simply looks up the request host in the `domains` table, and the consuming app creates domain records however it wants. See [Architecture](./architecture.md) and [docs/config-refactor.md](./config-refactor.md) for the rationale.

---

## Routes

### Route Prefix

```php
'route_prefix' => env('NEEV_ROUTE_PREFIX', 'neev'),
```

URL prefix for every machine-facing route the package registers: the API namespace (including `/csrf-cookie`), the OAuth redirect/callback routes, and the tenant SSO endpoints. For example, setting it to `auth` gives `/auth/login`, `/auth/oauth/{service}/callback`, and `/auth/sso/callback`.

Blade UI pages (`/login`, `/register`, `/account/...`) stay at the root — they are end-user URLs, not part of the API namespace.

> **Warning:** Changing the prefix also changes the OAuth/SSO callback URLs registered with your identity providers — update those app registrations too. Route names are unaffected.

---

## Frontend UI

```php
'ui' => env('NEEV_UI'),
```

Which starter kit drives the frontend. Valid values:

- **`'blade'`** — registers the Blade page routes (`/login`, `/register`, `/account/...`), rendered from the app-owned views the installer ejected to `resources/views/vendor/neev/`. Setting `'blade'` without ejecting the kit gives a clear "view not found" error — run `php artisan neev:ui blade` first.
- **`null`** (default) — headless: no Blade page routes are registered. The API routes, OAuth/SSO endpoints, and email flows are unaffected; you build the frontend yourself. Verification and new-user invitation email links point at your frontend (`{app.url}/verify-email?...`, `{app.url}/register?invitation_id=...&hash=...`) carrying the signed query for the API endpoints.

This value is normally set for you by `php artisan neev:install` / `php artisan neev:ui` — see [CLI Commands](./cli-commands.md#neevui) and [RFC 002](./rfcs/002-starter-kits.md).

---

## Authentication

### Username Support

```php
'support_username' => false,
```

When enabled:
- Users can register and login with usernames
- Username field added to registration
- Both email and username can be used for login

### OAuth Providers

```php
'oauth' => [
    // 'google',
    // 'github',
    // 'microsoft',
    // 'apple',
],
```

App-wide social login providers. Uncomment providers you want to enable. Each requires credentials in `config/services.php`.

> This is separate from per-tenant/per-team enterprise SSO, which is configured by tenant or team admins and stored in the `tenant_auth_settings` / `team_auth_settings` database tables (`TenantAuthSettings` / `TeamAuthSettings` models) — not in this config file. The old `tenant_auth` and `tenant_auth_options` config keys were removed in favour of these DB-backed settings.

### WebAuthn Relying Party ID

```php
'relying_party_id' => parse_url(config('app.url'), PHP_URL_HOST),
```

The domain that passkeys are bound to (e.g. `example.com`). Defaults to the host of `app.url`. This is a single application-wide value: passkeys work on this domain and its subdomains only, never on a tenant's custom domain. See [Supported Domains](./authentication.md#supported-domains).

### WebAuthn Allowed Origins

```php
'allowed_origins' => [
    config('app.url'),
],
```

Origins permitted to complete WebAuthn ceremonies **under the relying party ID above**. List every allowed origin for multi-origin setups (e.g. app served from multiple domains), including each subdomain — subdomain matching is off, so a wildcard is not accepted. Listing an origin the relying party ID does not cover has no effect; the browser rejects those ceremonies before the server sees them.

---

## Multi-Factor Authentication

### Available Methods

```php
'multi_factor_auth' => [
    'authenticator',  // TOTP via authenticator apps
    'email',          // OTP via email
],
```

### Recovery Codes

```php
'recovery_codes' => 8,
```

Number of single-use recovery codes generated per user.

### Pending Setup Retention

```php
'mfa_pending_setup_retention_days' => 2,
```

Days to keep unverified (pending) MFA setups before the `neev:clean-pending-mfa-setups` command deletes them.

Set it to `0` (or any value below 1) to **disable** the cleanup. Zero does not mean "delete everything": a retention of zero would be "older than right now", which would wipe setups a user was still in the middle of, so the command skips the deletion and reports that it is disabled — the same convention `login_history_retention_days` follows.

---

## Verification

### OTP Length

```php
'otp_length' => 6,
```

Length of one-time password codes: 4, 6, or 8 digits (used for MFA email OTP). Replaces the old `otp_min` / `otp_max` range keys.

> **Email verification:** there is no `email_verified` toggle anymore. The verification flow (signed links) is always available, and enforcement is opt-in via the `neev-verified-email` middleware alias (`EnsureEmailIsVerified`) — apply it to the routes you want to protect.

---

## Expiry

### Login Token Expiry

```php
'login_token_expiry_minutes' => 1440,
```

Minutes before login access tokens expire.

### MFA JWT Expiry

```php
'mfa_jwt_expiry_minutes' => 30,
```

Minutes before temporary MFA JWTs expire.

### JWT Secret

```php
'jwt_secret' => env('NEEV_JWT_SECRET'),
```

Secret key for signing MFA JWTs. Falls back to `APP_KEY` if not set.

### URL Token Expiry

```php
'url_expiry_time' => 60,
```

Minutes before every emailed link expires — magic links, password reset,
email verification, email change, and team invitations.

Where those links *point* is not configuration: it is decided by the
`EmailLinks` service, which you subclass and bind when you want them on your
own pages. See [Email Links](./email-links.md).

### OTP Expiry

```php
'otp_expiry_time' => 15,
```

Minutes before email OTP codes expire.

### Password Expiry

```php
'password_expiry_days' => 90,
```

Days before a password expires. Set to `0` to disable. Replaces the old `password_soft_expiry_days` / `password_hard_expiry_days` pair — the warning period is now the app's UI concern via the user helper methods (`passwordExpiresAt()`, `isPasswordExpired()`, `isPasswordExpiringSoon($days)`).

Enforcement is opt-in: apply the `neev-password-not-expired` middleware alias (`EnsurePasswordNotExpired`) to protected routes. It returns a 403 with a `password_expired` error for expired passwords.

---

## Rate Limiting (Progressive Delay)

```php
'login_throttle' => [
    'delay_after' => 3,
    'max_delay_seconds' => 300,
],
```

| Option | Description |
|--------|-------------|
| `delay_after` | Failed attempts before progressive delay kicks in |
| `max_delay_seconds` | Maximum delay in seconds (exponential backoff caps here) |

Progressive delay replaces the old hard-lockout scheme (`login_soft_attempts`, `login_hard_attempts`, `login_block_minutes`): after `delay_after` failed attempts, each further attempt is delayed with exponential backoff up to `max_delay_seconds`.

---

## Login Tracking

### Failed Attempt Storage

```php
'log_failed_logins' => false,
```

- `false`: Store failed attempts in cache (faster, auto-cleanup)
- `true`: Store failed attempts in database (persistent, auditable)

### Login History Retention

```php
'login_history_retention_days' => 30,
```

Days to keep login history records. Use `neev:clean-login-attempts` to remove old records.

---

## MaxMind GeoIP

```php
'maxmind' => [
    'db_path' => 'app/geoip/GeoLite2-City.mmdb',
    'edition' => env('MAXMIND_EDITION', 'GeoLite2-City'),
    'license_key' => env('MAXMIND_LICENSE_KEY'),
],
```

| Option | Description |
|--------|-------------|
| `db_path` | Path to GeoIP database (relative to storage) |
| `edition` | MaxMind database edition |
| `license_key` | Your MaxMind license key (used by `neev:download-geoip`) |

---

## Post-Auth Redirect

```php
'home' => '/dashboard',
```

Path users are redirected to after login, logout-adjacent flows, and email actions. Applies to Blade flows only — API flows return JSON and never redirect. Replaces the old `dashboard_url` / `frontend_url` keys.

---

## Password Validation

```php
use Ssntpl\Neev\Rules\Password;
use Ssntpl\Neev\Rules\PasswordHistory;
use Ssntpl\Neev\Rules\PasswordUserData;

'password' => [
    'required',
    'confirmed',
    Password::min(8)->max(72)->letters()->mixedCase()->numbers()->symbols(),
    PasswordHistory::notReused(5),
    PasswordUserData::notContain(['name', 'email']),
],
```

`Ssntpl\Neev\Rules\Password` is Laravel's `Illuminate\Validation\Rules\Password`
with one addition: a `__set_state()` method. `php artisan config:cache` writes
the config with `var_export()` and reads it back with `require`, and an object
without `__set_state()` makes that cache file fatal on load — which is what
Laravel reports as a non-serializable config value. The fluent builder is
unchanged and every method returns the same class, so
`Password::min(8)->symbols()` reads and behaves exactly as before while
surviving a cached config. `PasswordHistory` and `PasswordUserData` carry the
same trait.

Import the rule from `Ssntpl\Neev\Rules` in your published `config/neev.php`.

| Rule | Description |
|------|-------------|
| `min(8)` | Minimum 8 characters |
| `max(72)` | Maximum 72 characters (bcrypt limit) |
| `letters()` | Must contain letters |
| `mixedCase()` | Must contain uppercase and lowercase |
| `numbers()` | Must contain numbers |
| `symbols()` | Must contain special characters |
| `PasswordHistory::notReused(5)` | Cannot reuse last 5 passwords |
| `PasswordUserData::notContain()` | Cannot contain user's personal data |

---

## Username Validation

```php
'username' => [
    'required',
    'string',
    'min:3',
    'max:20',
    'regex:/^(?![._])(?!.*[._]{2})[a-zA-Z0-9._]+(?<![._])$/',
    'unique:users,username',
],
```

| Rule | Description |
|------|-------------|
| `min:3` | Minimum 3 characters |
| `max:20` | Maximum 20 characters |
| `regex` | Alphanumeric with dots/underscores, no consecutive/leading/trailing special chars |

**Valid examples:** `john_doe`, `user123`, `jane.smith`

**Invalid examples:** `_user`, `user..name`, `user_`, `user@domain`

---

## Team Slugs

Only used when `team => true`.

```php
'slug' => [
    'min_length' => 2,
    'max_length' => 63,
    'reserved' => ['www', 'api', 'admin', 'app', 'mail', 'ftp', 'cdn', 'assets', 'static'],
],
```

| Option | Description |
|--------|-------------|
| `min_length` | Minimum slug length |
| `max_length` | Maximum slug length (63 for DNS compliance) |
| `reserved` | Slugs that cannot be used by teams |

---

## Model Configuration

```php
'tenant_model' => Ssntpl\Neev\Models\Tenant::class,
'team_model' => Ssntpl\Neev\Models\Team::class,
'user_model' => Ssntpl\Neev\Models\User::class,
```

Specify custom model classes that extend Neev's default models.

---

## Complete Configuration Example

```php
<?php

use Illuminate\Validation\Rules\Password;
use Ssntpl\Neev\Rules\PasswordHistory;
use Ssntpl\Neev\Rules\PasswordUserData;

return [
    // Identity
    'tenant' => false,
    'team' => true,

    // Routes
    'route_prefix' => env('NEEV_ROUTE_PREFIX', 'neev'),

    // Frontend UI ('blade' = Blade page routes from app-owned views; null = headless)
    'ui' => env('NEEV_UI'),

    // Authentication
    'support_username' => false,
    'oauth' => [
        'google',
        'github',
    ],
    'relying_party_id' => parse_url(config('app.url'), PHP_URL_HOST),
    'allowed_origins' => [
        config('app.url'),
    ],

    // MFA
    'multi_factor_auth' => ['authenticator', 'email'],
    'recovery_codes' => 8,
    'mfa_pending_setup_retention_days' => 2,

    // Verification
    'otp_length' => 6,

    // Expiry
    'login_token_expiry_minutes' => 1440,
    'mfa_jwt_expiry_minutes' => 30,
    'jwt_secret' => env('NEEV_JWT_SECRET'),
    'url_expiry_time' => 60,
    'otp_expiry_time' => 15,
    'password_expiry_days' => 90,            // 0 = disabled

    // Rate limiting (progressive delay)
    'login_throttle' => [
        'delay_after' => 3,
        'max_delay_seconds' => 300,
    ],

    // Login tracking
    'log_failed_logins' => false,
    'login_history_retention_days' => 30,

    // MaxMind GeoIP
    'maxmind' => [
        'db_path' => 'app/geoip/GeoLite2-City.mmdb',
        'edition' => env('MAXMIND_EDITION', 'GeoLite2-City'),
        'license_key' => env('MAXMIND_LICENSE_KEY'),
    ],

    // Post-auth redirect (Blade flows only)
    'home' => '/dashboard',

    // Validation rules
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

    // Team slugs (only when team=true)
    'slug' => [
        'min_length' => 2,
        'max_length' => 63,
        'reserved' => ['www', 'api', 'admin', 'app', 'mail', 'ftp', 'cdn', 'assets', 'static'],
    ],

    // Models
    'tenant_model' => Ssntpl\Neev\Models\Tenant::class,
    'team_model' => Ssntpl\Neev\Models\Team::class,
    'user_model' => Ssntpl\Neev\Models\User::class,
];
```

---

## Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `NEEV_ROUTE_PREFIX` | Prefix for machine-facing routes (API, OAuth, SSO, csrf-cookie) | `neev` |
| `NEEV_UI` | Frontend starter kit: `blade` or unset (headless) | unset (headless) |
| `NEEV_JWT_SECRET` | Secret for signing MFA JWTs | Falls back to `APP_KEY` |
| `MAXMIND_EDITION` | GeoIP database edition | `GeoLite2-City` |
| `MAXMIND_LICENSE_KEY` | MaxMind license key | - |

---

## Removed Options

The following keys no longer exist in `config/neev.php`. Where behaviour moved, the replacement is noted:

| Removed key | Replacement |
|-------------|-------------|
| `identity_strategy`, `tenant_isolation`, `tenant_isolation_options` | Single `tenant` flag; domain resolution via `domains` table |
| `tenant_auth`, `tenant_auth_options` | Per-entity settings in `tenant_auth_settings` / `team_auth_settings` DB tables |
| `email_verified` | Opt-in `neev-verified-email` middleware alias (`EnsureEmailIsVerified`) |
| `require_company_email`, `free_email_domains`, `domain_federation` | Removed from Neev (app-level or separate package concern) |
| `magicauth` | Removed — magic link login is always available |
| `dashboard_url`, `frontend_url` | `home` (path only, Blade flows) |
| `otp_min` / `otp_max` | `otp_length` |
| `login_soft_attempts` / `login_hard_attempts` / `login_block_minutes` | `login_throttle` progressive delay |
| `password_soft_expiry_days` / `password_hard_expiry_days` | `password_expiry_days` + `neev-password-not-expired` middleware alias (`EnsurePasswordNotExpired`) |
| `record_failed_login_attempts` | `log_failed_logins` |
| `last_login_attempts_in_days` | `login_history_retention_days` |
| `geo_ip_db`, `edition`, `maxmind_license_key` | `maxmind.db_path`, `maxmind.edition`, `maxmind.license_key` |

---

## Next Steps

- [Authentication Guide](./authentication.md)
- [Multi-Tenancy](./multi-tenancy.md)
- [Architecture](./architecture.md) -- identity model design decisions
- [API Reference](./api-reference.md)
