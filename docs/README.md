# Neev - Enterprise User Management for Laravel

Neev is a comprehensive Laravel package that provides enterprise-grade user authentication, team management, and security features. It's designed as a complete starter kit for SaaS applications, eliminating the need to build complex user management systems from scratch.

## Table of Contents

1. [Installation](./installation.md)
2. [Configuration](./configuration.md)
3. [Authentication](./authentication.md)
4. [API Reference](./api-reference.md)
5. [Web Routes](./web-routes.md)
6. [Team Management](./teams.md)
7. [Multi-Factor Authentication](./mfa.md)
8. [Multi-Tenancy](./multi-tenancy.md)
9. [Security Features](./security.md)

---

## Features Overview

### Authentication Methods
- **Password-based login** with strong password policies
- **Magic link authentication** (passwordless login via email)
- **Passkey/WebAuthn support** (biometric authentication, hardware keys)
- **OAuth/Social login** (Google, GitHub, Microsoft, Apple)
- **Tenant SSO** (Microsoft Entra ID, Google Workspace, Okta)

### Multi-Factor Authentication
- **TOTP authenticator apps** (Google Authenticator, Authy, 1Password)
- **Email OTP** (6-digit codes via email)
- **Recovery codes** (single-use backup codes)

### Team Management
- Create and manage teams/organizations
- Invite members via email
- Role-based access control
- Domain-based auto-joining (federation)
- Team switching for multi-team users

### Security Features
- Brute force protection with progressive delays
- Account lockout after failed attempts
- Password history to prevent reuse
- Password expiry policies
- Login attempt tracking with GeoIP
- Session management
- Suspicious login detection

### Multi-Tenancy
- Subdomain-based tenant isolation
- Custom domain support with DNS verification
- Per-tenant authentication configuration
- Per-tenant SSO integration

---

## Quick Start

### 1. Install via Composer

```bash
composer require ssntpl/neev
```

### 2. Run the Installation Command

```bash
php artisan neev:install
```

This interactive command will guide you through the setup process.

### 3. Configure Environment

Add the following to your `.env` file:

```env
NEEV_DASHBOARD_URL="${APP_URL}/dashboard"
MAXMIND_LICENSE_KEY="your-maxmind-key"
```

### 4. Run Migrations

```bash
php artisan migrate
```

### 5. Publish Assets (Optional)

```bash
# Publish configuration
php artisan vendor:publish --tag=neev-config

# Publish views
php artisan vendor:publish --tag=neev-views

# Publish migrations
php artisan vendor:publish --tag=neev-migrations
```

---

## Package Structure

```
neev/
├── config/
│   └── neev.php              # Main configuration file
├── database/
│   └── migrations/           # Database migrations
├── resources/
│   ├── lang/                 # Translations
│   └── views/                # Blade templates
├── routes/
│   ├── neev.php             # Web and API routes
│   └── sso.php              # Tenant SSO routes
└── src/
    ├── Commands/             # Artisan commands
    ├── Events/               # Event classes
    ├── Http/
    │   ├── Controllers/      # Route handlers
    │   ├── Middleware/       # Request middleware
    │   └── Requests/         # Form requests
    ├── Mail/                 # Email templates
    ├── Models/               # Eloquent models
    ├── Rules/                # Validation rules
    ├── Services/             # Business logic
    ├── Support/              # Helper classes
    └── Traits/               # Reusable traits
```

---

## Middleware Groups

Neev provides several middleware groups for protecting your routes:

| Middleware | Description |
|------------|-------------|
| `neev:web` | Web authentication with MFA, email verification, and active account check |
| `neev:api` | API token authentication |
| `neev:tenant` | Tenant isolation (resolves tenant from domain) |
| `neev:tenant-web` | Tenant + membership check + web authentication |
| `neev:tenant-api` | Tenant + membership check + API authentication |
| `neev:active-team` | Blocks access when the user's team is inactive/waitlisted |
| `neev:tenant-member` | Ensures user is a member of the current tenant |

### Usage Example

```php
// In your routes/web.php
Route::middleware(['neev:web'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
});

// In your routes/api.php
Route::middleware(['neev:api'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'show']);
});
```

---

## Extending Models

You can extend Neev's models with your own:

```php
// app/Models/User.php
namespace App\Models;

use Ssntpl\Neev\Models\User as NeevUser;

class User extends NeevUser
{
    protected $fillable = [
        'name',
        'username',
        'active',
        'custom_field',
    ];
}
```

Then update your configuration:

```php
// config/neev.php
'user_model' => App\Models\User::class,
'team_model' => App\Models\Team::class,
```

---

## Events

Neev fires the following events that you can listen to:

| Event | Description |
|-------|-------------|
| `Ssntpl\Neev\Events\LoggedInEvent` | Fired when a user logs in |
| `Ssntpl\Neev\Events\LoggedOutEvent` | Fired when a user logs out |

### Example Listener

```php
// app/Listeners/LogSuccessfulLogin.php
namespace App\Listeners;

use Ssntpl\Neev\Events\LoggedInEvent;

class LogSuccessfulLogin
{
    public function handle(LoggedInEvent $event)
    {
        // $event->user contains the logged-in user
        activity()->log("User {$event->user->name} logged in");
    }
}
```

---

## Console Commands

| Command | Description |
|---------|-------------|
| `neev:install` | Interactive setup wizard |
| `neev:download-geoip` | Download MaxMind GeoLite2 database |
| `neev:clean-login-attempts` | Remove old login attempt records |
| `neev:clean-passwords` | Remove old password history |

---

## Requirements

- PHP 8.1+
- Laravel 10.x or 11.x
- Database (MySQL, PostgreSQL, SQLite)

### Optional Dependencies

- MaxMind GeoLite2 account (for IP geolocation)
- Redis/Memcached (for rate limiting)
- Mail server (for email verification and OTP)

---

## License

MIT License. See [LICENSE](../LICENSE) for details.

---

## Support

- Documentation: This folder
- Issues: [GitHub Issues](https://github.com/ssntpl/neev/issues)
- Email: support@ssntpl.com
