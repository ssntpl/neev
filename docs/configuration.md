# Configuration Reference

Complete reference for all Neev configuration options in `config/neev.php`.

---

## Feature Toggles

### Team Management

```php
'team' => false,
```

Enable team/organization management. When enabled:
- Users can create teams
- Team invitations are available
- Team switching is enabled
- Requires `teams`, `memberships`, `team_invitations` tables

### Email Verification

```php
'email_verified' => false,
```

When enabled:
- New users must verify their email
- Unverified users are blocked from the application
- Verification emails are sent automatically

### Company Email Requirement

```php
'require_company_email' => false,
```

When enabled (requires `team` feature):
- Users with free email providers (Gmail, Yahoo, etc.) are waitlisted
- Their team is created but inactive
- Admin must manually activate the team

### Free Email Domains

```php
'free_email_domains' => [
    // 'example.com',
    // 'tempmail.org',
],
```

Additional domains to consider as "free" email providers. The default list includes Gmail, Yahoo, Hotmail, Outlook, iCloud, ProtonMail, AOL, and more.

### Domain Federation

```php
'domain_federation' => false,
```

When enabled (requires `team` feature):
- Users with matching email domains can auto-join teams
- Domain verification via DNS TXT records
- Domain-based security policies

### Tenant Isolation

```php
'tenant_isolation' => false,
```

When enabled (requires `team` feature):
- Teams are isolated by subdomain or custom domain
- Enables multi-tenant SaaS architecture
- Requires `tenant_domains` table

### Tenant Isolation Options

```php
'tenant_isolation_options' => [
    'subdomain_suffix' => env('NEEV_SUBDOMAIN_SUFFIX', null),
    'allow_custom_domains' => true,
    'single_tenant_users' => false,
],
```

| Option | Description |
|--------|-------------|
| `subdomain_suffix` | Base domain for subdomains (e.g., `.yourapp.com`) |
| `allow_custom_domains` | Allow tenants to use custom domains |
| `single_tenant_users` | Users can only belong to one tenant |

### Tenant-Driven Authentication

```php
'tenant_auth' => false,
```

When enabled (requires `tenant_isolation`):
- Each tenant can configure their auth method
- Supports password or SSO authentication
- Per-tenant identity provider configuration

### Tenant Auth Options

```php
'tenant_auth_options' => [
    'default_method' => 'password',
    'sso_providers' => ['entra', 'google', 'okta'],
    'auto_provision' => false,
    'auto_provision_role' => null,
],
```

| Option | Description |
|--------|-------------|
| `default_method` | Default auth method (`password` or `sso`) |
| `sso_providers` | Available SSO providers for tenants |
| `auto_provision` | Auto-create users on SSO login |
| `auto_provision_role` | Role for auto-provisioned users |

### Username Support

```php
'support_username' => false,
```

When enabled:
- Users can register and login with usernames
- Username field added to registration
- Both email and username can be used for login

### Magic Link Authentication

```php
'magicauth' => true,
```

When enabled:
- Passwordless login via email links
- "Send login link" option on login page
- Links expire based on `url_expiry_time`

---

## Slug Configuration

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
'team_model' => Ssntpl\Neev\Models\Team::class,
'user_model' => Ssntpl\Neev\Models\User::class,
```

Specify custom model classes that extend Neev's default models.

---

## Application URLs

```php
'dashboard_url' => env('NEEV_DASHBOARD_URL', env('APP_URL').'/dashboard'),
'frontend_url' => env('APP_URL'),
```

| Option | Description |
|--------|-------------|
| `dashboard_url` | Redirect URL after login |
| `frontend_url` | Base URL for frontend (SPA) |

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

---

## OAuth Providers

```php
'oauth' => [
    // 'google',
    // 'github',
    // 'microsoft',
    // 'apple',
],
```

Uncomment providers you want to enable. Each requires additional configuration in `config/services.php`.

---

## Login Tracking

### Failed Attempt Storage

```php
'record_failed_login_attempts' => false,
```

- `false`: Store in cache (faster, auto-cleanup)
- `true`: Store in database (persistent, auditable)

### Login History Retention

```php
'last_login_attempts_in_days' => 30,
```

Days to keep login attempt records. Use `neev:clean-login-attempts` to remove old records.

---

## GeoIP Configuration

```php
'geo_ip_db' => 'app/geoip/GeoLite2-City.mmdb',
'edition' => env('MAXMIND_EDITION', 'GeoLite2-City'),
'maxmind_license_key' => env('MAXMIND_LICENSE_KEY'),
```

| Option | Description |
|--------|-------------|
| `geo_ip_db` | Path to GeoIP database (relative to storage) |
| `edition` | MaxMind database edition |
| `maxmind_license_key` | Your MaxMind license key |

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

## Password Validation

```php
'password' => [
    'required',
    'confirmed',
    Password::min(8)
        ->max(72)
        ->letters()
        ->mixedCase()
        ->numbers()
        ->symbols(),
    PasswordHistory::notReused(5),
    PasswordUserData::notContain(['name', 'email']),
],
```

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

## Rate Limiting

### Soft Limit (Progressive Delay)

```php
'login_soft_attempts' => 5,
```

Failed attempts before introducing delays between attempts.

### Hard Limit (Account Lockout)

```php
'login_hard_attempts' => 20,
```

Failed attempts before completely blocking login.

### Lockout Duration

```php
'login_block_minutes' => 1,
```

Minutes to block login after reaching hard limit.

---

## Password Expiry

### Soft Expiry (Warning)

```php
'password_soft_expiry_days' => 30,
```

Days before hard expiry when warnings start.

### Hard Expiry (Forced Change)

```php
'password_hard_expiry_days' => 90,
```

Days until password must be changed. Set to `0` to disable.

---

## Token Expiry

### URL Token Expiry

```php
'url_expiry_time' => 60,
```

Minutes before magic links, password reset links, and verification links expire.

### OTP Expiry

```php
'otp_expiry_time' => 15,
```

Minutes before one-time passwords expire.

---

## OTP Range

```php
'otp_min' => 100000,
'otp_max' => 999999,
```

Range for 6-digit OTP codes.

---

## Complete Configuration Example

```php
<?php

