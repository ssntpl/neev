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

The two booleans are orthogonal, giving four modes:

| Mode | `tenant` | `team` | Who shares an email | What `TenantResolver` resolves | Typical product shape |
|------|----------|--------|---------------------|--------------------------------|-----------------------|
| **Single-app** | `false` | `false` | One account per email, application-wide | Nothing — resolver is inactive | Personal apps, internal tools, products with no organization concept |
| **B2B teams** | `false` | `true` | One account per email, application-wide; that account joins many teams | A `Team`, from its platform subdomain or a verified hostname — no user scoping, but it makes per-team SSO reachable | GitHub/Slack-style collaboration SaaS |
| **Isolated tenants** | `true` | `false` | Unique per `(tenant_id, email)` — the same email can be a separate account in each tenant | A `Tenant` (X-Tenant header → subdomain → custom domain) | White-label SaaS, reseller platforms, regulated industries |
| **Tenant + teams** | `true` | `true` | Unique per `(tenant_id, email)` | A `Tenant`; teams are resolved within it | Enterprise SaaS: each customer is an isolated tenant with internal teams/workspaces |

Email uniqueness comes from the composite unique index on `users (tenant_id, email)` (see `2025_01_01_000001_create_users_table.php`). With `tenant => false` every user has a `NULL` `tenant_id`, so all accounts live in one identity namespace. With `tenant => true` each tenant is its own namespace: the same email can hold a distinct account — with a distinct password, MFA setup, and memberships — in every tenant.

**Single-app** (`tenant: false`, `team: false`) — Neev is a drop-in auth layer: password/passkey/OAuth login, MFA, sessions, tokens. No organization modeling at all. Choose this when users only ever act as themselves.

**B2B teams** (`tenant: false`, `team: true`) — users are global and log in once; teams are collaboration containers a user can create, join, and switch between. Per-team SSO and roles are available, but identity stays global — a user is the same account in every team. Choose this for the GitHub/Jira/Trello shape. The resolver runs here too, resolving the `Team` the request's host names, which is what lets the SSO routes read that team's auth settings; it does **not** scope users or data — that stays a `tenant: true` concern.

**Isolated tenants** (`tenant: true`, `team: false`) — the tenant is an identity boundary resolved *before* authentication (so Neev knows which identity provider and user namespace to use). Users belong to exactly one tenant and never interact across tenants. Choose this when each customer must be invisible to every other customer.

**Tenant + teams** (`tenant: true`, `team: true`) — isolation between customers plus collaboration structure inside each customer. Teams exist *within* a tenant; the tenant still owns identity. Choose this for large enterprise accounts that need departments, projects, or workspaces under one isolated umbrella.

#### Four Questions to Decide

1. **Can the same email need two separate accounts in two different customer organizations** (white-label, reseller, strict data isolation)? Yes → `tenant: true`. No → `tenant: false`.
2. **Should one user belong to multiple organizations with a single login** (GitHub/Slack model)? Yes → `tenant: false` + `team: true` (this is impossible with `tenant: true`, where a user belongs to exactly one tenant).
3. **Do users collaborate in named groups** — invites, roles, shared resources, group switching? Yes → `team: true` (in either tenant mode). No → `team: false`.
4. **Must each customer control *how* its users log in** (own SSO/IdP, subdomain or custom-domain access)? Per-org SSO works in both modes via database-driven auth settings, but if the org must also own the *user namespace*, you need `tenant: true`.

