# Installation Guide

This guide will walk you through installing and configuring Neev for your Laravel application.

## Prerequisites

- PHP 8.3 or higher
- Laravel 11.x or 12.x
- Composer
- Database (MySQL, PostgreSQL, or SQLite)

---

## Step 1: Install via Composer

```bash
composer require ssntpl/neev
```

This will install Neev and its dependencies:
- `geoip2/geoip2` - IP geolocation
- `web-auth/webauthn-lib` - WebAuthn/passkey support
- `laravel/socialite` - OAuth integration
- `spomky-labs/otphp` - TOTP generation
- `ssntpl/laravel-acl` - Role & permission system
- `bacon/bacon-qr-code` - QR code generation

---

## Step 2: Run Installation Command

```bash
php artisan neev:install
```

This command must be run on a fresh installation — it aborts if the `users` table already contains records. It will:
1. Publish the configuration file to `config/neev.php` (overwriting any existing copy)
2. Set the `tenant` and `team` config options based on your answers

### Installation Options

The command takes two arguments and interactively prompts for any that are missing:

| Prompt | Config key | Description |
|--------|------------|-------------|
| Would you like to enable multi-tenant isolation? | `tenant` | Users scoped to a tenant; the same email can exist in different tenants |
| Would you like to install team support? | `team` | Enable team/organization features |

You can also pass the answers directly:

```bash
php artisan neev:install yes no   # tenant: yes, teams: no
```

All other features (username support, OAuth, MFA, password policies, etc.) are configured by editing `config/neev.php` — see the [Configuration Reference](./configuration.md).

---

## Step 3: Environment Configuration

Add the following to your `.env` file:

```env
# GeoIP (optional but recommended)
MAXMIND_LICENSE_KEY="your-maxmind-license-key"
MAXMIND_EDITION="GeoLite2-City"

# Optional: secret for signing MFA JWTs (falls back to APP_KEY if not set)
NEEV_JWT_SECRET="your-random-secret"
```

The post-login redirect is not an environment variable — it is the `home` key in `config/neev.php` (default: `/dashboard`).

### Getting a MaxMind License Key

1. Register at [MaxMind GeoLite2](https://www.maxmind.com/en/geolite2/signup)
2. Go to Account > Manage License Keys
3. Generate a new license key
4. Add it to your `.env` file

---

## Step 4: Run Migrations

```bash
php artisan migrate
```

Migrations load automatically from the package — no publishing is required before running them.

This creates the following tables:

| Table | Description |
|-------|-------------|
| `users` | User accounts (includes nullable `tenant_id` for tenant isolation, password, and password history) |
| `otp` | One-time passwords |
| `passkeys` | WebAuthn credentials |
| `multi_factor_auths` | MFA configurations |
| `recovery_codes` | MFA backup codes |
| `access_tokens` | API and login tokens |
| `login_attempts` | Login history and tracking |
| `teams` | Teams/organizations |
| `team_user` | Team-user membership pivot table |
| `team_invitations` | Pending invitations |
| `tenants` | Tenants (isolated identity mode) |
| `domains` | Custom domains and email domains for federation |
| `domain_rules` | Domain-specific security rules |
| `team_auth_settings` | Per-team authentication settings |
| `tenant_auth_settings` | Per-tenant authentication/SSO settings |

---

## Step 5: Download GeoIP Database (Optional)

If you want IP geolocation for login tracking:

```bash
php artisan neev:download-geoip
```

This downloads the MaxMind GeoLite2-City database (~70MB) to your storage directory.

---

## Step 6: Configure Mail

Neev sends emails for:
- Email verification
- Password reset
- Magic link login
- OTP codes
- Team invitations

Ensure your mail configuration is set up in `.env`:

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=your-username
MAIL_PASSWORD=your-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="noreply@yourapp.com"
MAIL_FROM_NAME="${APP_NAME}"
```

---

## Step 7: Configure Session Driver (Optional)

For session management features (logout all devices), use the database session driver:

```env
SESSION_DRIVER=database
```

Then create the sessions table:

```bash
php artisan session:table
php artisan migrate
```

---

## Publishing Assets

### Publish Configuration

```bash
php artisan vendor:publish --tag=neev-config
```

This creates `config/neev.php` where you can customize all settings.

### Publish Migrations

```bash
php artisan vendor:publish --tag=neev-migrations
```

This copies migrations to `database/migrations/` for customization. Publishing is optional — migrations load automatically from the package.

### Publish Views

```bash
php artisan vendor:publish --tag=neev-views
```

This copies Blade templates to `resources/views/vendor/neev/` for customization.

### Publish Routes

```bash
php artisan vendor:publish --tag=neev-routes
```

This copies `routes/neev.php` to your application's `routes/` directory for customization. When a published copy exists, Neev loads it instead of the package routes.

---

## Customizing the User Model

Neev's User model includes the `BelongsToTenant` trait by default, providing automatic tenant scoping via a global scope. When you extend the User model, these traits are inherited — no additional setup is needed for tenant isolation.

### Step 1: Create Custom User Model

```php
// app/Models/User.php
<?php

namespace App\Models;

use Ssntpl\Neev\Models\User as NeevUser;

class User extends NeevUser
{
    protected $fillable = [
        'name',
        'username',
        'active',
        // ...plus any custom columns you add via your own migrations
    ];

    // Add your custom methods and relationships
    public function posts()
    {
        return $this->hasMany(Post::class);
    }
}
```

### Step 2: Update Configuration

```php
// config/neev.php
'user_model' => App\Models\User::class,
```

### Step 3: Update Auth Configuration

```php
// config/auth.php
'providers' => [
    'users' => [
        'driver' => 'eloquent',
        'model' => App\Models\User::class,
    ],
],
```

---

## Customizing the Team Model

```php
// app/Models/Team.php
<?php

namespace App\Models;

use Ssntpl\Neev\Models\Team as NeevTeam;

class Team extends NeevTeam
{
    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'is_public',
        // ...plus any custom columns you add via your own migrations
    ];

    // Add your custom methods
    public function projects()
    {
        return $this->hasMany(Project::class);
    }
}
```

Update configuration:

```php
// config/neev.php
'team_model' => App\Models\Team::class,
```

---

## OAuth Provider Setup

### Google

1. Create a project in [Google Cloud Console](https://console.cloud.google.com/)
2. Enable Google+ API
3. Create OAuth 2.0 credentials
4. Add redirect URI: `https://yourapp.com/oauth/google/callback`

