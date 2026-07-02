# RFC 003 — Per-Group Auth Policies

> **Status:** Proposed (resolves RFC-001 §6 Q1–Q4 with concrete design; awaiting maintainer review)
> **Supersedes:** RFC-001's "v0.5.0 open questions" section (RFC-001's decided parts D1–D3 shipped in v0.4.3 and remain authoritative)
> **Drivers:** TAILLOG multi-population tenants (staff → SSO, customers → magic link, vendors → password+MFA)

## 1. Problem (recap from RFC-001)

`tenant_auth_settings` / `team_auth_settings` hold **one** `auth_method`
per tenant. Real tenants have multiple user populations with different
auth requirements. The enterprise-standard shape (Auth0 connections,
Okta authentication policies, Entra conditional access) is
**policy-per-group**, evaluated at login time.

## 2. Design summary

One new table, one resolver service, one identifier-first endpoint:

```
auth_policies
├── id
├── owner_type + owner_id     (nullable morph: tenant | team | NULL = platform)
├── role_slug                 (nullable: match users holding this role)
├── email_domain              (nullable: match by email domain, pre-auth)
├── auth_method               ('password' | 'sso' | 'magicauth')
├── sso_provider, sso_client_id, sso_client_secret (encrypted),
│   sso_tenant_id, sso_extra_config (json)
├── mfa_required              (boolean)
├── mfa_methods               (json, subset of config('neev.multi_factor_auth'))
├── priority                  (int, lower wins)
├── timestamps
└── unique(owner_type, owner_id, role_slug, email_domain)
    index(owner_type, owner_id, priority)
```

A policy matches by **role** (`role_slug` set), by **email domain**
(`email_domain` set), or as the **default** (both null — the `'*'`
policy in RFC-001's strawman, made explicit as nulls instead of a magic
string).

### 2.1 Matching algorithm (`AuthPolicyResolver::resolve`)

Given a context (tenant/team/platform) and an email:

1. Load the context's policies ordered by `priority`, then specificity.
2. If a user with that email exists in the context: the first policy
   whose `role_slug` the user holds wins.
3. Otherwise (or if no role policy matched): the first policy whose
   `email_domain` matches the email's domain wins.
4. Otherwise: the default policy (`role_slug` and `email_domain` both
   null).
5. Otherwise: fall back to `auth_method = 'password'` with global MFA
   behaviour — **byte-for-byte today's behaviour for contexts with no
   policies**.

Ties inside a tier are broken by `priority` (lower first). Specificity
order is fixed and not configurable: role > domain > default (a
layer-1 invariant — configurable match order is a footgun).

Results are cached per (context, email-domain) with the same 30-minute
TTL as the current auth-settings cache; role matches are per-user and
resolved live (one indexed query).

## 3. RFC-001's open questions — resolutions

### Q1 sub-questions

- **Multiple auth methods per user (primary + fallback)?** **No, in
  v1.** One matched policy → one required method. Recovery codes
  remain the MFA fallback. A future `allowed_methods` json column can
  add CAN-semantics without schema breakage; starting with it would
  multiply the routing matrix before any consumer needs it (guardrail:
  "someone might need it" is not a requirement).
- **Role change → auth method change?** **Automatic at next login.**
  Policies are evaluated per login attempt, never stored on the user.
  Existing sessions/tokens are untouched until they expire or the app
  revokes them (documented).
- **MUST or CAN?** **MUST.** `auth_method` is enforced: a user matched
  to an SSO policy cannot password-login. This matches the semantics
  of today's `requiresSSO()` and is the posture enterprise buyers
  expect. (CAN is the future `allowed_methods` extension.)
