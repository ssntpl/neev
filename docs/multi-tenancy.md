# Multi-Tenancy

Complete guide to implementing multi-tenant SaaS applications with Neev.

> For the architectural rationale behind these concepts, see [Architecture](./architecture.md).

---

## Overview

Neev's multi-tenancy features allow you to:

- Choose between **shared** and **isolated** identity strategies
- Isolate organizations by subdomain or custom domain
- Configure per-tenant authentication methods
- Support enterprise SSO (Microsoft Entra ID, Google Workspace, Okta)
- Auto-provision users from identity providers
- Scope any Eloquent model to the current tenant or team automatically

---

## Identity Strategy

Neev supports two identity strategies, configured at install time:

### Shared Identity (default)

Users are global. A single user can belong to multiple teams. There is no tenant boundary — teams serve as collaboration containers.

```php
// config/neev.php
'identity_strategy' => 'shared',
```

Best for: GitHub-style platforms, project management tools, collaborative SaaS.

### Isolated Identity

Users are scoped inside a **tenant** (an identity boundary). The same email can exist in different tenants. The tenant must be resolved *before* authentication so Neev knows which identity provider to use.

```php
// config/neev.php
'identity_strategy' => 'isolated',
```

Best for: white-label SaaS, reseller platforms, regulated industries.

In isolated mode, Neev resolves a `Tenant` model (instead of a `Team`) from the request. Teams still exist as collaboration containers *within* a tenant.

> **Key distinction**: Tenant = identity boundary (who can log in). Team = collaboration boundary (who works together). See [Architecture](./architecture.md) for the full conceptual model.

---

## Configuration

### Enable Tenant Isolation

```php
// config/neev.php
'team' => true,                    // Required
'tenant_isolation' => true,        // Enable tenant isolation

'tenant_isolation_options' => [
    'subdomain_suffix' => env('NEEV_SUBDOMAIN_SUFFIX', '.yourapp.com'),
    'allow_custom_domains' => true,
    'single_tenant_users' => false,
],
```

### Environment Variables

```env
NEEV_SUBDOMAIN_SUFFIX=.yourapp.com
```

---

## Isolation Approaches

Neev supports two approaches to tenant isolation. Choose the one that fits your data model.

### Membership-Based Isolation (Default)

Users can belong to multiple tenants via team memberships. The `EnsureTenantMembership` middleware validates that the authenticated user belongs to the current tenant on each request. Data models use the `BelongsToTenant` trait to scope queries by `tenant_id`.

- Users exist independently of any single tenant
- A user can switch between tenants
- Best for: collaboration platforms, project management tools, consulting firms

### Hard User Isolation (`tenant_id` on Users Table)

Each user belongs to exactly one tenant via a `tenant_id` foreign key on the `users` table. The User model itself uses the `BelongsToTenant` trait, so all user queries are automatically scoped to the current tenant.

- Users are permanently bound to a single tenant
- Best for: regulated industries, data-sensitive applications, single-employer SaaS

To enable hard user isolation during installation:

```bash
php artisan neev:install
# Answer "Yes" to "Would you like to enable hard user-level tenant isolation?"
```

This publishes a migration adding `tenant_id` to the `users` table. Then add the trait to your User model:

```php
use Ssntpl\Neev\Traits\BelongsToTenant;

class User extends Authenticatable
{
    use BelongsToTenant;
    // ...
}
```

### Config vs Trait

The `tenant_isolation` config key controls the **infrastructure**: tenant resolver, middleware, SSO routes. It determines whether Neev resolves tenants from subdomains, headers, and custom domains.

The `BelongsToTenant` trait controls **per-model scoping**. Adding the trait to a model opts that model into automatic query scoping and `tenant_id` auto-assignment — regardless of the `tenant_isolation` config value. This means you can use `BelongsToTenant` on your own models even in simpler setups where you manage the tenant context manually via `TenantResolver::setCurrentTenant()`.

---

## Tenant Isolation Options

### Subdomain Suffix

```php
'subdomain_suffix' => '.yourapp.com',
```

- Tenants access via `acme.yourapp.com`, `corp.yourapp.com`
- Set to `null` to disable subdomain support

### Custom Domains

```php
'allow_custom_domains' => true,
```

- Tenants can use their own domains (e.g., `app.acme.com`)
- Requires DNS verification before activation

### Single Tenant Users

```php
'single_tenant_users' => false,
```

- When `true`: Users can only belong to one tenant
- When `false`: Users can belong to multiple tenants

---

## Tenant Resolution

The `TenantResolver` singleton resolves the current tenant using the following priority order:

1. **X-Tenant Header** -- Resolve by team ID, slug, or domain via the `X-Tenant` request header
2. **Subdomain** -- Extract slug from subdomain (e.g., `acme.yourapp.com` -> slug `acme`)
3. **Custom Domain** -- Look up the full domain in the `domains` table

### Using the X-Tenant Header (API)

For API requests where subdomain routing isn't available, use the `X-Tenant` header:

```bash
# By team ID
curl -H "X-Tenant: 42" -H "Authorization: Bearer {token}" https://api.yourapp.com/resource

# By team slug
curl -H "X-Tenant: acme-corp" -H "Authorization: Bearer {token}" https://api.yourapp.com/resource

# By domain
curl -H "X-Tenant: app.acme.com" -H "Authorization: Bearer {token}" https://api.yourapp.com/resource
```

### Accessing the Current Tenant

```php
use Ssntpl\Neev\Services\TenantResolver;

$resolver = app(TenantResolver::class);

// Shared mode — returns the resolved Team (backward compat)
$team = $resolver->current();

// Isolated mode — returns the resolved Tenant
$tenant = $resolver->currentTenant();

// Either mode — returns the resolved context container (Team or Tenant)
$context = $resolver->resolvedContext();

// Resolution metadata
$resolver->resolvedVia();                  // 'subdomain', 'header', or 'custom'
$resolver->isResolvedDomainVerified();     // Whether the domain is verified
$resolver->currentId();                     // Context ID (Team ID or Tenant ID)
$resolver->isIsolated();                    // true if identity_strategy is 'isolated'
```

---

## Subdomain-Based Tenancy

### How It Works

1. User accesses `acme.yourapp.com`
2. `TenantMiddleware` extracts subdomain `acme`
3. Team with slug `acme` is resolved
4. Tenant context is set for the request

### Team Slugs

Slugs are auto-generated from team names:

```php
$team = Team::create(['name' => 'Acme Corporation']);
// $team->slug = 'acme-corporation'
```

### Reserved Slugs

```php
// config/neev.php
'slug' => [
    'reserved' => ['www', 'api', 'admin', 'app', 'mail', 'ftp', 'cdn'],
],
```

### Computed Subdomain

```php
$team->subdomain;  // Returns 'acme.yourapp.com'
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
  "status": "Success",
  "data": {
    "id": 1,
    "domain": "app.acme.com",
    "verification_token": "neev-verify=abc123...",
    "verified_at": null
  }
}
```

### DNS Verification

Tenant must add a TXT record:

```
TXT neev-verify=abc123...
```

Or a CNAME record pointing to your subdomain:

```
CNAME app.acme.com -> acme.yourapp.com
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
$team->webDomain;  // Returns primary verified domain or subdomain
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
$context->currentContext();   // Tenant (isolated) or Team (shared), or null
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

In artisan commands or queue jobs, set the context manually via `TenantResolver`:

```php
$resolver = app(TenantResolver::class);
$resolver->setCurrentTenant($team);

// Now scoped queries and ContextManager work
$projects = Project::all(); // Scoped to $team
```

---

## Tenant Middleware

### Available Middleware

| Middleware | Description |
|------------|-------------|
| `neev:web` | Web authentication (includes tenant resolution when enabled) |
| `neev:api` | API authentication (includes tenant resolution when enabled) |
| `neev:tenant` | Resolves tenant from domain (no auth) |

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

When `tenant_isolation` is disabled, the tenant-specific middleware are no-ops. Authentication always runs before the membership check. This ensures `$request->user()` is available when `EnsureTenantMembership` validates that the user belongs to the current tenant.

### Middleware Behavior

1. Extracts host from request
2. Checks for custom domain match
3. Falls back to subdomain extraction
4. Validates tenant exists and is verified
5. Sets tenant context on request
6. Proceeds to next middleware

---

## Tenant-Driven Authentication

Per-tenant authentication method configuration.

### Enable Tenant Auth

```php
// config/neev.php
'tenant_isolation' => true,  // Required
'tenant_auth' => true,

