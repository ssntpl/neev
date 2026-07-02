# Multi-Tenancy

Complete guide to implementing multi-tenant SaaS applications with Neev.

> For the architectural rationale behind these concepts, see [Architecture](./architecture.md).

---

## Overview

Neev's multi-tenancy features allow you to:

- Isolate users per tenant with a single `tenant` config flag
- Isolate organizations by subdomain or custom domain
- Configure per-tenant authentication methods (stored in the database)
- Support enterprise SSO (Microsoft Entra ID, Google Workspace, Okta)
- Auto-provision users from identity providers
- Scope any Eloquent model to the current tenant or team automatically

---

## Identity Modes

Neev's identity model is controlled by two orthogonal booleans in `config/neev.php`:

```php
// config/neev.php

// Multi-tenant isolation. Users scoped to tenant.
// Same email can exist in different tenants.
'tenant' => false,

// Team sub-grouping. Optional in both tenant and non-tenant modes.
'team' => false,
```

### Shared Identity (`tenant => false`, default)

Users are global. There is no tenant boundary. With `team => true`, a single user can belong to multiple teams — teams serve as collaboration containers.

Best for: GitHub-style platforms, project management tools, collaborative SaaS.

### Tenant Isolation (`tenant => true`)

Users are scoped inside a **tenant** (an identity boundary) via a `tenant_id` column on the `users` table. The same email can exist in different tenants. The tenant is resolved *before* authentication so Neev knows which identity provider to use.

In tenant mode, Neev resolves a `Tenant` model from the request. With `team => true`, teams still exist as collaboration containers *within* a tenant.

Best for: white-label SaaS, regulated industries.

> **Key distinction**: Tenant = identity boundary (who can log in). Team = collaboration boundary (who works together). See [Architecture](./architecture.md) for the full conceptual model.

### Choosing Your Mode

| If you need... | Config |
|----------------|--------|
| Users joining multiple orgs (GitHub/Slack model) | `tenant: false`, `team: true` |
| Simple app with no org concept | `tenant: false`, `team: false` |
| Same email in different orgs (white-label) | `tenant: true` |
| Per-org SSO with separate identity providers | `tenant: true` (or `team: true`) + auth settings in DB |
| Subdomain/custom-domain org access (acme.app.com) | `tenant: true` + verified `domains` records |
| Regulatory data isolation between orgs | `tenant: true` |

