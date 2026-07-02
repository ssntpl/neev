# Architecture Internals

> Implementation patterns and coding standards for Neev's identity and collaboration systems.
> This document complements [Architecture](./architecture.md), which defines the conceptual foundations.

This document covers:

* Interfaces and contracts
* Code reuse patterns (traits, services, scopes)
* Implementation standards and anti-patterns
* Extension guidelines

The [Architecture](./architecture.md) document remains the source of truth for
conceptual boundaries, identity rules, and invariants.

---

## Core Implementation Principle

Concepts remain separate.

Implementation is shared.

```
Tenant != Team (conceptually)
Tenant ≈ Team (implementation capabilities)
```

Neev minimizes duplication by sharing behavior, not by collapsing models.

---

## Implementation Goals

1. Minimal code duplication.
2. Strong abstraction boundaries.
3. Clear extensibility.
4. Stable internal contracts.
5. Avoid entity-type conditionals.
6. Framework-safe evolution.

---

## Shared Capability Model

Tenant and Team share many operational behaviors.

These must be implemented using reusable capabilities.

### Examples of Shared Capabilities

* Membership handling
* Identity provider ownership (SSO)
* Resolver participation
* Context binding compatibility
* Policy hooks
* Scopes and convenience helpers

---

## Context Container Concept

Internally, Tenant and Team are treated as **context containers**.

A context container is an object that:

* may own members
* may own identity providers
* may be resolvable from a request
* may bind context

This is an implementation abstraction only.

It does not alter conceptual meaning.

---

## Interfaces (Preferred Abstraction)

Shared systems operate on interfaces rather than concrete models.

Example conceptual contracts:

```
ContextContainerInterface
IdentityProviderOwnerInterface
HasMembersInterface
ResolvableContextInterface
```

### Rules

* Services depend on interfaces.
* Concrete models implement contracts.
* Shared logic must not require entity-specific knowledge.

---

## Traits / Composition Strategy

Traits may be used for reusable functionality.

Examples:

```
HasMembers
HasIdentityProviders
CanResolveFromRequest
```

Rules:

* Traits contain reusable mechanics only.
* Traits must not encode business meaning.
* Traits must not assume entity type.

---

## Service Layer Strategy

Shared logic should live in services.

Examples:

```
MembershipService
IdentityProviderService
ContextResolver
```

Services operate against interfaces.

Avoid duplicating services per entity:

Bad:

```
TenantMembershipService
TeamMembershipService
```

Preferred:

```
ContainerMembershipService
```

---

## Resolver Implementation

Resolvers should be reusable components.

Resolution concerns:

* extraction logic
* lookup strategy
* validation

Strictness decisions remain external (identity strategy).

Resolvers must not enforce policy.

---

## Context Binding

Context binding remains single-pass per request.

Shared contract:

```
ContextBindable
```

Rules:

* Context is resolved once.
* Context is immutable after binding.
* No lazy resolution allowed.

---

## Middleware Usage & Ordering

This is the authoritative reference for Neev's middleware groups and aliases. Compositions below mirror `NeevServiceProvider::boot()` exactly.

### Middleware Groups

Every group ends with `BindContextMiddleware`. Its `handle()` calls `ContextManager::bind()`, which flips the immutability flag — any later attempt to mutate the context (`setTenant()`, `setTeam()`, `setUser()`, `setContext()`) throws a `LogicException`. It also clears the context after the response, so nothing leaks into the next request on long-running runtimes.

| Group | Composition (in order) | Use for |
|-------|------------------------|---------|
| `neev:web` | `TenantMiddleware` → `ResolveTeamMiddleware` → `NeevMiddleware` → `EnsureTenantMembership` → `BindContextMiddleware` | Session-authenticated web (Blade) routes |
| `neev:api` | `TenantMiddleware` → `ResolveTeamMiddleware` → `EnsureSpaRequestsAreStateful` → `NeevAPIMiddleware` → `EnsureTenantMembership` → `BindContextMiddleware` | Bearer-token API routes; `EnsureSpaRequestsAreStateful` also lets same-origin SPAs authenticate via the HttpOnly cookie |
| `neev:login` | `TenantMiddleware` → `ResolveTeamMiddleware` → `EnsureSpaRequestsAreStateful` → `JwtLoginMiddleware` → `EnsureTenantMembership` → `BindContextMiddleware` | Routes authenticated by the temporary MFA JWT — the step between password login and MFA verification (e.g. the OTP verify endpoint) |
| `neev:tenant` | `TenantMiddleware:required` → `ResolveTeamMiddleware` → `BindContextMiddleware` | Tenant resolution **without** user authentication (public tenant pages, pre-login tenant discovery). The `required` parameter makes it return 404 when no tenant resolves |

The internal ordering inside each group is deliberate and must not be re-created differently by hand:

1. `TenantMiddleware` — resolves the tenant from the request (X-Tenant header, then host). Must run first so everything downstream sees the tenant.
2. `ResolveTeamMiddleware` — resolves the team (route parameter / context).
3. Authentication (`NeevMiddleware`, `NeevAPIMiddleware`, or `JwtLoginMiddleware`) — authenticates the user *after* tenant resolution, because in isolated mode the tenant determines the user namespace.
4. `EnsureTenantMembership` — needs both the resolved tenant and the authenticated user.
5. `BindContextMiddleware` — always last; locks the fully populated context.

### Middleware Aliases

Aliases are single-purpose checks you attach **in addition to** a group:

| Alias | Class | Purpose |
|-------|-------|---------|
| `neev:active-team` | `EnsureTeamIsActive` | Reject requests when the current team is deactivated |
| `neev:active-tenant` | `EnsureTenantIsActive` | Reject requests when the current tenant is deactivated |
| `neev:tenant-member` | `EnsureTenantMembership` | Standalone membership check (already inside the auth groups; use on custom stacks) |
| `neev:resolve-team` | `ResolveTeamMiddleware` | Standalone team resolution (already inside the groups; use on custom stacks) |
| `neev:ensure-sso` | `EnsureContextSSO` | When the resolved tenant/team requires SSO, reject (API) or redirect (web) sessions that were not established via SSO |
| `neev:password-not-expired` | `EnsurePasswordNotExpired` | Block access once the user's password has expired |
| `neev:verified-email` | `EnsureEmailIsVerified` | Block access until the user's email is verified |

### Ordering Rules

* **Group first, aliases after.** Aliases like `neev:ensure-sso`, `neev:active-team`, and `neev:password-not-expired` need the authenticated user and resolved context, which only the group provides:

```php
Route::middleware(['neev:api', 'neev:active-team', 'neev:ensure-sso'])->group(function () {
    // ...
});
```

* **Custom application middleware runs after the Neev group** if it needs the resolved context. Placed after the group, it can safely read `ContextManager` (`currentTenant()`, `currentTeam()`, `currentUser()`) and `$request->user()`:

```php
Route::middleware(['neev:api', EnforceSubscriptionLimits::class])->group(function () {
    // EnforceSubscriptionLimits sees the bound, immutable context
});
```

* **Custom middleware must not mutate the context.** By the time it runs, `BindContextMiddleware` has locked it — mutation throws a `LogicException`. If you need code to run *before* binding (rare), compose the individual middleware classes yourself instead of using the group, keeping the internal order above and `BindContextMiddleware` last.
* **Never apply two Neev groups to the same route** — each group is a complete stack.

When `tenant => false`, the tenant-specific steps are no-ops and the groups behave as plain session/token authentication stacks.

---

## Identity Provider Ownership

Identity providers are implemented generically.

Owner contract:

```
IdentityProviderOwnerInterface
```

Possible owners:

* Tenant
* Team

SSO logic must not branch based on concrete type.

---

## Membership Abstraction

Membership systems operate via shared contracts.

Membership semantics differ conceptually but share implementation.

Rules:

* Membership storage logic must be reusable.
* Role interpretation belongs to application logic.

---

## Coding Standards

### 1. Prefer Composition over Inheritance

Inheritance may be used only for shared infrastructure behavior.

Business meaning must not rely on inheritance trees.

---

### 2. Avoid Type Conditionals

Forbidden pattern:

```
if ($model instanceof Tenant) { ... }
```

If branching is required:

* extract interface behavior
* use polymorphism

---

### 3. Shared Services Must Be Entity-Agnostic

Services should operate on contracts, not models.

---

### 4. Keep Conceptual Rules Outside Shared Layer

Example:

* identity strictness
* isolation guarantees
* collaboration rules

These belong to strategy/policy layers.

---

## Extension Guidelines

New features should:

* extend existing contracts
* compose capabilities
* avoid introducing parallel implementations

Before adding a new subsystem, ask:

```
Can this be expressed as a capability?
```

---

## Anti-Patterns

### 1. Entity Duplication

Avoid creating parallel systems for Tenant and Team.

---

### 2. Hidden Concept Merging

Do not introduce abstraction that silently makes Team behave as Tenant or vice versa.

---

### 3. Strategy Leakage

Identity strategy must not leak into shared abstractions.

---

## Relationship to Architecture Foundation

This document implements the ideas defined in [Architecture](./architecture.md).

If an implementation conflicts with the foundation, the foundation wins.

---

## Evolution Policy

Implementation architecture may evolve.

Allowed:

* replacing traits with services
* changing internal abstractions
* refactoring interfaces

Not allowed:

* violating conceptual boundaries defined in the foundation.

---

## Implementation Summary

1. Tenant and Team remain conceptually distinct.
2. Shared behavior is implemented through capabilities.
3. Services operate via interfaces.
4. Resolvers remain neutral and reusable.
5. Context binding stays single-pass and immutable.
6. Avoid duplication through composition, not merging.

---

---

## Related Documentation

- [Architecture](./architecture.md) -- conceptual foundations for identity, tenancy, and teams
- [Multi-Tenancy](./multi-tenancy.md) -- practical setup guide
- [Teams](./teams.md) -- team management guide
