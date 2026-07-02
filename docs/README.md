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
| [CLI Commands](./cli-commands.md) | All Artisan commands: tenant provisioning, domains, members, auth/SSO, team lifecycle |

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

## Proposals

Design proposals under review, not yet implemented.

| Document | Status | Description |
|----------|--------|-------------|
| [SPA Cookie Mode](./spa-cookie-mode.md) | Proposed (2026-06-12), target v0.4.5 | HttpOnly-cookie auth + double-submit CSRF for same-origin SPAs. Additive to the existing bearer-token API. Driven by the TAILLOG web rebuild and otper. |

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
│   └── sso.php               # Tenant SSO routes
└── src/
    ├── Commands/              # Artisan commands
    ├── Contracts/             # Interfaces (ContextContainer, HasMembers, etc.)
    ├── Events/                # Auth, MFA, team/tenant lifecycle, and domain events
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
| `neev:login` | TenantMiddleware > ResolveTeamMiddleware > JwtLoginMiddleware (MFA JWT auth) > EnsureTenantMembership > BindContextMiddleware |
| `neev:tenant` | TenantMiddleware (required) > ResolveTeamMiddleware > BindContextMiddleware |

When `tenant` is disabled, tenant-specific middleware are no-ops.

### Middleware Aliases

These can be applied individually to specific routes.

| Alias | Description |
|-------|-------------|
| `neev:active-team` | Blocks access when team is inactive/waitlisted |
| `neev:active-tenant` | Blocks access when tenant is inactive |
| `neev:tenant-member` | Ensures user is a member of the current tenant |
| `neev:resolve-team` | Resolves team from route parameter (slug or ID) |
| `neev:ensure-sso` | Enforces SSO-only access for the current context |
| `neev:password-not-expired` | Blocks access when the user's password has expired |
| `neev:verified-email` | Blocks access until the user's email is verified |

### Usage

```php
// Apply a middleware group to your routes
Route::middleware(['neev:web'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
});

// Apply individual middleware aliases after the group
Route::middleware(['neev:web', 'neev:active-team'])->group(function () {
    Route::get('/projects', [ProjectController::class, 'index']);
});
```

> **Ordering:** Always place `neev:web` or `neev:api` before any alias middleware. Aliases like `neev:active-team` and `neev:ensure-sso` depend on context being resolved by the group middleware first. `BindContextMiddleware` (the last middleware in each group) locks the context as immutable — custom middleware that needs tenant/team/user context should run after the Neev group.

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

Neev fires Laravel's native auth events where semantics match, and its own events for package concepts.

**Laravel native events:**

| Event | Fired when |
|-------|------------|
| `Illuminate\Auth\Events\Registered` | User registers (web, API, OAuth, SSO auto-provision) |
| `Illuminate\Auth\Events\PasswordReset` | Password is reset via a reset link (web or API) |
| `Illuminate\Auth\Events\Lockout` | Login throttle rejects an attempt |

**Neev events (`Ssntpl\Neev\Events\`):**

| Event | Payload | Fired when |
|-------|---------|------------|
| `LoggedIn` | `$user` | User logs in (any method) |
| `LoggedOut` | `$user` | User logs out |
| `PasswordChanged` | `$user` | Password changes (including resets) |
| `EmailVerified` | `$user` | Email is verified for the first time |
| `MfaMethodAdded` | `$user, $method` | An MFA method is configured |
| `MfaMethodRemoved` | `$user, $method` | An MFA method is removed |
| `RecoveryCodesGenerated` | `$user` | Recovery codes are (re)generated |
| `TeamCreated` / `TeamDeleted` | `$team` | A team is created / deleted |
| `MemberAdded` / `MemberRemoved` | `$team, $user` | Team membership changes |
| `TenantCreated` | `$tenant` | A tenant is created |
| `SsoUserProvisioned` | `$user, $owner` | SSO auto-provisions a new user |
| `DomainVerified` | `$domain` | A domain passes DNS verification for the first time |
| `DomainReverified` | `$domain` | A domain that had been failing re-verification passes again |
| `DomainVerificationFailed` | `$domain` | A previously verified domain first fails re-verification |

The model-lifecycle events (`TeamCreated`, `TeamDeleted`, `TenantCreated`, `MemberAdded`, `MemberRemoved`) implement `ShouldDispatchAfterCommit`, so listeners never observe state from a transaction that later rolls back. `EmailVerified` is likewise dispatched after commit.

> **Note:** neev's User model deliberately does not implement `MustVerifyEmail`. If your custom user model does, Laravel's auto-registered `SendEmailVerificationNotification` listener will also react to `Registered` — disable it or neev's own verification mail to avoid duplicate emails.

```php
// app/Listeners/LogSuccessfulLogin.php
use Ssntpl\Neev\Events\LoggedIn;

class LogSuccessfulLogin
{
    public function handle(LoggedIn $event)
    {
        activity()->log("User {$event->user->name} logged in");
    }
}
```

---

## Console Commands

See [CLI Commands](./cli-commands.md) for full reference with options and examples.

| Command | Description |
|---------|-------------|
| `neev:install` | Interactive setup wizard |
| `neev:download-geoip` | Download MaxMind GeoLite2 database |
| `neev:clean-login-attempts` | Remove old login attempt records |
| `neev:tenant:create` | Create a tenant (isolated) or team (shared) |
| `neev:tenant:list` | List tenants or teams |
| `neev:tenant:show` | Show tenant/team details by ID, slug, or domain |
| `neev:domain:add` | Add a domain to a tenant or team |
| `neev:domain:verify` | Verify a domain via DNS TXT record |
| `neev:domain:list` | List domains |
| `neev:member:add` | Add a user to a team (bypasses invitation) |
| `neev:member:remove` | Remove a user from a team |
| `neev:member:list` | List members of a team or tenant |
| `neev:auth:configure` | Configure auth method (password/SSO) for a tenant or team |
| `neev:auth:show` | Display auth configuration |
| `neev:team:activate` | Activate or deactivate a team |

---

## Requirements

- PHP 8.3+
- Laravel 12.x
- MySQL, PostgreSQL, or SQLite

### Optional

- MaxMind GeoLite2 account (IP geolocation for login tracking)
- Redis/Memcached (distributed rate limiting)
- Mail server (email verification, OTP, magic links, invitations)

---

## Support

- [GitHub Issues](https://github.com/ssntpl/neev/issues)
- Email: support@ssntpl.com
