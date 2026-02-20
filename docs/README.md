# Neev Documentation

Neev is an enterprise-grade Laravel package for user authentication, team management, and multi-tenancy in SaaS applications. See the [main README](../README.md) for a quick overview and getting started guide.

---

## Guides

Start here to set up and use Neev in your application.

| Guide | Description |
|-------|-------------|
| [Installation](./installation.md) | Step-by-step setup: Composer, migrations, environment, publishing assets |
| [Configuration](./configuration.md) | All `config/neev.php` options with explanations |
| [Authentication](./authentication.md) | Password, magic link, passkey, OAuth, and SSO login flows |
| [MFA](./mfa.md) | Authenticator apps (TOTP), email OTP, and recovery codes |
| [Teams](./teams.md) | Team creation, invitations, roles, domain federation |
| [Multi-Tenancy](./multi-tenancy.md) | Identity strategy, tenant isolation, subdomain/custom domain, enterprise SSO |
| [Security](./security.md) | Brute force protection, password policies, login tracking, session management |

## Reference

Endpoint and route details for building against Neev.

| Reference | Description |
|-----------|-------------|
| [API Reference](./api-reference.md) | Every REST endpoint with request/response examples |
| [Web Routes](./web-routes.md) | All Blade-rendered web routes and view files |

## Architecture

Design decisions and internal patterns — useful when extending Neev or contributing.

| Document | Description |
|----------|-------------|
| [Architecture](./architecture.md) | Identity strategy (shared vs isolated), tenant/team concepts, context lifecycle |
| [Architecture Internals](./architecture-internals.md) | Interfaces, traits, services, coding standards, anti-patterns |

---

## Package Structure

```
neev/
├── config/
│   └── neev.php              # Configuration file
├── database/
│   ├── factories/            # Model factories (testing)
│   └── migrations/           # Database migrations
├── resources/
│   └── views/                # Blade templates (64 files)
├── routes/
│   ├── neev.php              # Web and API routes
│   └── sso.php               # Tenant SSO routes (loaded when tenant_auth enabled)
└── src/
    ├── Commands/              # Artisan commands
    ├── Contracts/             # Interfaces (ContextContainer, HasMembers, etc.)
    ├── Events/                # LoggedInEvent, LoggedOutEvent
    ├── Http/
    │   ├── Controllers/       # Auth, User, Team, Role, TenantDomain, TenantSSO
    │   └── Middleware/        # Auth, tenant, team, context middleware
    ├── Mail/                  # 5 Mailables (verification, OTP, login link, etc.)
    ├── Models/                # User, Team, Tenant, AccessToken, Domain, etc.
    ├── Rules/                 # PasswordHistory, PasswordUserData
    ├── Scopes/                # TenantScope, TeamScope
    ├── Services/              # ContextManager, TenantResolver, TenantSSOManager, etc.
    └── Traits/                # HasTeams, HasMultiAuth, BelongsToTenant, BelongsToTeam, etc.
```

---

## Middleware

### Middleware Groups

These are pre-composed groups you apply to route groups.

| Group | Pipeline |
|-------|----------|
| `neev:web` | TenantMiddleware > ResolveTeamMiddleware > NeevMiddleware (session auth) > EnsureTenantMembership > BindContextMiddleware |
| `neev:api` | TenantMiddleware > ResolveTeamMiddleware > NeevAPIMiddleware (token auth) > EnsureTenantMembership > BindContextMiddleware |
| `neev:tenant` | TenantMiddleware (required) > ResolveTeamMiddleware > BindContextMiddleware |

When `tenant_isolation` is disabled, tenant-specific middleware are no-ops.

### Middleware Aliases

These can be applied individually to specific routes.

| Alias | Description |
|-------|-------------|
| `neev:active-team` | Blocks access when team is inactive/waitlisted |
| `neev:tenant-member` | Ensures user is a member of the current tenant |
| `neev:resolve-team` | Resolves team from route parameter (slug or ID) |
| `neev:ensure-sso` | Enforces SSO-only access for the current context |

### Usage

```php
// Apply a middleware group to your routes
Route::middleware(['neev:web'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
});

// Apply individual middleware aliases
Route::middleware(['neev:web', 'neev:active-team'])->group(function () {
    Route::get('/projects', [ProjectController::class, 'index']);
});
```

---

## Extending Models

Extend Neev's models with your own, then update the config:

```php
// app/Models/User.php
class User extends \Ssntpl\Neev\Models\User
{
    protected $fillable = ['name', 'username', 'active', 'custom_field'];
}
```

```php
// config/neev.php
'user_model' => App\Models\User::class,
'team_model' => App\Models\Team::class,
'tenant_model' => App\Models\Tenant::class,
```

---

## Events

| Event | Fired when |
|-------|------------|
| `Ssntpl\Neev\Events\LoggedInEvent` | User logs in (any method) |
| `Ssntpl\Neev\Events\LoggedOutEvent` | User logs out |

```php
// app/Listeners/LogSuccessfulLogin.php
use Ssntpl\Neev\Events\LoggedInEvent;

class LogSuccessfulLogin
{
    public function handle(LoggedInEvent $event)
    {
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

- PHP 8.3+
- Laravel 11.x or 12.x
- MySQL, PostgreSQL, or SQLite

### Optional

- MaxMind GeoLite2 account (IP geolocation for login tracking)
- Redis/Memcached (distributed rate limiting)
- Mail server (email verification, OTP, magic links, invitations)

---

## Support

- [GitHub Issues](https://github.com/ssntpl/neev/issues)
- Email: support@ssntpl.com
