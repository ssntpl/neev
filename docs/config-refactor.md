# Neev Config Refactor Plan

> **Status: Implemented in v2.0.0.** This document is the design rationale. For upgrade instructions, see the [Migration Guide in CHANGELOG.md](../CHANGELOG.md#migration-guide).

## Core Identity (Before → After)

### Before: 3 flags, 8 combinations, some invalid
```php
'identity_strategy' => 'shared' | 'isolated',
'tenant_isolation' => true | false,
'team' => true | false,
'tenant_isolation_options' => [
    'strict' => true,
    'subdomain_suffix' => '.app.com',
    'allow_custom_domains' => true,
],
```

### After: 2 flags, 4 combinations, all valid
```php
'tenant' => false,
'team' => false,
```

| tenant | team | Mode | Example |
|--------|------|------|---------|
| false | false | Simple app | Basic Laravel app |
| false | true | Collaboration | Slack, Notion |
| true | false | Multi-tenant isolated | White-label SaaS |
| true | true | Enterprise SaaS | Salesforce, Workday |

### Key decisions
- `tenant = true` always means isolated identity. Users scoped to tenant. Same email can exist in different tenants.
- `team` is optional sub-grouping in both modes (within app or within tenant).
- Tenant is the security/isolation boundary. Team is the organizational boundary. They never overlap.
- Domains/subdomains are a tenant-only concept. Team never owns domains.
- Strict scoping is always on (no config). No tenant context = empty results, never silently return all rows.
- Subdomain suffix removed. Package resolves tenant via domain table lookup. Consuming app creates domain records (subdomain or custom) however it wants.
- `allow_custom_domains` removed. Product/UI decision for the consuming app.

---

## Removed configs

| Config | Why removed |
|--------|------------|
| `identity_strategy` | Replaced by `tenant = true/false` |
| `tenant_isolation` | Implied by `tenant = true` |
| `tenant_isolation_options.strict` | Always strict. Not configurable. |
| `tenant_isolation_options.subdomain_suffix` | Package just looks up host in domains table |
| `tenant_isolation_options.allow_custom_domains` | App's UI decision |
| `tenant_auth` | Redundant — if no entity has SSO configured in DB, nothing happens. Routes self-guard. |
| `tenant_auth_options.default_method` | Always 'password' when no DB record. Hardcode. |
| `tenant_auth_options.sso_providers` | Package checks if Socialite driver class exists at runtime instead. |
| `tenant_auth_options.auto_provision` | Per-tenant in DB. Hardcode default to false. |
| `tenant_auth_options.auto_provision_role` | Per-tenant in DB. Hardcode default to null. |
| `email_verified` | Package provides verification flow + middleware. Doesn't enforce. App applies middleware where needed. |
| `require_company_email` | Removed from Neev entirely. Separate email reputation package. |
| `free_email_domains` | Removed with require_company_email. |
| `domain_federation` | Should be per-domain behavior (enforce flag in DB), not a global toggle. |
| `magicauth` | Dead config — never checked in code. Magic link feature always available. |
| `sso_providers` | Package checks if driver class exists at runtime. |
| `dashboard_url` | Replaced by `home` (just a path, not full URL). Blade flows use `redirect($home)`. |
| `frontend_url` | Removed. API flows return JSON, no redirects. SPA passes `redirect_uri` for OAuth/SSO callbacks. |
| `otp_min` / `otp_max` | Replaced by `otp_length`. |
| `login_soft_attempts` / `login_hard_attempts` / `login_block_minutes` | Replaced by progressive delay: `login_throttle`. |
| `password_soft_expiry_days` / `password_hard_expiry_days` | Replaced by single `password_expiry_days`. Warning period is app's UI concern via helper methods. |

---

## Removed services and middleware enforcement

| Item | Action | Reason |
|------|--------|--------|
| `EmailDomainValidator` service | Remove from package | Extract to separate email reputation package |
| `EnsureTeamIsActive` middleware | Keep but don't auto-apply | App decides where to use it |
| `EnsureEmailIsVerified` middleware | Keep but don't auto-apply | App decides where to use it |
| `EnsureTenantIsActive` middleware | Add, don't auto-apply | App decides where to use it |
| `EnsurePasswordNotExpired` middleware | Add, don't auto-apply | App decides where to use it |
| Free email waitlist logic in registration | Remove | App handles via events/custom controllers |
| Auto-block login for unverified email | Remove | App applies middleware where needed |

### Middleware toolkit (all opt-in, none auto-applied)
- `EnsureEmailIsVerified` — checks `$user->hasVerifiedEmail()`
- `EnsureTeamIsActive` — checks `$team->isActive()`
- `EnsureTenantIsActive` — checks `$tenant->isActive()`
- `EnsurePasswordNotExpired` — checks password expiry, returns 403 with `password_expired` error

---

## Model changes

### Domain model
- Remove `team_id` column and `team()` relationship
- Keep `tenant_id` only
- Update `markAsPrimary()` to scope by `tenant_id` instead of `team_id`
- Resolution is just: `Domain::where('domain', $host)->whereNotNull('verified_at')->first()`

### Team model
- Keep `activated_at` and `inactive_reason` columns (useful infrastructure)
- Keep `isActive()` helper
- Team creation logic moves to published starter kit controllers (not package decision)
- Teams are always created active. App deactivates based on its own logic.

### TenantScope / TeamScope
- Fix dead code bug: `shouldApplyScope()` returns early before strict check runs
- Always enforce `WHERE 1 = 0` when no context (not configurable)

### Null context safety
- `tenant = true` means "feature available", not "context always present"
- `team = true` means "feature available", not "context always present"
- Package handles null context gracefully everywhere (middleware skips, scopes return empty, no crashes)

---

## SSO architecture

Two separate systems:

| | `oauth` | Per-entity SSO |
|---|---|---|
| What | Social login (Google, GitHub) | Enterprise SSO (Entra, Okta) |
| Who configures | App developer (config) | Tenant/team admin (DB) |
| Credentials | App-wide (config/services.php) | Per-entity (TenantAuthSettings/TeamAuthSettings) |
| Works without team/tenant | Yes | No — needs an owner entity |
| User choice | User picks provider | Entity forces provider |

- `tenant = true` → SSO config owned by Tenant
- `tenant = false, team = true` → SSO config owned by Team
- SSO provider validation: package checks if Socialite driver class exists at runtime (no config whitelist)

---

## Per-tenant overridable configs (isolated mode only)

These are app-wide defaults in config, overridable per-tenant in `TenantAuthSettings` DB table.
All nullable in DB — null means fall back to app config.

```
support_username          (bool)
username rules            (JSON)
password rules            (JSON)
password_expiry_days      (int)
multi_factor_auth         (JSON, e.g. ['authenticator'])
login_throttle            (JSON, delay_after + max_delay_seconds)
otp_expiry_time           (int)
url_expiry_time           (int)
```

Resolution pattern:
```php
public function getPasswordExpiryDays(): int
{
    return $this->authSettings?->password_expiry_days
        ?? config('neev.password_expiry_days', 90);
}
```

NOT per-tenant (always app-wide):
- `otp_length` — infrastructure, affects templates/UI
- `email_verification_method` — infrastructure, changes entire flow
- `oauth` — app-wide Socialite drivers
- `log_failed_logins` — architectural decision
- `recovery_codes` — minor, not worth per-tenant complexity
- GeoIP, URLs, slugs, models

---

## Final config

```php
return [
    // Identity
    'tenant' => false,
    'team' => false,

    // Authentication
    'support_username' => false,
    'oauth' => [],

    // MFA
    'multi_factor_auth' => ['authenticator', 'email'],
    'recovery_codes' => 8,

    // Verification
    'email_verification_method' => 'link',   // 'link' or 'otp'
    'otp_length' => 6,

    // Expiry
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
        'required', 'string', 'min:3', 'max:20',
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

## Helper methods (package provides, app uses)

```php
// Email verification
$user->hasVerifiedEmail()

// Password expiry
$user->passwordExpiresAt()              // Carbon|null
$user->isPasswordExpired()              // bool
$user->isPasswordExpiringSoon($days)    // bool, app passes its own warning threshold

// Team/Tenant state
$team->isActive()
$tenant->isActive()
```

---

## Extractable Auth Traits

The auth infrastructure (emails, passwords, MFA, tokens, OAuth) should be usable on any authenticatable model, not just the tenant-scoped User.

### Package provides:
- `NeevAuthenticatable` trait — core auth machinery (emails, passwords, MFA, tokens, recovery codes)
- Works on any model extending `Illuminate\Foundation\Auth\User`
- No tenant/team coupling — the trait is purely about authentication

### Package does NOT provide:
- Platform user model or concept
- Platform middleware group or guard
- Tenant provisioning logic
- Shadow linking between different user models

These are app-level architectural decisions. The package gives tools, the app composes them.

### Example: SaaS with separate platform admin

```php
// app/Models/PlatformUser.php
class PlatformUser extends Authenticatable
{
    use NeevAuthenticatable; // emails, passwords, MFA, tokens
    // No BelongsToTenant, no HasTeams
    // This is the SaaS admin who creates/manages tenants
}

// app/Models/User.php (extends Neev's User or uses traits)
class User extends Authenticatable
{
    use NeevAuthenticatable, BelongsToTenant, HasTeams;
    // Tenant-scoped user
}
```

```php
// config/auth.php — standard Laravel multi-guard setup
'guards' => [
    'platform' => ['driver' => 'session', 'provider' => 'platform_users'],
    'web'      => ['driver' => 'session', 'provider' => 'users'],
],
'providers' => [
    'platform_users' => ['driver' => 'eloquent', 'model' => App\Models\PlatformUser::class],
    'users'          => ['driver' => 'eloquent', 'model' => App\Models\User::class],
],
```

```php
// routes/web.php
// Platform routes (main domain) — standard Laravel auth, no tenant context
Route::middleware('auth:platform')->group(function () {
    Route::get('/dashboard', PlatformDashboardController::class);
    Route::post('/tenants', CreateTenantController::class);
});

// Tenant routes (subdomains) — Neev handles auth + tenant resolution
Route::middleware('neev:web')->group(function () {
    // ...
});
```

### Example: Simple SaaS with admin role (no separate model)

```php
// Just use roles on the same user model
// No PlatformUser needed — admin is a role

// Middleware
Route::middleware(['auth', 'role:super-admin'])->group(function () {
    Route::get('/admin/tenants', TenantAdminController::class);
});
```

### Example: Platform-as-tenant (first tenant is special)

```php
// The "platform" is just the first tenant with elevated privileges
// No separate model — tenant #1 is the platform tenant
// Platform admins are users in tenant #1 with admin role
$platformTenant = Tenant::where('slug', 'platform')->first();
```

### Tenant provisioning is app logic

The consuming app handles tenant creation however it wants:

```php
// In the app's controller, not in Neev
class CreateTenantController
{
    public function __invoke(Request $request)
    {
        $tenant = Tenant::create([
            'name' => $request->name,
            'slug' => $request->slug,
        ]);

        // Create subdomain
        $tenant->domains()->create([
            'domain' => $tenant->slug . '.myapp.com',
            'verified_at' => now(), // auto-verify since we own *.myapp.com
            'is_primary' => true,
        ]);

        // Create initial admin user inside tenant
        $admin = User::create([
            'name' => $request->user()->name,
            'tenant_id' => $tenant->id,
        ]);

        // ... assign admin role, send welcome email, etc.
    }
}
```

---

## Separate package: Email Reputation
See: docs/email-reputation-package.md
