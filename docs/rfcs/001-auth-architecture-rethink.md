# RFC-001: Auth Architecture Rethink — Multi-Tier Users & Per-Group Auth Methods

**Status:** Discussion
**Date:** 2026-03-10
**Package:** ssntpl/neev (Laravel auth/teams/multi-tenancy package)

---

## 1. Problem Statement

Neev's current architecture treats all users within a tenant as homogeneous — one auth method, one identity model, one set of rules. Real enterprise SaaS applications need to support multiple user populations within a single tenant, each with different auth methods, permissions, and onboarding flows.

### Triggering Discovery

A security review of `TenantScope` (the Eloquent global scope that filters queries by `tenant_id`) revealed a chain of issues:

1. **TenantScope failed open** — when tenant isolation was enabled but no tenant was resolved, the scope silently skipped, allowing cross-tenant data leakage (queries ran unscoped against ALL tenants). **Fixed in v0.4.3** — now scopes to `WHERE tenant_id IS NULL` (platform users only).

2. **No concept of platform users** — fixing the scope with `WHERE 1 = 0` (strict fail closed) would block legitimate platform-level users (SaaS operators, super admins) who don't belong to any tenant. **Resolved** — platform users are users with `tenant_id = null` in the same `users` table. TenantScope now surfaces only these users when no tenant context exists.

3. **NeevAuthenticatable trait is schema-coupled** — the trait was designed to be composable on any authenticatable model (e.g., a separate `PlatformUser` model), but all auth tables (`emails`, `passwords`, `passkeys`, `multi_factor_auths`, `recovery_codes`, `login_attempts`, `access_tokens`) have `foreignId('user_id')->constrained()` pointing at the `users` table. A separate model in a separate table can't use these relationships. **Resolved** — all user types (platform, tenant, staff, customer) live in the single `users` table, differentiated by `tenant_id` and roles. No need for separate models.

4. **Auth method is per-tenant, not per-user-group** — `TenantAuthSettings.auth_method` is a single value (`password`, `sso`). A tenant can't configure "SSO for staff, magic auth for customers." **Open — this is the core issue for v0.5.0.**

---

## 2. Current Architecture

### 2.1 Identity Model

```
users table (single table, single model)
├── id
├── tenant_id (nullable FK → tenants)
│   ├── NULL = platform user (SaaS operator, global admin, consultant)
│   └── N = tenant-scoped user (staff, customer, vendor within tenant N)
├── name, username, active
├── default_team_id
└── timestamps

Trait composition on User model:
  - NeevAuthenticatable (emails, passwords, MFA, tokens, passkeys, password expiry)
  - BelongsToTenant (tenant_id scoping via TenantScope)
  - HasTeams (team membership via pivot)
  - HasRoles (from ssntpl/laravel-acl)
```

### 2.2 Auth Tables (all FK to `users.id`)

| Table | Purpose |
|-------|---------|
| `emails` | Email addresses (has `tenant_id` for uniqueness scoping) |
| `passwords` | Password history (hashed) |
| `passkeys` | WebAuthn credentials |
| `multi_factor_auths` | TOTP secrets |
| `recovery_codes` | MFA backup codes |
| `login_attempts` | Audit log with GeoIP |
| `access_tokens` | API tokens (hashed) |

All use `$table->foreignId('user_id')->constrained()->onDelete('cascade')` — hardcoded to `users` table. This is correct — all user types share the single `users` table, so no polymorphic change is needed.

### 2.3 Auth Settings (per-tenant, single method)

```
tenant_auth_settings / team_auth_settings
├── auth_method: 'password' | 'sso'  (ONE value for entire tenant)
├── sso_provider, sso_client_id, sso_client_secret, sso_tenant_id
└── sso_redirect_url
```

**This is the primary limitation.** A tenant cannot configure different auth methods for different user groups (e.g., SSO for staff, magic auth for customers).

### 2.4 Tenant Scoping (fixed in v0.4.3)

```php
// TenantScope::apply()
if (!$resolver->hasTenant()) {
    // Scope to platform users only — prevents cross-tenant leakage
    // while allowing platform-level users (tenant_id = null) to authenticate.
    $builder->whereNull($model->getQualifiedTenantIdColumn());
    return;
}
// Tenant resolved — filter to that tenant's users only.
$builder->where($model->getQualifiedTenantIdColumn(), $resolver->currentId());
```

**Three scoping states:**
| Tenant isolation | Tenant resolved | Scope behavior |
|---|---|---|
| Disabled (`neev.tenant = false`) | N/A | No scope applied |
| Enabled | No | `WHERE tenant_id IS NULL` (platform users only) |
| Enabled | Yes | `WHERE tenant_id = ?` (tenant users only) |

### 2.5 Identity Modes (config flags)