Per-tenant and per-team authentication (password vs SSO) is **not** a config toggle — it lives in the `tenant_auth_settings` and `team_auth_settings` database tables. See [Tenant-Driven Authentication](#tenant-driven-authentication).

---

## Configuration

### Enable Tenant Isolation

```php
// config/neev.php
'tenant' => true,   // Multi-tenant isolation
'team' => true,     // Optional: team sub-grouping within tenants
```

There are no other tenant-related config keys. Domain-based access (subdomains and custom domains) is managed through the `domains` table, and per-tenant auth settings live in the `tenant_auth_settings` table.

---

## How Isolation Works

When `tenant => true`:

- Each user belongs to a tenant via a nullable `tenant_id` column on the `users` table (`NULL` = platform-level user)
- The `users` table has a unique constraint on `(tenant_id, email)`, so the same email can exist in different tenants
- Neev's User model includes the `BelongsToTenant` trait, so all user queries are automatically scoped to the resolved tenant via the `TenantScope` global scope
- The `EnsureTenantMembership` middleware validates that the authenticated user belongs to the resolved tenant — directly via `tenant_id`, or indirectly through membership in one of the tenant's teams

The `tenant_id` column is included in the base migrations — no additional setup is required beyond enabling the config.

When `tenant => false`, the `tenant_id` columns remain `NULL` and the global scope is a no-op — all queries run unscoped.

### Config vs Trait

The `tenant` config key controls the **infrastructure**: tenant resolver, middleware, SSO routes. It determines whether Neev resolves tenants from the `X-Tenant` header and request host.

The `BelongsToTenant` trait controls **per-model scoping**. Adding the trait to a model opts that model into automatic query scoping and `tenant_id` auto-assignment — regardless of the `tenant` config value. This means you can use `BelongsToTenant` on your own models even in simpler setups where you manage the tenant context manually via `TenantResolver::setCurrentTenant()`.

---

## Tenant Resolution

The `TenantResolver` (a request-scoped singleton) resolves the current tenant — it only runs when `tenant => true`. Priority order:

1. **X-Tenant Header** -- Resolve by tenant ID (numeric), slug, or a domain registered in the `domains` table
2. **Request Host** -- Look up the full host (subdomain or custom domain) in the `domains` table

Host lookups only match **verified** domains and are cached for 5 minutes. A domain owned directly by a tenant resolves that tenant; a domain owned by a team resolves the team's tenant.

### Using the X-Tenant Header (API)

For API requests where domain routing isn't available, use the `X-Tenant` header:

```bash
# By tenant ID
curl -H "X-Tenant: 42" -H "Authorization: Bearer {token}" https://api.yourapp.com/resource

# By tenant slug
curl -H "X-Tenant: acme-corp" -H "Authorization: Bearer {token}" https://api.yourapp.com/resource

# By domain
curl -H "X-Tenant: app.acme.com" -H "Authorization: Bearer {token}" https://api.yourapp.com/resource
```

### Accessing the Current Tenant

```php
use Ssntpl\Neev\Services\TenantResolver;

$resolver = app(TenantResolver::class);

// The resolved Tenant model
$tenant = $resolver->currentTenant();

// The resolved context container (Tenant, or Team when set manually)
$context = $resolver->resolvedContext();

// Resolution metadata
$resolver->resolvedVia();                  // 'header', 'custom', or 'manual'
$resolver->isResolvedDomainVerified();     // Whether the domain is verified
$resolver->currentId();                     // Context ID (Tenant ID or Team ID)
$resolver->isEnabled();                     // true when config('neev.tenant') is enabled

// Run code in a specific tenant context (useful for platform provisioning)
$resolver->runInContext($tenant, function () {
    $user = User::create([...]);         // tenant_id auto-set
});
```

---

## Domain-Based Tenancy

### How It Works

1. User accesses `acme.yourapp.com` (or `app.acme.com`)
2. `TenantMiddleware` passes the host to `TenantResolver::resolve()`
3. The host is looked up in the `domains` table (only verified domains match)
4. The domain's owner (Tenant, or a Team belonging to a Tenant) determines the tenant
5. Tenant context is set for the request

Subdomains are not derived from slugs at request time — every host (subdomain or custom domain) must exist as a verified `Domain` record. Subdomains added via the tenant-domains API with `type: subdomain` are auto-verified; custom domains require DNS verification.

### Tenant & Team Slugs

Slugs are auto-generated from names and can be used for `X-Tenant` header resolution:

```php
$team = Team::create(['name' => 'Acme Corporation']);
// $team->slug = 'acme-corporation'
```

### Slug Configuration

```php
// config/neev.php
'slug' => [
    'min_length' => 2,
    'max_length' => 63,
    'reserved' => ['www', 'api', 'admin', 'app', 'mail', 'ftp', 'cdn', 'assets', 'static'],
],
```

---

## Custom Domains

Allow tenants to use their own domains.

### Add Custom Domain

```bash
curl -X POST https://yourapp.com/neev/tenant-domains \
  -H "Authorization: Bearer {token}" \
  -d '{"domain": "app.acme.com"}'
```

**Response:**

```json
{
  "message": "Domain added successfully.",
  "data": {
    "id": 1,
    "domain": "app.acme.com",
    "verified_at": null
  },
  "verification_token": "abc123...",
  "dns_record": {
    "type": "TXT",
    "name": "_neev-verification.app.acme.com",
    "value": "abc123..."
  }
}
```

### DNS Verification

Tenant must add a TXT record named `_neev-verification.{domain}` whose value is the verification token:

```
_neev-verification.app.acme.com.  TXT  "abc123..."
```

### Verify Domain

```bash
curl -X POST https://yourapp.com/neev/tenant-domains/1/verify \
  -H "Authorization: Bearer {token}"
```

### Set Primary Domain

```bash
curl -X POST https://yourapp.com/neev/tenant-domains/1/primary \
  -H "Authorization: Bearer {token}"
```

### Web Domain Resolution

```php
$team->webDomain;  // Returns the primary verified domain, or null
```

---

## ContextManager

The `ContextManager` is a request-scoped singleton that holds the resolved tenant, team, and user for the current request. It is populated by middleware and becomes immutable after binding.

```php
use Ssntpl\Neev\Services\ContextManager;

$context = app(ContextManager::class);

$context->currentTenant();    // Tenant model or null
$context->currentTeam();      // Team model or null
$context->currentUser();      // User model or null
$context->currentContext();   // Tenant (tenant mode) or Team, or null
$context->isBound();          // true after BindContextMiddleware runs
```

The context lifecycle:
1. **TenantMiddleware** resolves tenant/team from the request
2. **ResolveTeamMiddleware** resolves team from route parameter
3. **Auth middleware** authenticates the user
4. **EnsureTenantMembership** checks membership
5. **BindContextMiddleware** locks the context (immutable after this)
6. Context is cleared after the response is sent

### In Controllers

```php
public function index(ContextManager $context)
{
    $tenant = $context->currentTenant();
    $team = $context->currentTeam();
    $user = $context->currentUser();
    // ...
}
```

### Console & Queue Context

`ContextManager` is request-scoped — it is cleared after each HTTP request. In artisan commands or queue jobs, there is no HTTP request, so you must set the tenant context manually.

**Using `runInContext()` (recommended):**

`runInContext()` temporarily sets the tenant context for a callback, then restores the previous state — even if the callback throws an exception. This is the safest approach for any code that needs to operate within a tenant context outside of a request.

```php
$resolver = app(TenantResolver::class);

$resolver->runInContext($tenant, function () {
    // All scoped queries and auto-assignment work within this callback
    $projects = Project::all(); // Scoped to $tenant
    $user = User::create([...]); // tenant_id auto-set
});
// Previous context (or no context) is restored here
```

**Platform provisioning example:**

When creating tenant resources from platform context (e.g., provisioning the first user for a new tenant), `runInContext()` ensures all `BelongsToTenant` models get the correct `tenant_id` automatically:

```php
$resolver->runInContext($tenant, function () use ($data) {
    $user = User::create(['name' => $data['name'], 'email' => $data['email']]);
    // tenant_id set automatically
});
```

Note that `tenant_id` is **not** mass-assignable on the User model. Outside of `runInContext()`, set it explicitly before saving:

```php
$user = User::model()->fill(['name' => $data['name'], 'email' => $data['email']]);
$user->tenant_id = $tenant->id;
$user->save();
```

**Using `setCurrentTenant()` (manual):**

For long-running processes where you need context to persist:

```php
$resolver = app(TenantResolver::class);
$resolver->setCurrentTenant($team);

// Now scoped queries and ContextManager work
$projects = Project::all(); // Scoped to $team
```

**In queue jobs:**

Serialize the tenant/team ID in the job payload and restore context in the `handle()` method:

```php
class ProcessTenantReport implements ShouldQueue
{
    public function __construct(
        private int $teamId,
    ) {}

    public function handle(): void
    {
        $team = Team::find($this->teamId);
        $resolver = app(TenantResolver::class);

        $resolver->runInContext($team, function () {
            // All scoped queries now work
            $projects = Project::all(); // Scoped to $team
        });
    }
}
```

> **Important:** Do not rely on the `ContextManager` being populated in queue workers. Always pass tenant/team IDs explicitly in job payloads and restore context at the start of `handle()`. The `ContextManager` singleton is shared across jobs in a long-running worker, so failing to set context could leak data between tenants.

---

## Tenant Middleware

### Available Middleware Groups

| Group | Description |
|------------|-------------|
| `neev:web` | Session authentication for web routes (includes tenant resolution when enabled) |
| `neev:api` | Token authentication for API routes (includes tenant resolution when enabled) |
| `neev:login` | MFA JWT authentication (used for `POST /neev/mfa/otp/verify`) |
| `neev:tenant` | Tenant resolution only, no auth — uses `TenantMiddleware:required`, returns 404 when no tenant resolves |

### Middleware Aliases

| Alias | Middleware |
|-------|------------|
| `neev:active-team` | `EnsureTeamIsActive` |
| `neev:active-tenant` | `EnsureTenantIsActive` |
| `neev:tenant-member` | `EnsureTenantMembership` |
| `neev:resolve-team` | `ResolveTeamMiddleware` |
| `neev:ensure-sso` | `EnsureContextSSO` |
| `neev:password-not-expired` | `EnsurePasswordNotExpired` |
| `neev:verified-email` | `EnsureEmailIsVerified` |

### Using Middleware

```php
// routes/web.php
Route::middleware(['neev:web'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
});

// routes/api.php
Route::middleware(['neev:api'])->group(function () {
    Route::get('/data', [DataController::class, 'index']);
});
```

### Middleware Ordering

The middleware groups run in this order:

**`neev:web`**: TenantMiddleware (resolve tenant) → ResolveTeamMiddleware (resolve team) → NeevMiddleware (authenticate user) → EnsureTenantMembership (check membership) → BindContextMiddleware (lock context)

**`neev:api`**: TenantMiddleware (resolve tenant) → ResolveTeamMiddleware (resolve team) → NeevAPIMiddleware (authenticate user) → EnsureTenantMembership (check membership) → BindContextMiddleware (lock context)

When `tenant` is disabled, the tenant-specific middleware are no-ops. Authentication always runs before the membership check. This ensures `$request->user()` is available when `EnsureTenantMembership` validates that the user belongs to the current tenant.

### TenantMiddleware Behavior

1. Skips entirely when `tenant => false`
2. Resolves the tenant via `TenantResolver` (X-Tenant header, then host lookup in the `domains` table)
3. If no tenant resolves: returns 404 in `required` mode (`neev:tenant` group), otherwise passes through
4. If the resolved domain is not verified: returns 403
5. Sets the `tenant` attribute on the request and proceeds

---

## Tenant-Driven Authentication

Per-tenant authentication method configuration. There is no config toggle for this — auth settings live in the database (`tenant_auth_settings` for tenants, `team_auth_settings` for teams). When no settings row exists, the tenant defaults to password authentication.

### Configuring via CLI

```bash
# Configure SSO for a tenant
php artisan neev:auth:configure --tenant=acme --method=sso \
    --sso-provider=entra --sso-client-id=... --sso-client-secret=... --sso-tenant-id=...

# Configure auth for a team (non-tenant mode)
php artisan neev:auth:configure --team=acme --method=password

# Show current auth settings
php artisan neev:auth:show --tenant=acme
```

### TenantAuthSettings / TeamAuthSettings Models

Each tenant (or team) can have custom auth settings:

```php
$tenant->authSettings()->create([
    'auth_method' => 'sso',           // 'password' or 'sso'
    'sso_provider' => 'entra',        // 'entra', 'google', or 'okta'
    'sso_client_id' => 'client-id',
    'sso_client_secret' => 'client-secret',  // encrypted automatically via cast
    'sso_tenant_id' => 'azure-tenant-id',
    'auto_provision' => true,
    'auto_provision_role' => 'member',
]);
```

Provider-specific extras (Okta base URL, domain restrictions, etc.) go in the `sso_extra_config` JSON column and are merged into the Socialite config.

### Checking Auth Method

Both `Tenant` and `Team` (via the `HasTenantAuth` trait) expose the same API. Settings are cached for 30 minutes:

```php
$tenant->getAuthMethod();        // 'password' or 'sso'
$tenant->requiresSSO();          // true if SSO is required
$tenant->hasSSOConfigured();     // true if SSO is properly configured
$tenant->getSSOProvider();       // 'entra', 'google', or 'okta'
$tenant->allowsAutoProvision();  // true if SSO users are auto-created
$tenant->getAutoProvisionRole(); // role assigned to auto-provisioned users
```

---

## Enterprise SSO

### Supported Providers

| Provider | ID | Description |
|----------|-------|-------------|
| Microsoft Entra ID | `entra` | Azure Active Directory |
| Google Workspace | `google` | Google corporate accounts |
| Okta | `okta` | Okta Identity |

### SSO Configuration

Each provider requires specific configuration:

#### Microsoft Entra ID (Azure AD)

```php
$tenant->authSettings()->create([
    'auth_method' => 'sso',
    'sso_provider' => 'entra',
    'sso_client_id' => 'your-app-id',
    'sso_client_secret' => 'your-client-secret',  // encrypted automatically via cast
    'sso_tenant_id' => 'your-azure-tenant-id',
]);
```

Azure App Registration:
1. Go to Azure Portal > App Registrations
2. Create new registration
3. Add redirect URI: `https://tenant.yourapp.com/sso/callback`
4. Generate client secret
5. Note the Application (client) ID and Directory (tenant) ID

#### Google Workspace

```php
$tenant->authSettings()->create([
    'auth_method' => 'sso',
    'sso_provider' => 'google',
    'sso_client_id' => 'your-client-id',
    'sso_client_secret' => 'your-client-secret',
    'sso_extra_config' => ['hd' => 'acme.com'],  // Restrict to domain
]);
```

#### Okta

```php
$tenant->authSettings()->create([
    'auth_method' => 'sso',
    'sso_provider' => 'okta',
    'sso_client_id' => 'your-client-id',
    'sso_client_secret' => 'your-client-secret',
    'sso_extra_config' => ['base_url' => 'https://acme.okta.com'],
]);
```

---

## SSO Flow

### 1. Get Tenant Auth Config

```bash
curl -X GET https://acme.yourapp.com/api/tenant/auth
```

**Response:**

```json
{
  "auth_method": "sso",
  "sso_enabled": true,
  "sso_provider": "entra",
  "sso_redirect_url": "https://acme.yourapp.com/sso/redirect"
}
```

### 2. Redirect to SSO

```http
GET /sso/redirect?email=user@acme.com
```

User is redirected to the identity provider.

### 3. Handle Callback

After authentication, the user is redirected to:

```http
GET /sso/callback?code=auth-code&state=...
```

Neev:
1. Exchanges code for user info
2. Finds or creates the user
3. Ensures tenant membership
4. Logs in the user

### 4. SPA Flow

For single-page applications, pass a `redirect_uri`:

```http
GET /sso/redirect?redirect_uri=https://acme.yourapp.com/app
```

After SSO, user is redirected with the token in the URL fragment (not query parameter) to prevent server-side logging:

```
https://acme.yourapp.com/app#token=1|abc123...&auth_state=authenticated&email_verified=true&expires_in=1440
```

Your SPA should extract the token from `window.location.hash`:

```javascript
const hash = window.location.hash.substring(1);
const params = new URLSearchParams(hash);
const token = params.get('token');

// Store and use for API calls
localStorage.setItem('auth_token', token);

// Clean the URL
window.history.replaceState(null, '', window.location.pathname);
```

> **Security:** The `redirect_uri` must match a verified domain belonging to the tenant. Neev validates this to prevent open redirect attacks. The URL fragment is never sent to the server in HTTP requests, making it safer than query parameters for token transport.

---

## Auto-Provisioning

Automatically create users on SSO login.

### Enable Auto-Provisioning

Auto-provisioning is configured per tenant (or team) in its auth settings — it is disabled by default:

```php
$tenant->authSettings()->update([
    'auto_provision' => true,
    'auto_provision_role' => 'member',
]);
```

Or via CLI:

```bash
php artisan neev:auth:configure --tenant=acme --method=sso \
    --auto-provision --auto-provision-role=member ...
```

### How It Works

1. User authenticates via SSO
2. If user doesn't exist, create account
3. If not team member, add membership
4. Assign default role

### Disabling Auto-Provisioning

When disabled:
- Only existing team members can authenticate
- New SSO users are rejected
- Admins must pre-create accounts

---

## TenantSSOManager Service

Manages SSO configuration and authentication:

```php
use Ssntpl\Neev\Services\TenantSSOManager;

$ssoManager = app(TenantSSOManager::class);

// Build Socialite driver for tenant
$driver = $ssoManager->buildSocialiteDriver($tenant);

// Handle SSO callback
$ssoUser = $ssoManager->handleCallback($tenant);

// Find or create user
$user = $ssoManager->findOrCreateUser($tenant, $ssoUser);

// Ensure membership
$ssoManager->ensureMembership($user, $tenant);
```

---

## API Endpoints

### Tenant Domain Management

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/neev/tenant-domains` | List tenant domains |
| POST | `/neev/tenant-domains` | Add custom domain |
| GET | `/neev/tenant-domains/{id}` | Get domain details |
| DELETE | `/neev/tenant-domains/{id}` | Delete domain |
| POST | `/neev/tenant-domains/{id}/verify` | Verify domain |
| POST | `/neev/tenant-domains/{id}/regenerate-token` | New verification token |
| POST | `/neev/tenant-domains/{id}/primary` | Set as primary |

### Tenant Auth

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/tenant/auth` | Get tenant auth config |

### SSO

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/sso/redirect` | Initiate SSO flow |
| GET | `/sso/callback` | Handle SSO callback |

---

## Database Schema

### users Table (tenant_id column)

The `users` table includes a nullable `tenant_id` column. In tenant mode, the `BelongsToTenant` global scope uses this column to automatically filter all queries to the current tenant. When `tenant` is disabled, this column remains `NULL` and is ignored.

The `users` table has a unique constraint on `(tenant_id, email)`, allowing the same email address to exist in different tenants while preventing duplicates within one tenant.

### tenants Table (tenant mode)

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| name | string | Tenant name |
| slug | string | Unique URL-friendly identifier |
| activated_at | timestamp (nullable) | Activation time |
| inactive_reason | string (nullable) | Reason for deactivation |
| created_at | timestamp | Creation time |
| updated_at | timestamp | Last update time |

### domains Table

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| owner_type | string (nullable) | Polymorphic owner type (`team` or `tenant`) |
| owner_id | bigint (nullable) | Polymorphic owner ID |
| enforce | boolean | Enforce email-domain matching (federation) |
| domain | string | Custom domain or email domain |
| verification_token | string | DNS verification token |
| verified_at | timestamp | When verified |
| verification_failed_at | timestamp | When re-verification last failed |
| is_primary | boolean | Primary custom domain |
| created_at | timestamp | Creation time |
| updated_at | timestamp | Last update time |

The `domains` table serves two purposes:
- **Domain federation**: email domains claimed by teams for auto-join rules
- **Custom domains** (tenant mode): domains for tenant/team access (e.g., `app.acme.com`)

### team_auth_settings Table

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| team_id | bigint | Team reference |
| auth_method | string | 'password' or 'sso' |
| sso_provider | string | Provider ID |
| sso_client_id | string | OAuth client ID |
| sso_client_secret | text | Encrypted client secret |
| sso_tenant_id | string | Provider tenant ID |
| sso_extra_config | json | Additional provider config (base URL, domain restrictions, etc.) |
| auto_provision | boolean | Auto-create users |
| auto_provision_role | string | Role for auto-provisioned users |
| created_at | timestamp | Creation time |
| updated_at | timestamp | Last update time |

### tenant_auth_settings Table (tenant mode)

Same schema as `team_auth_settings`, but with `tenant_id` instead of `team_id`. Used when `tenant` is enabled and SSO is configured at the tenant level.

---

## Security Considerations

### Domain Verification

- Always verify domain ownership via DNS
- Don't allow unverified domains for auth — `TenantMiddleware` rejects unverified custom domains with a 403
- Re-verify periodically for long-lived tenants (`VerifyDomainJob` / `VerifyAllDomainsJob` support scheduled re-verification, tracked via `verification_failed_at`)

### Secret Storage

- `sso_client_secret` is encrypted at rest automatically (Eloquent `encrypted` cast) and hidden from serialization
- Never log or expose secrets

### Redirect URI Validation

- Validate `redirect_uri` against tenant domains
- Prevent open redirect attacks
- Only allow same-origin or verified domains

### User Association

- Match SSO users by email
- Consider additional verification for sensitive tenants
- Log all SSO authentications

---

## Example: Multi-Tenant SaaS

### 1. Setup Routes

```php
// routes/web.php

// Public routes (no tenant required)
Route::middleware('web')->group(function () {
    Route::get('/', [HomeController::class, 'index']);
});

// Authenticated routes (tenant-aware when `tenant` is enabled)
Route::middleware(['neev:web'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/settings', [SettingsController::class, 'index']);
});
```

### 2. Scoped Models

Neev provides two scoping traits:

- **`BelongsToTenant`** -- scopes models by `tenant_id` (uses `TenantScope`). The column references the `tenants` table when `tenant` is enabled, otherwise the `teams` table.
- **`BelongsToTeam`** -- scopes models by `team_id` (uses `TeamScope`). Useful when you need team-level scoping within a tenant.

Both traits auto-assign the ID on creation and add a global scope that filters queries automatically.

Neev's **User** model already includes `BelongsToTenant`. This means all user queries are automatically tenant-scoped when a tenant context is resolved. In addition, the following convenience methods are available:

- **`User::findByEmail(string $email)`** — Find a user by email address, automatically scoped to the current tenant.
- **`User::findByUsername(string $username)`** — Find a user by username, automatically scoped to the current tenant.

#### BelongsToTenant

#### Migration

Add a `tenant_id` column to your table:

```php
Schema::create('projects', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
    $table->string('name');
    $table->timestamps();

    // Tip: make unique constraints tenant-aware
    $table->unique(['tenant_id', 'name']);
});
```

#### Model

```php
use Ssntpl\Neev\Traits\BelongsToTenant;