'tenant_auth_options' => [
    'default_method' => 'password',
    'sso_providers' => ['entra', 'google', 'okta'],
    'auto_provision' => false,
    'auto_provision_role' => null,
],
```

### TeamAuthSettings Model

Each tenant can have custom auth settings:

```php
$team->authSettings()->create([
    'auth_method' => 'sso',           // 'password' or 'sso'
    'sso_provider' => 'entra',        // SSO provider
    'sso_client_id' => 'client-id',
    'sso_client_secret' => encrypt('client-secret'),
    'sso_tenant_id' => 'azure-tenant-id',
    'auto_provision' => true,
    'default_role' => 'member',
]);
```

### Checking Auth Method

```php
// In Team model (HasTenantAuth trait)
$team->getAuthMethod();        // 'password' or 'sso'
$team->requiresSSO();          // true if SSO is required
$team->hasSSOConfigured();     // true if SSO is properly configured
$team->getSSOProvider();       // 'entra', 'google', or 'okta'
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
$team->authSettings()->create([
    'auth_method' => 'sso',
    'sso_provider' => 'entra',
    'sso_client_id' => 'your-app-id',
    'sso_client_secret' => encrypt('your-client-secret'),
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
$team->authSettings()->create([
    'auth_method' => 'sso',
    'sso_provider' => 'google',
    'sso_client_id' => 'your-client-id',
    'sso_client_secret' => encrypt('your-client-secret'),
    'sso_domain' => 'acme.com',  // Restrict to domain
]);
```

#### Okta

```php
$team->authSettings()->create([
    'auth_method' => 'sso',
    'sso_provider' => 'okta',
    'sso_client_id' => 'your-client-id',
    'sso_client_secret' => encrypt('your-client-secret'),
    'sso_base_url' => 'https://acme.okta.com',
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

After SSO, user is redirected with a token:

```
https://acme.yourapp.com/app?token=1|abc123...
```

---

## Auto-Provisioning

Automatically create users on SSO login.

### Enable Auto-Provisioning

```php
// Global default
'tenant_auth_options' => [
    'auto_provision' => true,
    'auto_provision_role' => 'member',
],

// Per-tenant override
$team->authSettings()->update([
    'auto_provision' => true,
    'auto_provision_role' => 'viewer',
]);
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

### tenants Table (isolated mode)

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| name | string | Tenant name |
| slug | string | Unique URL-friendly identifier |
| managed_by_tenant_id | bigint (nullable) | Parent tenant (reseller model) |
| created_at | timestamp | Creation time |
| updated_at | timestamp | Last update time |

### domains Table

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| team_id | bigint (nullable) | Team reference (shared mode, domain federation) |
| tenant_id | bigint (nullable) | Tenant reference (isolated mode) |
| domain | string | Custom domain or email domain |
| verification_token | string | DNS verification token |
| verified_at | timestamp | When verified |
| is_primary | boolean | Primary custom domain |
| created_at | timestamp | Creation time |
| updated_at | timestamp | Last update time |

The `domains` table serves two purposes:
- **Domain federation** (shared mode): email domains claimed by teams for auto-join rules
- **Custom domains** (tenant isolation): custom domains for tenant/team access (e.g., `app.acme.com`)

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

### tenant_auth_settings Table (isolated mode)

Same schema as `team_auth_settings`, but with `tenant_id` instead of `team_id`. Used when `identity_strategy` is `'isolated'` and SSO is configured at the tenant level.

---

## Security Considerations

### Domain Verification

- Always verify domain ownership via DNS
- Don't allow unverified domains for auth
- Re-verify periodically for long-lived tenants

### Secret Storage

- Store `sso_client_secret` encrypted
- Use Laravel's `encrypt()` helper
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

// Authenticated routes (tenant-aware when tenant_isolation is enabled)
Route::middleware(['neev:web'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/settings', [SettingsController::class, 'index']);
});
```

### 2. Scoped Models

Neev provides two scoping traits:

- **`BelongsToTenant`** -- scopes models by `tenant_id` (uses `TenantScope`). The column references either the `teams` or `tenants` table depending on your identity strategy.
- **`BelongsToTeam`** -- scopes models by `team_id` (uses `TeamScope`). Useful when you need team-level scoping within a tenant.

Both traits auto-assign the ID on creation and add a global scope that filters queries automatically.

#### BelongsToTenant

#### Migration

Add a `tenant_id` column to your table:

```php
Schema::create('projects', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained('teams')->cascadeOnDelete();
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
- **No tenant context**: When there is no resolved tenant (console commands, queue jobs without tenant context), the scope is a no-op — queries run unscoped.

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
$project = Project::create(['name' => 'My Project', 'tenant_id' => $otherTeamId]);
```

#### Tenant Relationship

The trait provides a `tenant()` relationship:

```php
$project->tenant;       // Returns the Team model
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

In artisan commands or queue jobs, there is no HTTP request. See the [ContextManager section](#contextmanager) above for how to set the tenant manually.

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

- [Architecture](./architecture.md) -- conceptual foundations for identity strategy, tenant vs team
- [Security Features](./security.md) -- brute force protection, login tracking, session management
- [Teams Guide](./teams.md) -- team management, invitations, domain federation
- [API Reference](./api-reference.md) -- complete API endpoint reference