```php
// config/services.php
'google' => [
    'client_id' => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'redirect' => env('GOOGLE_REDIRECT_URI'),
],
```

```env
GOOGLE_CLIENT_ID=your-client-id
GOOGLE_CLIENT_SECRET=your-client-secret
GOOGLE_REDIRECT_URI="${APP_URL}/oauth/google/callback"
```

### GitHub

1. Create an OAuth App in [GitHub Developer Settings](https://github.com/settings/developers)
2. Add callback URL: `https://yourapp.com/oauth/github/callback`

```php
// config/services.php
'github' => [
    'client_id' => env('GITHUB_CLIENT_ID'),
    'client_secret' => env('GITHUB_CLIENT_SECRET'),
    'redirect' => env('GITHUB_REDIRECT_URI'),
],
```

### Microsoft

1. Register an app in [Azure Portal](https://portal.azure.com/)
2. Add redirect URI: `https://yourapp.com/oauth/microsoft/callback`

```php
// config/services.php
'microsoft' => [
    'client_id' => env('MICROSOFT_CLIENT_ID'),
    'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
    'redirect' => env('MICROSOFT_REDIRECT_URI'),
],
```

---

## Production Checklist

Before deploying to production:

- [ ] Set `APP_ENV=production`
- [ ] Set `APP_DEBUG=false`
- [ ] Configure secure mail server
- [ ] Set up Redis for rate limiting
- [ ] Download GeoIP database
- [ ] Configure OAuth providers
- [ ] Test email verification flow
- [ ] Test password reset flow
- [ ] Configure session driver (database recommended)
- [ ] Set up SSL certificates
- [ ] Configure CORS if using API

---

## Troubleshooting

### Routes Not Found

Clear route cache:

```bash
php artisan route:clear
php artisan config:clear
```

### Migrations Already Exist

If you've previously run migrations, check for duplicates:

```bash
php artisan migrate:status
```

### GeoIP Not Working

Ensure the database exists:

```bash
ls storage/app/geoip/
```

If missing, run:

```bash
php artisan neev:download-geoip
```

### OAuth Errors

Check that redirect URIs match exactly (including trailing slashes).

---

## Next Steps

- [Configuration Reference](./configuration.md)
- [Authentication Guide](./authentication.md)
- [Multi-Tenancy](./multi-tenancy.md) -- tenant isolation, identity strategy, SSO
- [Architecture](./architecture.md) -- design decisions and conceptual model
- [API Reference](./api-reference.md)