| `neev.tenant` | `neev.team` | Mode | Description |
|---|---|---|---|
| false | false | Simple | No scoping, single-org app |
| false | true | Collaboration | Users global, teams for grouping |
| true | false | White-label | Users scoped to tenant, platform users at null |
| true | true | Enterprise SaaS | Users scoped to tenant, teams within tenant, platform users at null |

---

## 3. Real-World Requirements (How Enterprise Auth Works)

Enterprise identity providers (Auth0, WorkOS, Okta, Azure Entra ID) all share these patterns:

### 3.1 Multiple Auth Methods Per Tenant

A single organization/tenant configures multiple authentication "connections" or "policies":

- **Staff** → SAML/OIDC SSO via corporate IdP (Entra ID, Okta)
- **Customers** → Magic link (passwordless email)
- **Vendors/Partners** → Password + MFA
- **External consultants** → Federated SSO via their own IdP

The auth method is **per user group**, not per tenant.

### 3.2 Routing to the Correct Auth Flow

How does the system know which auth method to use for a given login attempt?

- **Email domain routing** — `@company.com` → SSO, `@gmail.com` → magic auth
- **Explicit user type selection** — login page asks "Staff" vs "Customer"
- **URL-based** — `app.com/staff/login` vs `app.com/portal/login`
- **Pre-auth lookup** — enter email first, system looks up the user's group, redirects to correct flow

### 3.3 Single Identity Store

All user types live in one directory/table. Differentiation is via attributes (groups, roles, tags), not separate models or tables. This avoids schema duplication and allows users to change roles without migrating between tables.

### 3.4 Policy-Based Enforcement

Auth requirements are expressed as policies attached to groups:
- "If user is in group `staff`, require SSO + MFA"
- "If user is in group `customer`, allow magic auth"
- "If user has no group, deny access"

### 3.5 Platform/Global Users

SaaS operators who manage tenants are users with no tenant affiliation (`tenant_id = null`). They may need to impersonate tenant users, provision resources, etc. They authenticate via platform-level routes (no tenant context required).

---

## 4. Gap Analysis

| Capability | Enterprise Standard | Neev Today |
|---|---|---|
| Multiple auth methods per tenant | Per-group policies | Single `auth_method` per tenant |
| User populations within tenant | Groups/roles with different auth | All users identical |
| Platform users | First-class platform tier | **Supported (v0.4.3)** — `tenant_id = null` |
| Auth flow routing | Domain-based or group-based | Single flow per tenant |
| TenantScope safety | Fail closed | **Fixed (v0.4.3)** — scopes to null or tenant |
| Auth table portability | N/A (single store) | Single `users` table, no change needed |
| Conditional access policies | Group + device + location | None |

---

## 5. Design Decisions

### D1: TenantScope — What Happens When No Tenant Is Resolved? **DECIDED**

**Decision: `WHERE tenant_id IS NULL`** (option b)

When tenant isolation is enabled but no tenant is resolved, TenantScope scopes queries to `tenant_id IS NULL` — only platform-level users are visible. This:
- Prevents cross-tenant data leakage (tenant users are never exposed without a tenant context)
- Allows platform users (SaaS operators, super admins) to authenticate via non-tenant URLs
- Is less restrictive than `WHERE 1 = 0` which would block all access including legitimate platform users

**Implemented in:** `src/Scopes/TenantScope.php` (v0.4.3)

### D2: Where Do Platform Users Live? **DECIDED**

**Decision: Same `users` table, `tenant_id = null`** (option a)

All user types — platform admins, tenant staff, customers, consultants — share the single `users` table. Differentiation is via:
- `tenant_id`: null (platform) vs specific tenant ID (tenant-scoped)
- Roles (via `ssntpl/laravel-acl`): `platform-admin`, `staff`, `customer`, `vendor`, etc.

**Why not separate models/tables:**
- All auth tables (`emails`, `passwords`, `passkeys`, etc.) FK to `users.id` — a separate table would require polymorphic FKs across 7 tables
- Single identity store is the enterprise standard (Auth0, Okta, etc.)
- Role changes don't require migrating between tables
- `NeevAuthenticatable` trait works without modification

### D3: Should `NeevAuthenticatable` Remain a Composable Trait? **DECIDED**

**Decision: Keep as internal organization, not an extension point** (option a)

`NeevAuthenticatable` is useful as a code organization pattern (grouping auth relationships and helpers), but it is NOT designed to be used on separate models outside the `users` table. The schema enforces this — all auth tables FK to `users.id`. Documentation should reflect this: the trait is composable within the `User` model hierarchy, not across arbitrary models.

---

## 6. Open Design Questions (v0.5.0)

### Q1: How Should Per-Group Auth Methods Work?

The tenant needs to configure multiple auth policies, each tied to a role/group.

**Schema direction (strawman):**
```
auth_policies
├── id
├── owner_type + owner_id (polymorphic: tenant or team)
├── role_slug (e.g., 'staff', 'customer', 'vendor', '*' for default)
├── auth_method ('sso', 'password', 'magicauth')
├── sso_provider, sso_client_id, sso_client_secret, sso_tenant_id
├── mfa_required (boolean)
├── mfa_methods (json: ['totp', 'email'])
├── priority (for fallback/ordering)
└── timestamps
```

