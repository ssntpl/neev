# Configuration Reference

Complete reference for all Neev configuration options in `config/neev.php`.

---

## Identity & Tenancy

### Tenant Isolation

```php
'tenant' => false,
```

Enable multi-tenant isolation. When enabled:
- Users are scoped to tenants via `tenant_id`
- Same email can exist in different tenants
- Models automatically filtered by tenant
- Requires tenant resolution middleware

### Team Management

```php
'team' => false,
```

Enable team/organization management. When enabled:
- Users can create and join teams
- Team invitations and role management
- Team switching for multi-team users
- Requires `teams`, `memberships`, `team_invitations` tables

---

## Authentication

### Username Support

```php
'support_username' => false,
```

When enabled:
- Users can register and login with usernames
- Username field added to registration
- Both email and username accepted for login

### OAuth Providers

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

## Multi-Factor Authentication

### Available Methods

```php
'multi_factor_auth' => ['authenticator', 'email'],
```

| Method | Description |
|--------|-------------|
| `authenticator` | TOTP via authenticator apps (Google Authenticator, Authy, 1Password) |
| `email` | OTP codes sent via email |

### Recovery Codes

```php
'recovery_codes' => 8,
```

Number of single-use recovery codes generated per user.

---

## Email Verification

### Verification Method

```php
'email_verification_method' => 'link',
```

| Value | Description |
|-------|-------------|
| `'link'` | Send clickable verification links |
| `'otp'` | Send 6-digit verification codes |

### OTP Configuration

```php
'otp_length' => 6,
```

OTP code length: 4, 6, or 8 digits.

---

## Rate Limiting

### Progressive Throttling

```php
'login_throttle' => [
    'delay_after' => 3,
    'max_delay_seconds' => 300,
],
```

| Option | Description |
|--------|-------------|
| `delay_after` | Failed attempts before progressive delays start |
| `max_delay_seconds` | Maximum delay in seconds (5 minutes recommended) |

**Behavior**:
1. Attempts 1-3: Normal login speed
2. Attempts 4+: Exponential backoff delays
3. After max delay: Account temporarily locked

---

## Login Tracking

### Storage Method

```php
'log_failed_logins' => false,
```

- `false`: Store in cache (faster, auto-cleanup)
- `true`: Store in database (persistent, auditable)

### Retention Period

```php
'login_history_retention_days' => 30,
```

Days to keep login attempt records. Use `neev:clean-login-attempts` to remove old records.

---

## Password Policies

### Expiry

```php
'password_expiry_days' => 90,
```

Days until password expires. Set to `0` to disable expiry.

> **Note**: This configuration value is available for your application to read, but Neev does not currently ship enforcement middleware. You must implement your own middleware to check password age and redirect users to a password change form.

### Validation Rules

```php
'password' => [
    'required',
    'confirmed',
    Password::min(8)->max(72)->letters()->mixedCase()->numbers()->symbols(),
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

### Username Validation

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

**Valid examples:** `john_doe`, `user123`, `jane.smith`

**Invalid examples:** `_user`, `user..name`, `user_`, `user@domain`

---

## Token Expiry

### URL Tokens

```php
'url_expiry_time' => 60,
```

Minutes before magic links, password reset links, and verification links expire.

### OTP Codes

```php
'otp_expiry_time' => 15,
```

Minutes before one-time passwords expire.

---

## GeoIP Configuration

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
| `license_key` | Your MaxMind license key |

---

## Application URLs

```php
'home' => '/dashboard',
```

Post-authentication redirect URL for Blade flows.

---

## Team Slugs

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

## Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `MAXMIND_LICENSE_KEY` | MaxMind API key for GeoIP | - |
| `MAXMIND_EDITION` | GeoIP database edition | `GeoLite2-City` |

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

    // Authentication
    'support_username' => false,
    'oauth' => ['google', 'github'],

    // Multi-Factor Authentication
    'multi_factor_auth' => ['authenticator', 'email'],
    'recovery_codes' => 8,

    // Verification
    'email_verification_method' => 'link',
    'otp_length' => 6,

    // Expiry
    'url_expiry_time' => 60,
    'otp_expiry_time' => 15,
    'password_expiry_days' => 90,

    // Rate Limiting
    'login_throttle' => [
        'delay_after' => 3,
        'max_delay_seconds' => 300,
    ],

    // Login Tracking
    'log_failed_logins' => true,
    'login_history_retention_days' => 30,

    // GeoIP
    'maxmind' => [
        'db_path' => 'app/geoip/GeoLite2-City.mmdb',
        'edition' => env('MAXMIND_EDITION', 'GeoLite2-City'),
        'license_key' => env('MAXMIND_LICENSE_KEY'),
    ],

    // URLs
    'home' => '/dashboard',

    // Validation
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

    // Team Slugs
    'slug' => [
        'min_length' => 2,
        'max_length' => 63,
        'reserved' => ['www', 'api', 'admin', 'app'],
    ],

    // Models
    'tenant_model' => Ssntpl\Neev\Models\Tenant::class,
    'team_model' => Ssntpl\Neev\Models\Team::class,
    'user_model' => Ssntpl\Neev\Models\User::class,
];
```

---

## Next Steps

- [Authentication Guide](./authentication.md)
- [Multi-Tenancy](./multi-tenancy.md)
- [Architecture](./architecture.md) -- identity strategy design decisions
- [API Reference](./api-reference.md)
