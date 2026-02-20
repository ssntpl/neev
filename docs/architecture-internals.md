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
