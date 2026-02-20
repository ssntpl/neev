# Architecture

> Foundational architecture decisions for identity, tenancy, and teams in Neev.
> For implementation details and coding patterns, see [Architecture Internals](./architecture-internals.md).
> For a hands-on setup guide, see [Multi-Tenancy](./multi-tenancy.md).

This document covers:

* Identity strategy (shared vs isolated)
* Tenant and Team concepts
* Control plane vs data plane separation
* Context resolution lifecycle
* SSO ownership model
* Responsibility boundaries between Neev and application code

---

## Core Philosophy

Neev is a **framework layer**, not just scaffolding.

Applications are expected to:

* Depend on Neev long-term
* Upgrade Neev versions over time

Therefore:

* Internal models must remain consistent.
* Core abstractions must be stable.
* Configuration should alter behavior, not architecture.

---

## High-Level Goal

Neev provides:

* Flexible identity strategies
* Optional organizational isolation
* Optional collaboration structures
* Context-aware helper abstractions
* Reusable identity provider integration

Neev does **not** enforce business-domain ownership or product-level policies.

---

## Identity Strategy (Primary Concept)

Identity strategy defines how user identities exist in the system.

Selected at install time and stored in configuration.

---

### 1. Shared Identity Strategy

Characteristics:

* Users are global.
* Same user may belong to multiple teams.
* Tenant concept is hidden or ignored.
* Tenant resolution is skipped.
* Team SSO may exist but does not replace global identity.

Example products:

* GitHub
* Jira
* Trello

Result:

```
tenant = null (or system default)
```

---

### 2. Isolated Identity Strategy

Characteristics:

* Users are scoped inside an organization (internally called tenant).
* Same email may exist in multiple tenants.
* Tenant must be resolved **before authentication**.
* Tenant controls identity provider selection.

Example products:

* White-labelled SaaS
* Reseller platforms

Authentication flow:

```
resolve tenant
→ select identity provider
→ authenticate user
```

---

## Tenant (Internal Concept)

Tenant represents an **identity boundary**, not a business data boundary.

### Rules

* Users belong to exactly one tenant.
* No cross-tenant user interaction.
* Tenant required only in isolated identity mode.
* Tenant may be hidden in shared identity mode.

### Resolution

Possible resolver strategies:

* Domain
* Subdomain
* Header
* Explicit login selector

Important rule:

> In isolated identity mode, tenant must be resolved before login.

---

## Team (Collaboration Concept)

Team represents a collaboration boundary.

### Characteristics

* Users can belong to multiple teams.
* Teams may exist inside a tenant.
* Teams can be hidden when unused.
* Teams do not define identity isolation.

Team slug usage:

* Recommended for URL-based context resolution.
* Slug uniqueness scoped by tenant.

Example:

```
unique (tenant_id, slug)
```

---

## Tenant vs Team (Conceptual Separation)

This distinction is foundational.

### Tenant

* Identity boundary
* Exists before authentication
* Determines identity provider (in isolated mode)
* Users are scoped inside tenant

### Team

* Collaboration boundary
* Exists after authentication
* Controls access context
* Users may belong to many teams

Rule:

> Tenant owns identity.
> Team owns collaboration.

Teams must never replace or represent tenant identity boundaries.

---

## Control Plane vs Data Plane (Important)

Neev supports architectures where a platform manages multiple tenant environments.

### Control Plane

Typically uses **shared identity**.

Contains:

* Platform users
* Platform teams
* Billing, licensing, provisioning
* Tenant lifecycle management

A platform team may represent a customer account.

---

### Data Plane

Uses **isolated identity**.

Contains:

* Tenant
* Tenant users
* Tenant teams (optional)
* Product data

Each tenant remains an independent identity boundary.

---

### Relationship Between Planes

A platform team may be linked to a tenant.

Example:

```
Platform Team  ←→  Tenant
```

Meaning:

* Team represents customer account on platform.
* Tenant represents actual product environment.

Important:

* Team is NOT the tenant.
* Identity boundaries remain independent.
* Users are not automatically shared.

---

## Tenant Hierarchy (Reseller Model)

Tenant-to-tenant relationships may exist for business purposes.

Example:

```
Tenant A (reseller)
    manages
Tenant B (customer)
```

### Allowed

* Billing relationship
* Provisioning relationship
* Reporting relationship

### Not allowed (core rule)

* No identity inheritance
* No shared authentication
* No automatic authorization
* No data access across tenants

Identity context always resolves to exactly one tenant.

Recommended column:

```
managed_by_tenant_id
```

This represents business hierarchy only.

---

## SSO / Identity Provider Ownership

Neev supports a shared abstraction for identity providers.

### Core Principle

Identity provider logic must be reusable across contexts.

Conceptually:

```
IdentityProviderOwner
```

Implemented by:

* Tenant (isolated identity mode)
* Team (shared identity mode)

---

### SSO Attachment Rules

