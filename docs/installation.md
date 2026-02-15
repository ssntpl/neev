# Installation Guide

This guide will walk you through installing and configuring Neev for your Laravel application.

## Prerequisites

- PHP 8.3 or higher
- Laravel 10.x, 11.x, or 12.x
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

This interactive command will:
1. Publish the configuration file
2. Publish database migrations
3. Ask about feature toggles
4. Configure your environment

### Installation Options

During installation, you'll be asked about:

| Option | Description |
|--------|-------------|
| Team Management | Enable team/organization features |
| Email Verification | Require email verification before access |
| Username Support | Allow login with username (in addition to email) |
| Magic Links | Enable passwordless login via email |
| Domain Federation | Auto-join teams based on email domain |
| Tenant Isolation | Enable multi-tenancy with domain separation |

---

## Step 3: Environment Configuration

Add the following to your `.env` file:

```env
# Required
NEEV_DASHBOARD_URL="${APP_URL}/dashboard"

# GeoIP (optional but recommended)
MAXMIND_LICENSE_KEY="your-maxmind-license-key"
MAXMIND_EDITION="GeoLite2-City"

# Tenant isolation (if enabled)
NEEV_SUBDOMAIN_SUFFIX=".yourapp.com"
```

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

This creates the following tables:

| Table | Description |
|-------|-------------|
| `users` | User accounts |
| `emails` | User email addresses (multiple per user) |
| `passwords` | Password history |
| `otps` | One-time passwords |
| `passkeys` | WebAuthn credentials |
| `multi_factor_auths` | MFA configurations |
| `recovery_codes` | MFA backup codes |
| `access_tokens` | API and login tokens |
| `login_attempts` | Login history and tracking |
| `teams` | Teams/organizations |
| `team_user` | Team-user membership pivot table |
| `team_invitations` | Pending invitations |
| `domains` | Email domains for federation |
| `domain_rules` | Domain-specific security rules |

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

This copies migrations to `database/migrations/` for customization.

### Publish Views

```bash
php artisan vendor:publish --tag=neev-views
```

This copies Blade templates to `resources/views/vendor/neev/` for customization.

### Publish Routes

```bash
php artisan vendor:publish --tag=neev-routes
```

This copies route files to `routes/` for customization.

---

## Customizing the User Model

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
        'avatar',
        'phone',
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
        'logo',
        'description',
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
- [API Reference](./api-reference.md)
