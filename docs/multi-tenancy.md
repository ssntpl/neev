# Multi-Tenancy

Complete guide to implementing multi-tenant SaaS applications with Neev.

---

## Overview

Neev's multi-tenancy features allow you to:

- Isolate teams/organizations by subdomain or custom domain
- Configure per-tenant authentication methods
- Support enterprise SSO (Microsoft Entra ID, Google Workspace, Okta)
- Auto-provision users from identity providers

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

## Tenant Resolution

### TenantResolver Service

```php
use Ssntpl\Neev\Services\TenantResolver;

$resolver = app(TenantResolver::class);

// Get current tenant
$tenant = $resolver->current();

// Resolve by host
$tenant = $resolver->resolveFromHost('acme.yourapp.com');

// Set current tenant
$resolver->setCurrent($team);
```

### In Middleware

```php
// Tenant is automatically resolved and available
$tenant = $request->attributes->get('tenant');
```

### In Controllers

```php
public function index(Request $request, TenantResolver $resolver)
{
    $tenant = $resolver->current();
    // ...
}
```

---

## Tenant Middleware

### Available Middleware

| Middleware | Description |
|------------|-------------|
| `neev:tenant` | Resolves tenant from domain |
| `neev:tenant-web` | Tenant + web authentication |
| `neev:tenant-api` | Tenant + API authentication |

### Using Middleware

```php
// routes/web.php
Route::middleware(['neev:tenant-web'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
});

// routes/api.php
Route::middleware(['neev:tenant-api'])->group(function () {
    Route::get('/data', [DataController::class, 'index']);
});
```

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
    'default_role' => 'viewer',
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

### tenant_domains Table

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| team_id | bigint | Team reference |
| domain | string | Custom domain |
| verification_token | string | DNS verification token |
| verified_at | timestamp | When verified |
| is_primary | boolean | Primary custom domain |
| created_at | timestamp | Creation time |
| updated_at | timestamp | Last update time |

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
| sso_base_url | string | Provider base URL |
| sso_domain | string | Restrict to domain |
| auto_provision | boolean | Auto-create users |
| default_role | string | Role for new users |
| created_at | timestamp | Creation time |
| updated_at | timestamp | Last update time |

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

// Tenant routes
Route::middleware(['neev:tenant-web'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/settings', [SettingsController::class, 'index']);
});
```

### 2. Tenant-Scoped Models

Use the `BelongsToTenant` trait to automatically scope any model to the current tenant.

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
- **No tenant context**: When there is no resolved tenant (console commands, queue jobs without tenant context, or when `tenant_isolation` is disabled), the scope is a no-op — queries run unscoped.

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

In artisan commands or queue jobs, there is no HTTP request, so no tenant is resolved. You can manually set the tenant:

```php
// In an artisan command or queue job
$resolver = app(TenantResolver::class);
$resolver->setCurrentTenant($team);

// Now scoped queries work
$projects = Project::all(); // Scoped to $team
```

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

- [Security Features](./security.md)
- [Teams Guide](./teams.md)
- [API Reference](./api-reference.md)