#### Isolated Identity

```
SSO configured on Tenant
```

* SSO participates in authentication.

---

#### Shared Identity

```
SSO configured on Team
```

* User authenticates globally first.
* SSO verifies access to team/org.

Flow:

```
global login
→ access team
→ if team requires SSO:
       verify via team IdP
```

---

## Domain Federation (Shared Identity)

Domain federation is supported as a resolver strategy.

### Concept

```
email domain → team SSO policy
```

Example:

```
acme.com → Team A
```

### Rules

* One domain maps to one team.
* Resolver must be deterministic.
* Ambiguous matches require explicit user selection.

### Scope

Neev core provides:

* Domain → team mapping
* Resolver integration

Neev does NOT provide:

* domain takeover workflows
* migration windows
* forced email reassignment

Those are application-level concerns.

---

## Data Ownership Philosophy

Neev does **not** enforce business ownership.

Developers decide whether models belong to:

* user
* team
* something else

Example:

```
user_id
team_id
custom ownership structure
```

Neev remains neutral.

---

## Optional Convenience Features

Neev provides opt-in traits and scopes.

Examples:

```
BelongsToTenant
BelongsToTeam
ScopedByCurrentTenant
ScopedByCurrentTeam
```

These are optional.

If unused, nothing breaks.

---

## Unified Internal Schema Direction

Schema remains consistent for long-term stability.

Example structure:

```
tenants
users (tenant_id nullable)
teams (tenant_id nullable)
team_user
```

Business tables are defined by the application.

---

## Context System

Neev provides request-scoped context access.

### Context Objects

```
currentUser()
currentTeam()
currentTenant()
```

---

### Context Lifecycle

Context resolved via middleware.

Flow:

```
ResolveTenant
→ ResolveTeam
→ BindContext
```

Context stored in container:

```
ContextManager
  - tenant
  - team
  - user
```

Rules:

* Resolution occurs once per request.
* Context is read-only after binding.
* No lazy re-resolution.

---

## Strictness Rules

Behavior depends on identity strategy.

### Shared Identity

* Missing tenant is valid.
* Tenant context may be null.

### Isolated Identity

* Tenant required.
* Missing or invalid tenant results in immediate failure (404/403).

Resolvers resolve only; strategy decides strictness.

---

## URL & Context Recommendation

Preferred pattern:

```
tenant-domain.com/{team_slug}/...
```

or when tenancy hidden:

```
app.com/{team_slug}/...
```

Principles:

* Tenant resolved first.
* Team resolved second.
* Team context explicit when used.

---

## Installation Philosophy

Neev uses install presets.

Presets define configuration, not separate architectures.

Conceptual presets:

* Personal (auth only)
* Teams
* Isolated identity
* Isolated identity + teams

Internal architecture remains consistent.

---

## Performance & Overhead Principles

Accepted tradeoff:

* Small runtime overhead is acceptable for stability.

Optimizations:

* Shared identity mode can skip tenant resolution.
* Nullable tenant_id overhead is minimal and acceptable.

---

## Policy Boundary

This is critical.

### Neev decides

```
HOW identity is resolved
```

### Application decides

```
WHO is allowed
```

Examples of app-level policies:

* external members allowed or blocked
* company-only emails
* strict SSO enforcement
* enterprise governance rules
* reseller governance rules

---

## What Neev Owns vs What App Owns

### Neev owns

* Identity strategy
* Tenant resolution
* Team membership system
* Context management
* Identity provider abstraction
* Resolver system
* Convenience traits/scopes

### Application owns

* Business data structure
* Ownership logic
* Authorization rules
* Tenant hierarchy policies
* User governance policies
* Domain management workflows
* Billing / licensing logic

---

## Key Architectural Summary

1. Identity strategy defines user isolation.
2. Tenant is an identity boundary.
3. Team is a collaboration boundary.
4. Tenant and Team must remain separate concepts.
5. Control plane and data plane are separate concerns.
6. Teams may represent customer accounts in control plane.
7. SSO attaches to Tenant (isolated) or Team (shared).
8. Tenant hierarchy is business metadata only.
9. Business ownership is developer-defined.
10. Context resolves once per request.
11. Configuration changes behavior, not core structure.

---

## Stability Principle

Because Neev is a framework layer:

* Core abstractions must remain stable.
* Internal mental model must stay consistent.
* New features should extend existing context and resolver systems.

---

## Foundation Statement

Neev should be understood as:

> An identity and collaboration context framework for Laravel applications.

Not a forced multi-tenancy or business ownership system.

---

---

## Related Documentation

- [Architecture Internals](./architecture-internals.md) -- implementation patterns, interfaces, and coding standards
- [Multi-Tenancy](./multi-tenancy.md) -- practical setup guide for tenant isolation and SSO
- [Teams](./teams.md) -- team management, invitations, and domain federation
- [Configuration](./configuration.md) -- all configuration options including identity strategy