class Project extends Model
{
    use BelongsToTenant;
}
```

That's it. All queries on `Project` are now automatically scoped to the current tenant, and `tenant_id` is auto-filled when creating new records.

#### How It Works

- **Querying**: A `WHERE tenant_id = <current_tenant_id>` clause is added to every query automatically.
- **Creating**: `tenant_id` is set from the resolved tenant. You can override it by setting the value explicitly.
- **No tenant context**: When tenant isolation is enabled but no tenant is resolved, the scope **fails closed** — queries are scoped to `tenant_id IS NULL`, so only platform-level records (no tenant) are visible and tenant data can never leak. Use `runInContext()` or `setCurrentTenant()` to establish context in console commands and queue jobs, or `withoutTenantScope()` when you explicitly need cross-tenant access.

#### Querying

```php
// Automatically scoped to current tenant
$projects = Project::all();
$project = Project::where('status', 'active')->first();

// Cross-tenant queries (admin dashboards, reports, etc.)
$allProjects = Project::withoutTenantScope()->get();
$allProjects = Project::withoutTenantScope()->where('status', 'active')->paginate();
```

#### Creating Records

```php
// tenant_id is set automatically from the resolved tenant
$project = Project::create(['name' => 'My Project']);

// You can override tenant_id explicitly
$project = Project::create(['name' => 'My Project', 'tenant_id' => $otherTenantId]);
```

#### Tenant Relationship

The trait provides a `tenant()` relationship:

```php
$project->tenant;       // Returns the Tenant model (or Team when tenant mode is off)
$project->tenant->name; // 'Acme Corporation'
```

#### Custom Column Name

If your table uses a different column name (e.g., `team_id`), define a constant on your model:

```php
class Project extends Model
{
    use BelongsToTenant;

    const TENANT_ID_COLUMN = 'team_id';
}
```

#### Console & Queue Context

In artisan commands or queue jobs, there is no HTTP request — you must set context manually. See [Console & Queue Context](#console--queue-context) above for patterns and important caveats about long-running workers.

### 3. Tenant-Aware Controllers

With `BelongsToTenant`, controllers no longer need manual filtering:

```php
class ProjectController extends Controller
{
    public function index()
    {
        // Automatically scoped to current tenant
        $projects = Project::paginate(20);

        return view('projects.index', compact('projects'));
    }

    public function store(Request $request)
    {
        // tenant_id auto-set
        $project = Project::create($request->validated());

        return redirect()->route('projects.show', $project);
    }
}
```

---

## Next Steps

- [Architecture](./architecture.md) -- conceptual foundations for identity modes, tenant vs team
- [Security Features](./security.md) -- brute force protection, login tracking, session management
- [Teams Guide](./teams.md) -- team management, invitations, domain federation
- [API Reference](./api-reference.md) -- complete API endpoint reference