use Illuminate\Validation\Rules\Password;
use Ssntpl\Neev\Rules\PasswordHistory;
use Ssntpl\Neev\Rules\PasswordUserData;

return [
    // Feature Toggles
    'team' => true,
    'email_verified' => true,
    'require_company_email' => false,
    'free_email_domains' => [],
    'domain_federation' => true,
    'tenant_isolation' => false,
    'tenant_isolation_options' => [
        'subdomain_suffix' => env('NEEV_SUBDOMAIN_SUFFIX', null),
        'allow_custom_domains' => true,
        'single_tenant_users' => false,
    ],
    'tenant_auth' => false,
    'tenant_auth_options' => [
        'default_method' => 'password',
        'sso_providers' => ['entra', 'google', 'okta'],
        'auto_provision' => false,
        'auto_provision_role' => null,
    ],
    'slug' => [
        'min_length' => 2,
        'max_length' => 63,
        'reserved' => ['www', 'api', 'admin', 'app'],
    ],
    'support_username' => false,
    'magicauth' => true,

    // Models
    'team_model' => Ssntpl\Neev\Models\Team::class,
    'user_model' => Ssntpl\Neev\Models\User::class,

    // URLs
    'dashboard_url' => env('NEEV_DASHBOARD_URL', env('APP_URL').'/dashboard'),
    'frontend_url' => env('APP_URL'),

    // MFA
    'multi_factor_auth' => ['authenticator', 'email'],
    'recovery_codes' => 8,

    // OAuth
    'oauth' => [
        'google',
        'github',
    ],

    // Login Tracking
    'record_failed_login_attempts' => true,
    'last_login_attempts_in_days' => 30,

    // GeoIP
    'geo_ip_db' => 'app/geoip/GeoLite2-City.mmdb',
    'edition' => env('MAXMIND_EDITION', 'GeoLite2-City'),
    'maxmind_license_key' => env('MAXMIND_LICENSE_KEY'),

    // Validation
    'username' => [
        'required',
        'string',
        'min:3',
        'max:20',
        'regex:/^(?![._])(?!.*[._]{2})[a-zA-Z0-9._]+(?<![._])$/',
        'unique:users,username',
    ],
    'password' => [
        'required',
        'confirmed',
        Password::min(8)->max(72)->letters()->mixedCase()->numbers()->symbols(),
        PasswordHistory::notReused(5),
        PasswordUserData::notContain(['name', 'email']),
    ],

    // Rate Limiting
    'login_soft_attempts' => 5,
    'login_hard_attempts' => 20,
    'login_block_minutes' => 1,

    // Password Expiry
    'password_soft_expiry_days' => 30,
    'password_hard_expiry_days' => 90,

    // Token Expiry
    'url_expiry_time' => 60,
    'otp_expiry_time' => 15,

    // OTP
    'otp_min' => 100000,
    'otp_max' => 999999,
];
```

---

## Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `NEEV_DASHBOARD_URL` | Post-login redirect URL | `${APP_URL}/dashboard` |
| `NEEV_SUBDOMAIN_SUFFIX` | Subdomain base for tenants | `null` |
| `MAXMIND_LICENSE_KEY` | MaxMind API key | - |
| `MAXMIND_EDITION` | GeoIP database edition | `GeoLite2-City` |

---

## Next Steps

- [Authentication Guide](./authentication.md)
- [API Reference](./api-reference.md)