Per-tenant and per-team authentication (password vs SSO) is **not** a config toggle — it lives in the `tenant_auth_settings` and `team_auth_settings` database tables. See [Tenant-Driven Authentication](#tenant-driven-authentication).

> The conceptual model behind these modes (identity strategy, tenant vs team separation) is covered in [Architecture](./architecture.md#identity-strategy-primary-concept).

---

## Configuration

### Enable Tenant Isolation

```php
// config/neev.php
'tenant' => true,   // Multi-tenant isolation
'team' => true,     // Optional: team sub-grouping within tenants
```

Host-based access is configured with `platform_domain` (the zone platform subdomains are served under), `slug.retired_host_days` and `dns_verification`. Custom hosts live in the `hostnames` table, email domains in `email_domains`, and per-tenant auth settings in `tenant_auth_settings`.

---

## How Isolation Works

When `tenant => true`:

- Each user belongs to a tenant via a nullable `tenant_id` column on the `users` table (`NULL` = platform-level user)
- The `users` table has a unique constraint on `(tenant_id, email)`, so the same email can exist in different tenants
- Neev's User model includes the `BelongsToTenant` trait, so all user queries are automatically scoped to the resolved tenant via the `TenantScope` global scope
- The `EnsureTenantMembership` middleware validates that the authenticated user belongs to the resolved tenant, through `tenant_id` only (`Tenant::hasMember()`). Membership of one of the tenant's teams does not make a user a member of the tenant; a user of another tenant, or a platform user, could not sign in to it anyway, since users are tenant-scoped

The `tenant_id` column is included in the base migrations — no additional setup is required beyond enabling the config.

When `tenant => false`, the `tenant_id` columns remain `NULL` and the global scope is a no-op — all queries run unscoped.

### Config vs Trait

The `tenant` config key controls the **identity infrastructure**: user scoping (`TenantScope`), team scoping (`TeamTenantScope`), tenant membership enforcement, and whether a `Tenant` is resolved from the `X-Tenant` header and request host.

Context resolution itself also runs when `team` is enabled on its own: in shared mode the resolver resolves the **`Team`** the request's host names, so the SSO routes can read that team's `team_auth_settings`. Scoping stays off — `TenantScope` and `TeamTenantScope` both key on `tenant`.

The `BelongsToTenant` trait controls **per-model scoping**. Adding the trait to a model opts that model into automatic query scoping and `tenant_id` auto-assignment — regardless of the `tenant` config value. This means you can use `BelongsToTenant` on your own models even in simpler setups where you manage the tenant context manually via `TenantResolver::setCurrentTenant()`.

### Teams Inside a Tenant

The `teams` table has a `tenant_id` column, but `Team` does not use
`BelongsToTenant` — the trait's global scope would run during tenant
resolution, which itself reads Teams. The `tenant_id` the trait would assign is
therefore stamped on in `Team::booted()` instead.

A new team takes its `tenant_id` from the resolved context, and only when that
context is a Tenant. With `tenant => true` that is what the request resolves to,
so teams created during a request land under the right tenant automatically.
`tenant_id` stays `NULL` when:

- nothing is resolved — which is every request when `tenant => false`, since
  the resolver short-circuits and never sets a context; or
- the application has set a **Team** as the context itself (via
  `TenantResolver::setCurrentTenant()`). A Team is never another team's parent,
  so that context is ignored here.

A `tenant_id` you set yourself before saving is always kept — the hook only
fills in a `NULL` one. Note it is not fillable, so mass assignment will not
carry it:

```php
$team = new Team(['name' => 'Platform', 'user_id' => $user->id]);
$team->tenant_id = $tenant->id;   // tenant_id is not fillable
$team->save();
```

---

## Tenant Resolution

The `TenantResolver` (a request-scoped singleton) resolves the request's context. It runs when `tenant` or `team` is enabled: with `tenant => true` it resolves a `Tenant`, with only `team => true` a `Team`. Priority order:

1. **X-Tenant Header** -- Resolve by ID (numeric), current slug, a slug retired within `neev.slug.retired_host_days`, or a host (looked up as in step 2)
2. **Request Host** -- A platform subdomain resolves by its slug; any other host is looked up in the `hostnames` table

Custom host lookups only match **verified** rows and are cached for 5 minutes; saving or deleting a row clears its entry. A host owned by a tenant resolves that tenant; a host owned by a team resolves the team's tenant. Platform subdomains are not cached, so a rename takes effect at once.

### Platform Subdomains

With `platform_domain` set, an owner's subdomain is its slug, one label under that zone: tenant `acme` is served at `acme.otper.com`. It is derived from the slug on every request and never stored, so it needs no row and no DNS proof.

```php
// config/neev.php
'platform_domain' => env('NEEV_PLATFORM_DOMAIN'),   // e.g. 'otper.com'
```

- Only the owner kind the mode routes on has a subdomain: tenants with `tenant => true`, teams with only `team => true`. In tenant mode a team's slug is unique only within its tenant, so it names no host.
- The bare zone (`otper.com`) and anything deeper (`a.b.otper.com`) name no owner.
- Nothing under `platform_domain` can be claimed as a custom host.
- `slug.reserved` keeps your own operational names (`app`, `login`, ...) from being taken as slugs, and so as subdomains.

With `platform_domain` unset no subdomains are served; owners are reached by custom host or the `X-Tenant` header.

### Retired Slugs and Hosts

Renaming an owner retires its old slug in the `retired_slugs` table. A retired slug is never issued to anyone else. Its old subdomain keeps serving the owner for `neev.slug.retired_host_days` (90 by default), then stops answering. `0` turns the window off.

Within the window:

- A browser navigation — a `GET` or `HEAD` on the retired host that does not ask for JSON — gets a `302` to the owner's current platform host, with the same path and query.
- Anything else, such as an API call, is served in place with an `X-Tenant-Slug` response header carrying the current slug. A redirect that changes host makes clients drop `Authorization`, which would turn the call into a 401.
- An `X-Tenant` header naming a retired slug is served in place the same way, with `X-Tenant-Slug` on the response.

`resolvedVia()` reports `retired` for a request on a retired host.

### Custom Hosts

Any host outside `platform_domain` must be a verified row in the `hostnames` table. A host is unique across every owner: once one owner has claimed it, pending or verified, no other owner can. See [Custom Hosts & Email Domains](#custom-hosts--email-domains).

### Using the X-Tenant Header (API)

For API requests where host routing isn't available, use the `X-Tenant` header:

```bash
# By tenant ID
curl -H "X-Tenant: 42" -H "Authorization: Bearer {token}" https://api.yourapp.com/resource

# By tenant slug
curl -H "X-Tenant: acme-corp" -H "Authorization: Bearer {token}" https://api.yourapp.com/resource

# By host (platform subdomain or verified custom host)
curl -H "X-Tenant: app.acme.com" -H "Authorization: Bearer {token}" https://api.yourapp.com/resource
```

### Accessing the Current Tenant

```php
use Ssntpl\Neev\Services\TenantResolver;

$resolver = app(TenantResolver::class);

// The resolved Tenant model
$tenant = $resolver->currentTenant();

// The resolved context container (Tenant, or Team in shared mode or when set manually)
$context = $resolver->resolvedContext();

// Resolution metadata
$resolver->resolvedVia();                  // 'header', 'subdomain', 'retired', 'custom', or 'manual'
$resolver->currentHostname();              // The Hostname row a custom host resolved through, or null
$resolver->platformHost();                 // The context's current platform subdomain, or null
$resolver->headerSlugRetired();            // Whether X-Tenant named a retired slug
$resolver->isResolvedDomainVerified();     // Whether the resolved host is verified
$resolver->currentId();                     // Context ID (Tenant ID or Team ID)
$resolver->isEnabled();                     // true when config('neev.tenant') is enabled

// Run code in a specific tenant context — from a command or a queued job,
// never inside a request that has bound one (see Console & Queue Context).
$resolver->runInContext($tenant, function () {
    $user = User::create([...]);         // tenant_id auto-set
});
```

### Renaming a Slug

Renaming a tenant (or, in shared mode, a team) moves its platform subdomain with it. `Ssntpl\Neev\Events\SlugChanged` fires after the transaction commits, with `$owner`, `$oldSlug` and `$newSlug`. Three things do not follow the rename:

- **Signed links.** Email verification, password reset and email change links sent before the rename carry the old host inside their signature. A redirect cannot rescue them: they fail as invalid until they expire, up to `url_expiry_time` (60 minutes by default).
- **Passkeys enrolled on the old subdomain.** Each host is its own relying party, and a retired host is never one, so the browser refuses the ceremony there and on the new host. Users enrol a new passkey on the new host. Passkeys on a custom host are not affected.
- **External configuration.** IdP redirect URIs, API base URLs and integrations pointing at the old host are the tenant's to update. Listen for `SlugChanged` to ask them.

---

## Host-Based Tenancy

### How It Works

1. User accesses `acme.yourapp.com` (or `app.acme.com`)
2. `TenantMiddleware` passes the request to `TenantResolver::resolve()`
3. A host under `platform_domain` resolves to the owner of its slug (or, within the window, the owner that retired it); any other host is looked up in `hostnames` (only verified rows match)
4. The owner (Tenant, or a Team belonging to a Tenant) determines the tenant
5. Tenant context is set for the request

> **Passkeys work per host.** The WebAuthn relying party is the host the browser is on: the context's
> current platform subdomain, or a verified hostname equal to the request's origin. Otherwise the
> configured `relying_party_id` stands. A passkey enrolled on one host never works on another, so a
> tenant reached at both its subdomain and a custom host enrols one per host. See
> [Supported Domains](./authentication.md#supported-domains).

### Tenant & Team Slugs

Slugs are auto-generated from names and name the platform subdomain and the `X-Tenant` header value:

```php
$team = Team::create(['name' => 'Acme Corporation']);
// $team->slug = 'acme-corporation'
```

Slugs can be renamed but never recycled. Saving a slug another owner of the same kind has retired throws `SlugUnavailableException`. An owner may take back its own retired slug.

### Slug Configuration

```php
// config/neev.php
'slug' => [
    'min_length' => 2,
    'max_length' => 63,
    'retired_host_days' => 90,   // Days a renamed owner's old subdomain keeps serving
    'reserved' => ['www', 'api', 'admin', 'app', 'mail', /* ... */],
],
```

---

## Custom Hosts & Email Domains

A host an owner is served at and an email domain whose users belong to it are different things, with opposite uniqueness rules, so they live in two tables:

| | `hostnames` | `email_domains` |
|---|---|---|
| Means | This owner is served at this host | Users at this domain belong to this owner |
| Unique | Across every owner | Per owner; two owners may each verify `acme.com` |
| DNS record | `_neev-host.<host>` | `_neev-email.<domain>` |
| Model | `Ssntpl\Neev\Models\Hostname` | `Ssntpl\Neev\Models\EmailDomain` |

A host says nothing about who has addresses there, and an email domain is never served. `Tenant` and `Team` both use the `HasHostnames` and `HasEmailDomains` traits. Deleting an owner deletes its rows.

> `Ssntpl\Neev\Models\Domain` and the `domains` table are deprecated. `Domain` is a read-only reader
> kept for this release; saving or deleting one throws `LogicException`. Both are removed in the next
> release. Use `Hostname` and `EmailDomain`.

### Managing a Tenant's Hosts

Over the API, `GET|POST {prefix}/tenant/hostnames` list and add the hosts of the tenant the request resolved to, and `{prefix}/hostnames/{id}` (`verify`, `token`, `primary`, `DELETE`) manages each one; see [Tenant Hostnames and Email Domains](./api-reference.md#tenant-hostnames-and-email-domains). A tenant's row is reachable only from that tenant. Neev checks only that the caller belongs to the tenant: which members may manage its hosts is yours to decide, with your own middleware on these routes in a published `routes/neev.php`. The old `/tenant-domains` API is gone.

They can also be managed in code or from the CLI. Team-owned hosts have their own endpoints (`{prefix}/teams/{team}/hostnames`, see the [Teams Guide](./teams.md)), but under tenant isolation only a tenant's host routes ([RFC 006 §6 Q5](./rfcs/006-hostnames-vs-email-domains.md)): a team is a path inside its tenant, so it cannot claim a host — add the host to the tenant instead.

```php
$hostname = $tenant->claimHost('app.acme.com');   // pending claim
$hostname->getDnsRecordName();                    // '_neev-host.app.acme.com'
$hostname->verification_token;                    // the TXT value (hidden from serialization)

$hostname->verify();                              // checks DNS; true once the record is published
$tenant->makePrimaryHostname($hostname);          // only a verified host of this owner

$tenant->hostnames;                               // every row, whatever its status
$tenant->primaryHostname;                         // the row primary_hostname_id points at
$tenant->canonicalHost();                         // verified primary, else oldest verified host, else platform subdomain
$tenant->platformHost();                          // 'acme.otper.com', or null
$tenant->releaseHost('app.acme.com');             // deletes the row; false when not held
```

- `claimHost()` returns the owner's existing row for the host as it is. It throws `HostnameTakenException` when another owner holds the host, and `InvalidArgumentException` for a host under `platform_domain`.
- `releaseHost()` unpoints `primary_hostname_id` first, so the owner falls back to its next host. The platform subdomain is not a row and cannot be released.
- `generateVerificationToken()` issues a new token and changes nothing else. A verified row keeps granting; the daily re-check holds it to the new record, so one left unpublished fails and is unverified in time.
- `Team::$webDomain` returns the team's verified primary hostname, or `null`. `canonicalHost()` answers where an owner is served, platform subdomain included.

From the CLI:

```bash
php artisan neev:hostname:add app.acme.com --owner-type=tenant --owner-id=acme   # prints the TXT record
php artisan neev:hostname:verify app.acme.com
php artisan neev:hostname:primary app.acme.com
php artisan neev:hostname:list --owner-type=tenant --owner-id=acme
```

### Email Domains

Over the API, `GET|POST {prefix}/tenant/email-domains` list and add the resolved tenant's email domains, and `{prefix}/email-domains/{id}` (`PATCH` for `enforce`, `verify`, `token`, `DELETE`) manages each one, on the same terms as hosts above.

```php
$tenant->emailDomains;                            // EmailDomain rows
$tenant->federateDomain('acme.com', true);        // claim, or re-issue the token of one held
```

```bash
php artisan neev:email-domain:add acme.com --owner-type=tenant --owner-id=acme
php artisan neev:email-domain:verify acme.com --owner-type=tenant --owner-id=acme
php artisan neev:email-domain:list --owner-type=tenant --owner-id=acme
```

Enforcement, auto-join and the team endpoints are covered in the [Teams Guide](./teams.md).

Under tenant isolation a claim counts only inside its own tenant. `EmailDomain::isVerifiedForEmail()` — which makes a sign-up federated, so it gets no personal team — looks only at the resolved tenant's own email domains and those of its teams. Another tenant verifying `acme.com` has no effect here.

### DNS Records

Publish a TXT record whose value is the row's verification token:

```
_neev-host.app.acme.com.  TXT  "abc123..."
_neev-email.acme.com.     TXT  "abc123..."
```

A row your app copied from the old `domains` table, with the same owner and token (see [UPGRADING](../UPGRADING.md)), also passes on the record published for it there, `_neev-verification.<name>`, while `neev.dns_verification.legacy_record` is on (default `true`, env `NEEV_DNS_LEGACY_RECORD`). It is checked only when the row's own record is missing. That one record proves both a host and an email domain, so the fallback is for this release only and is removed with `domains`. Publish the new records, then set it to `false`.

### Verification Lifecycle

`verified_at` is what grants: a host serves and an email domain federates while it is set. `status` says why:

| Status | Meaning |
|--------|---------|
| `pending` | Claimed, not yet proven |
| `verified` | Record found |
| `failed` | Proven before, but the record has been missing since `verification_failed_at` |
| `disabled` | Disabled by the application with `disable()`; neither DNS nor a new token brings it back |

`VerifyAllDomainsJob` re-checks every verified row and every row unverified for a missing record. The package does not schedule it; schedule it daily:

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;
use Ssntpl\Neev\Jobs\VerifyAllDomainsJob;

Schedule::job(new VerifyAllDomainsJob)->daily();
```

With `neev.dns_verification.unverify_after_failed_days` at N (7 by default):

1. The first missed check marks the row `failed` and fires `DomainVerificationFailed`. It still grants.
2. After N days missing, the row is unverified and `DomainUnverified` fires: a host stops serving, an email domain stops federating, enforcing and deactivating. It stays `failed`, and publishing the record again restores it (`DomainReverified`).
3. After 2N days missing, the row is deleted and `DomainRemoved` fires, freeing the host for another owner. A removed email domain gives back the accounts it deactivated.

`0` never unverifies or deletes. A pending claim is never re-checked by the job; verify it with `verify()` or `neev:hostname:verify`. An email domain verified manually from the CLI has no record and is skipped.

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

It refuses to run on a request that has **already bound** its context, throwing a `LogicException`. `BindContextMiddleware` binds once per request and `ContextManager` is immutable afterwards, so re-entering from inside a request was never possible — it simply failed halfway, leaving the resolver pointing at the new context and `ContextManager` at the old one, with every tenant-scoped query for the rest of that request running against the wrong tenant. Call it from a queued job, an artisan command, or before the context is bound.

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

When creating tenant resources from platform context (e.g., provisioning the first user for a new tenant), `runInContext()` ensures all `BelongsToTenant` models get the correct `tenant_id` automatically — **from a command or a queued job**. A controller cannot use it: every neev route group ends in `BindContextMiddleware`, so the request has already bound its context and `runInContext()` throws a `LogicException` rather than leaving the resolver and `ContextManager` pointing at different tenants. Provisioning inside a request sets `tenant_id` explicitly, as shown below.

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

**In queue jobs (recommended pattern):**

Queue workers never run the HTTP middleware stack, so `ContextManager` and `TenantResolver` start empty in every job. The recommended pattern has two halves:

1. **At dispatch time**, serialize the tenant (or team) **ID** — a plain integer — into the job payload. Don't serialize the model into a context-holding property; the ID is unambiguous and survives queue serialization cleanly.
2. **In `handle()`**, re-fetch the model and wrap all work in `TenantResolver::runInContext()`.

`runInContext(ContextContainerInterface $context, Closure $callback): mixed` accepts either a `Tenant` (isolated mode) or a `Team` (shared mode) — both implement `ContextContainerInterface`. It sets the context, runs the callback, returns the callback's return value, and restores the previous context in a `finally` block — even if the callback throws.

```php
// Dispatching (e.g. from a controller, where middleware already resolved the context)
ProcessTenantReport::dispatch(app(TenantResolver::class)->currentId());
```

```php
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Ssntpl\Neev\Models\Tenant;
use Ssntpl\Neev\Services\TenantResolver;

class ProcessTenantReport implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private int $tenantId,
    ) {
    }

    public function handle(TenantResolver $resolver): void
    {
        $tenant = Tenant::getClass()::findOrFail($this->tenantId);

        $resolver->runInContext($tenant, function () {
            // Scoped queries and tenant_id auto-assignment work here
            $projects = Project::all();          // scoped to $tenant
            $report = Report::create([...]);     // tenant_id auto-set
        });
        // Context restored (empty again) here — nothing leaks to the next job
    }
}
```

In shared mode (`tenant => false`, `team => true`), pass a team ID instead and fetch it with `Team::getClass()::findOrFail(...)` — `runInContext()` works identically with a `Team`.

> **Important:** Never rely on the `ContextManager` being populated in queue workers — the HTTP middleware that populates it never runs there. Always pass tenant/team IDs explicitly in job payloads and set context at the start of `handle()`. Prefer `runInContext()` over `setCurrentTenant()` in jobs: its `finally`-based restore guarantees the context is cleaned up even when the job throws, so no tenant context can bleed into later work in the same process.

---

## Tenant Middleware

This section summarizes the middleware from a tenancy perspective. The authoritative usage and ordering guide — exact group compositions, alias placement, and how custom application middleware should interact with the bound context — is in [Architecture Internals](./architecture-internals.md#middleware-usage--ordering).

### Available Middleware Groups

| Group | Description |
|------------|-------------|
| `neev:web` | Session authentication for web routes (includes tenant resolution when enabled) |
| `neev:api` | Token authentication for API routes (includes tenant resolution when enabled) |
| `neev:login` | MFA JWT authentication (used for `POST /neev/mfa/otp/verify` and `POST /neev/mfa/otp/send`) |
| `neev:tenant` | Tenant resolution only, no auth — uses `TenantMiddleware:required`, returns 404 when no tenant resolves |

### Middleware Aliases

| Alias | Middleware |
|-------|------------|
| `neev-active-team` | `EnsureTeamIsActive` |
| `neev-active-tenant` | `EnsureTenantIsActive` |
| `neev-tenant-member` | `EnsureTenantMembership` |
| `neev-resolve-team` | `ResolveTeamMiddleware` |
| `neev-ensure-sso` | `EnsureContextSSO` |
| `neev-password-not-expired` | `EnsurePasswordNotExpired` |
| `neev-verified-email` | `EnsureEmailIsVerified` |
| `neev-token-can` | `EnsureTokenCan` |

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

Always list the group **before** any `neev:*` alias or custom middleware — everything after the group sees the bound (immutable) context. See the [full ordering rules](./architecture-internals.md#middleware-usage--ordering).

### TenantMiddleware Behavior

1. Skips entirely when both `tenant` and `team` are `false`
2. Resolves the context via `TenantResolver` (X-Tenant header, then the platform subdomain or a verified row in `hostnames`)
3. If nothing resolves: returns 404 in `required` mode (`neev:tenant` group), otherwise passes through
4. On a retired host within `slug.retired_host_days`: a browser navigation gets a `302` to the current platform host
5. If the resolved host is not verified: returns 403
6. Sets the `tenant` attribute on the request and proceeds; a request served through a retired slug or host gets an `X-Tenant-Slug` response header

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

SSO is owner-agnostic: a `Tenant` (isolated mode) and a `Team` (shared mode) both own their auth settings, and the whole flow — `TenantSSOManager`, the `/sso/*` routes, `EnsureContextSSO` — works against whichever context the request resolves to.

**Per-team SSO in shared mode** (`tenant: false`, `team: true`) therefore needs a resolvable team: the request must arrive on the team's platform subdomain or on a **verified** hostname it owns, or name the team in `X-Tenant`. `/neev/tenant/auth` then reports the team's method, and `/neev/sso/redirect` builds the driver from `team_auth_settings`:

```bash
php artisan neev:auth:configure --team=acme --method=sso \
    --sso-provider=google --sso-client-id=... --sso-client-secret=...
```

Without a resolvable context the SSO endpoints answer as unconfigured — configuring team SSO is not enough on its own, the request has to resolve to that team.

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
3. Add redirect URI: `https://tenant.yourapp.com/neev/sso/callback`
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
curl -X GET https://acme.yourapp.com/neev/tenant/auth
```

**Response:**

```json
{
  "auth_method": "sso",
  "sso_enabled": true,
  "sso_provider": "entra",
  "sso_redirect_url": "https://acme.yourapp.com/neev/sso/redirect"
}
```

### 2. Redirect to SSO

```http
GET /neev/sso/redirect?email=user@acme.com
```

User is redirected to the identity provider.

### 3. Handle Callback

After authentication, the user is redirected to:

```http
GET /neev/sso/callback?code=auth-code&state=...
```

Neev:
1. Exchanges code for user info
2. Finds or creates the user
3. Ensures tenant membership
4. Logs in the user

### 4. SPA Flow

For single-page applications, pass a `redirect_uri`:

```http
GET /neev/sso/redirect?redirect_uri=https://acme.yourapp.com/app
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

> **Security:** The `redirect_uri` host must be the tenant's platform subdomain, one of its verified hostnames whose record is not failing, or the current request's host. An email domain does not count. Neev validates this to prevent open redirect attacks. The URL fragment is never sent to the server in HTTP requests, making it safer than query parameters for token transport.

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

### Current Context

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/neev/hostnames/current` | The resolved context and the hostname it resolved through |

It replaces `/neev/tenant-domains/current`. The rest of the `/tenant-domains` API is removed: a tenant's hosts are managed in code or from the CLI (see [Managing a Tenant's Hosts](#managing-a-tenants-hosts)). The route sits in the `neev:api` group, so it needs an authenticated user.

`current` reports the context the resolver settled on for this request. With
`tenant => true` that is the Tenant named by the `X-Tenant` header or the
request host — a team-owned host resolves up to that team's tenant — so
`type` is `tenant`:

```json
{
  "data": {
    "type": "tenant",
    "context": { "id": 1, "name": "Acme", "slug": "acme" },
    "hostname": { "id": 4, "owner_type": "tenant", "owner_id": 1, "host": "app.acme.com", "status": "verified", "verified_at": "..." }
  }
}
```

`context` is that record, and `type` says what it is: `tenant`, or `team` in
shared mode or when the application has made a Team the context itself via
`TenantResolver::setCurrentTenant()`. `hostname` is the verified row a custom
host resolved through, and `null` on a platform subdomain or when the context
came from the `X-Tenant` header.

With nothing resolved the endpoint answers `400 No tenant context.` — including
on every request when both `tenant` and `team` are `false`, where the resolver never runs.

### Tenant Auth

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/neev/tenant/auth` | Get tenant auth config |

### SSO

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/neev/sso/redirect` | Initiate SSO flow |
| GET | `/neev/sso/callback` | Handle SSO callback |

All of the SSO and tenant-auth routes above run `TenantMiddleware` before their
controller, so the context is resolved by the time the controller asks for it —
including `GET /neev/tenant/auth`, which an SPA reads to decide which sign-in
buttons to show.

That middleware is a no-op when both `tenant` and `team` are `false`: it
passes the request straight through, the controllers see no context, and
`/neev/tenant/auth` answers with its default of `auth_method: password`,
`sso_enabled: false`. With only `team => true` the context is a Team (see
[Enterprise SSO](#enterprise-sso)).

> The `/neev` prefix on these endpoints is configurable via `route_prefix` in `config/neev.php` (env `NEEV_ROUTE_PREFIX`).

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
| primary_hostname_id | bigint (nullable) | The tenant's primary hostname; `teams` has the same column |
| created_at | timestamp | Creation time |
| updated_at | timestamp | Last update time |

### hostnames Table

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| owner_type | string | Polymorphic owner type (`team`, `tenant`, or an application model) |
| owner_id | bigint | Polymorphic owner ID |
| host | string | Custom host, canonical (lowercase, no trailing dot); unique across every owner |
| status | string | `pending`, `verified`, `failed` or `disabled` |
| verification_token | string (nullable) | DNS verification token |
| verified_at | timestamp (nullable) | When verified; set while the host serves |
| verification_failed_at | timestamp (nullable) | Start of the current missing-record streak |
| created_at | timestamp | Creation time |
| updated_at | timestamp | Last update time |

Platform subdomains are derived from the slug and never stored here.

### email_domains Table

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| owner_type | string | Polymorphic owner type |
| owner_id | bigint | Polymorphic owner ID |
| domain | string | Email domain; unique per owner |
| status | string | `pending`, `verified`, `failed` or `disabled` |
| verification_strategy | string | `dns`, or `manual` when verified from the CLI without DNS |
| verification_token | string (nullable) | DNS verification token |
| verified_at | timestamp (nullable) | When verified; set while the domain federates |
| verification_failed_at | timestamp (nullable) | Start of the current missing-record streak |
| enforce | boolean | Only invite users at this domain; one owner per domain |
| created_at | timestamp | Creation time |
| updated_at | timestamp | Last update time |

### retired_slugs Table

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| owner_type | string | Polymorphic owner type |
| owner_id | bigint | Owner that gave up the slug |
| slug | string | The retired slug; never issued to another owner of this type |
| created_at | timestamp | When it was retired; starts the `retired_host_days` window |
| updated_at | timestamp | Last update time |

### domains Table (deprecated)

Kept for this release so your app can copy its rows into `hostnames` and `email_domains` (the migrations copy nothing; see [UPGRADING](../UPGRADING.md)), so the deprecated `Domain` model can still read them, and so the legacy `_neev-verification` record can be matched. Neev no longer writes to it. It is removed in the next release.

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

### Host Verification

- Always verify host ownership via DNS; a claim serves nothing until its `_neev-host` record is verified
- A host is unique across every owner. The first claim holds it, pending or verified, and a second claim throws `HostnameTakenException` even with its own TXT record in place
- Don't allow unverified hosts for auth — the resolver only matches verified rows, and `TenantMiddleware` rejects an unverified custom host with a 403
- Schedule `VerifyAllDomainsJob` daily. A host whose record stays missing stops serving after `dns_verification.unverify_after_failed_days` and is deleted at twice that, so a lapsed domain registration does not hand the tenant's host to whoever registers the name next
- Set `dns_verification.legacy_record` to `false` once tenants have published the new records; the legacy `_neev-verification` record proves a host and an email domain at once

### Secret Storage

- `sso_client_secret` is encrypted at rest automatically (Eloquent `encrypted` cast) and hidden from serialization
- Never log or expose secrets

### Redirect URI Validation

- Validate `redirect_uri` against the tenant's platform subdomain and verified hostnames
- Prevent open redirect attacks
- Only allow same-origin or verified hosts

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

#### Teams are scoped the same way

`Team` is filtered by the resolved tenant too, so a user's team listings, team lookups by id or slug, and route-model binding never reach across tenants. Teams cannot reuse `TenantScope` itself — `users.tenant_id` holds the *resolved context id* (which is a team id in shared/back-compat mode) while `teams.tenant_id` is a real foreign key into `tenants` — so `Ssntpl\Neev\Scopes\TeamTenantScope` applies the same rules, deriving the tenant from the resolved context whatever its type:

| Tenant isolation | Tenant resolved | Team visibility |
|---|---|---|
| Disabled | n/a | No scope applied |
| Enabled | No | `tenant_id IS NULL` — platform teams only |
| Enabled | Yes | `tenant_id = <resolved tenant>` |

`Team::withoutTenantScope()` reaches across tenants for platform-level code. Three relations and lookups deliberately bypass the scope, because they are already keyed correctly or run before a tenant exists: `Tenant::teams()`, the console team resolver used by `neev:*` commands, and domain-owner resolution inside `TenantResolver`.

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
- [Teams Guide](./teams.md) -- team management, invitations, email domains
- [API Reference](./api-reference.md) -- complete API endpoint reference