This replaces `tenant_auth_settings` / `team_auth_settings` with a single polymorphic `auth_policies` table that supports multiple policies per tenant.

**Backward compatibility:** A tenant with a single policy where `role_slug = '*'` behaves identically to today's single `auth_method`.

**Login flow routing:**
1. User enters email on login page
2. System resolves tenant (from domain/subdomain)
3. System looks up user by email within tenant
4. If user exists → check their role → find matching `auth_policy` → route to that auth flow
5. If user doesn't exist → check email domain → match against tenant's default policy for new users

**Open sub-questions:**
- Should a user be able to have multiple auth methods (SSO primary, password fallback)?
- What happens when a user's role changes — does their auth method change automatically?
- Can a tenant enforce "customers MUST use magic auth" or just "customers CAN use magic auth"?
- How does self-registration work when different user types have different flows?
- Should email domain routing be a first-class feature? (e.g., `@company.com` → SSO policy, `@gmail.com` → magic auth policy)

### Q2: Auth Flow Routing Mechanism

How does the login page determine which auth method to present?

**Options:**
- **(a) Pre-auth email lookup** — user enters email, system resolves their role and auth policy before showing the auth form. Most flexible but requires an extra round-trip.
- **(b) Email domain rules** — tenant configures domain-to-policy mappings (e.g., `company.com → SSO`, `* → magicauth`). Works for corporate SSO but not for individual user overrides.
- **(c) URL-based separation** — different login URLs per user type (`/staff/login`, `/portal/login`). Simple but rigid.
- **(d) Hybrid** — email domain rules for initial routing, with per-user overrides for exceptions.

### Q3: Platform Auth Policy

Platform users (`tenant_id = null`) also need auth configuration. Where does it live?

**Options:**
- **(a) Global config** — `config('neev.platform_auth_method')` in `neev.php`. Simple, single method for all platform users.
- **(b) Auth policy with `owner = null`** — a row in `auth_policies` with null owner, representing platform-level policy. Consistent with tenant policies.
- **(c) Role-based platform policies** — multiple platform auth policies keyed by role (platform-admin → SSO + MFA, platform-support → password + MFA).

### Q4: Migration Path From Current Schema

Current apps using Neev have `tenant_auth_settings` / `team_auth_settings` with a single `auth_method`. The migration needs to:

1. Create `auth_policies` table
2. Migrate existing `tenant_auth_settings` rows into `auth_policies` with `role_slug = '*'`
3. Keep `tenant_auth_settings` / `team_auth_settings` as deprecated aliases (or remove with a major version bump)
4. Update `EnsureContextSSO` → `EnsureAuthPolicy` middleware
5. Update `TenantSSOController` to support role-aware auth routing
6. Update `TenantSSOManager` to select the correct SSO config based on user role

**Backward compatibility requirement:** A tenant with only a `role_slug = '*'` policy must behave identically to today's single `auth_method`. Existing apps should work without code changes after running the migration.

---

## 7. Immediate Fix (v0.4.3) vs Architectural Change (v0.5.0)

### v0.4.3 — Security fix + platform user support (backward-compatible)

- [x] TenantScope scopes to `WHERE tenant_id IS NULL` when no tenant resolved (prevents cross-tenant leakage while supporting platform users)
- [ ] `TenantResolver::runInContext()` for platform code that needs to operate within a tenant
- [ ] `tenant_id` mass-assignable on User and Email for explicit assignment
- [ ] Document platform user pattern (`tenant_id = null` + roles)

### v0.5.0 — Per-group auth policies (breaking)

- [ ] Design and implement `auth_policies` table (replaces `tenant_auth_settings` / `team_auth_settings`)
- [ ] Per-role auth method configuration within a tenant
- [ ] Auth flow routing (email-domain-based, role-based, or hybrid)
- [ ] Platform auth policy support
- [ ] Migration from `tenant_auth_settings` → `auth_policies`
- [ ] Updated middleware: `EnsureContextSSO` → `EnsureAuthPolicy`
- [ ] Updated login flow with pre-auth email lookup or domain routing
- [ ] Backward-compatible defaults (single `*` policy = current behavior)

---

## 8. References

- [Auth0 Organizations + Connections](https://auth0.com/docs/manage-users/organizations)
- [WorkOS Organizations](https://workos.com/docs/user-management/organizations)
- [Okta Authentication Policies](https://developer.okta.com/docs/concepts/policies/)
- [Azure Entra ID Conditional Access](https://learn.microsoft.com/en-us/entra/identity/conditional-access/overview)
- Neev source: `src/Scopes/TenantScope.php`, `src/Models/TenantAuthSettings.php`, `src/Traits/BelongsToTenant.php`, `src/Traits/NeevAuthenticatable.php`