- **Self-registration routing?** Self-registering users have no role
  yet, so **registration always routes via domain or default policy**;
  invited users get the invitation's role, so their *next login*
  routes via that role's policy. This resolves RFC-001's circularity:
  the role tier simply cannot apply to a user who doesn't exist.
  `RegistrationService` (the single registration entry point since
  v0.5.0) asks the resolver whether the matched policy permits
  registration at all (an SSO-only domain policy means "provision via
  SSO, don't self-register").
- **Email-domain routing first-class?** **Yes** — the `email_domain`
  column, not a separate table. It is exactly WorkOS/Auth0 domain
  capture and is the only way to route users who don't exist yet.

### Q2: routing mechanism → **(d) hybrid, identifier-first**

The industry-standard identifier-first flow (Okta, Auth0, Entra all
ask for the identifier before choosing a flow):

- **New endpoint:** `POST {prefix}/auth/methods` `{email}` → the
  resolved flow for that email in the current context:

  ```json
  { "auth_method": "sso", "sso_redirect_url": "…", "mfa_required": true }
  ```

- The **web flow already is identifier-first** (`/login` collects the
  email, then shows `login-password`) — `loginPassword` consults the
  resolver and renders/redirects accordingly instead of only checking
  `requiresSSO()` on the context.
- SPAs call the endpoint after their email step and branch.
- URL-based separation is **not** built in; apps that want
  `/staff/login` publish the routes file (existing escape hatch).

**User-enumeration consideration (security §6):** the endpoint's
response must be computable from the email *string* alone wherever
possible. Domain and default policies are; role policies are the one
tier that can differ for existing users. Mitigations: identical
response shape for known/unknown users, no "user exists" field,
`throttle:10,1` like the other pre-auth endpoints, and documentation
that role-tier policies marginally trade enumeration resistance for
routing precision (the same trade-off Okta's identifier-first makes).

### Q3: platform auth policy → **(b) null-owner rows**

Platform policies are `auth_policies` rows with `owner_type/owner_id
= null`. One mechanism everywhere; and because `role_slug` still
works, option (c) (per-role platform policies: `platform-admin` → SSO
+ MFA) comes for free. No config key. No policies → password, today's
behaviour.

### Q4: migration path

Shipped **with a data migration this time** (unlike the 0.4.5 table
consolidation):

1. Create `auth_policies`.
2. Copy every `tenant_auth_settings` / `team_auth_settings` row into a
   default policy (null role/domain) for the same owner.
3. `HasTenantAuth`'s public surface (`requiresSSO()`,
   `hasSSOConfigured()`, `authSettings`) delegates to the owner's
   default policy for one release (deprecated), so consuming apps run
   unchanged; the old tables are dropped in the release after.
4. `EnsureContextSSO` keeps working (reads the default policy);
   new `EnsureAuthPolicy` middleware generalises it (asserts the
   session's login method satisfies the *user's* matched policy).
5. `TenantSSOManager` selects SSO credentials from the matched policy,
   enabling different IdPs per group within one tenant.
6. `neev:auth:configure` gains `--role=` / `--domain=` /
   `--priority=`; `neev:auth:show` lists all policies for an owner.

**BC invariant (testable):** an owner with exactly one default policy
behaves identically to today's single `auth_method` — the acceptance
gate for phase A.

## 4. What this deliberately does not do (non-goals)

- Conditional access (device/location/IP rules) — different feature
  (see TODO's IP-allowlist item).
- Per-user auth-method overrides — the role tier covers the need;
  a per-user policy would reintroduce the "auth method stored on the
  user" coupling this design avoids.
- SAML — orthogonal; when SAML lands, it becomes another
  `auth_method`/provider on the same policy rows.

## 5. Phasing

| Phase | Scope | Risk |
|---|---|---|
| **A** | Schema + `AuthPolicy` model + data migration + `HasTenantAuth` delegation. Zero behaviour change (BC invariant test) | Low |
| **B** | `AuthPolicyResolver` + `POST {prefix}/auth/methods` + web/API login integration + `EnsureAuthPolicy` | Medium — touches login flows |
| **C** | Per-policy SSO credential selection (`TenantSSOManager`), registration routing via `RegistrationService`, CLI options, docs | Medium |

## 6. Security considerations

- SSO client secrets keep the `encrypted` cast (unchanged posture).
- Enumeration surface of the identifier-first endpoint: see Q2. The
  endpoint returns flows, never existence; throttled; role-tier
  trade-off documented.
- Policy matching order is fixed (invariant), and "no policy" falls
  back to password + global MFA — fail-open to *today's* behaviour,
  never to "no auth".
- `mfa_required = true` with a user who has no active MFA method:
  the login flow forces MFA enrolment before issuing the token
  (pending-state machinery from v0.5.0 makes this safe).

## 7. Open items for maintainer review

1. **`magicauth` as an enforceable method:** magic links are currently
   always available. Under a MUST policy, should a `password` policy
   *block* magic-link login for matched users? Proposal: yes —
   enforcement means the matched method only (recovery codes excepted).
2. **`mfa_methods` narrowing:** when a policy lists `['authenticator']`,
   a user's active email-OTP method no longer satisfies MFA. Proposal:
   enforce at challenge time, prompt enrolment if needed.
3. Naming: `auth_policies` vs `auth_connections` (Auth0 vocabulary).
   Proposal: policies — it describes enforcement, not just linkage.
