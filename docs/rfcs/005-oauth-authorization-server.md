# RFC 005 — Neev as an OAuth 2.1 Authorization Server

> **Status:** Proposed — strategic direction; intentionally gated behind RFC 004 (which builds and battle-tests the token-issuance core this RFC generalises)
> **Drivers:** neev apps that need to *be* an identity provider — third-party integrations calling their APIs, partner apps, "Sign in with <YourApp>", machine-to-machine access

## 1. Problem

Neev currently sits on only one side of OAuth: it **consumes** other
providers (social login via Socialite, tenant SSO via OIDC). A
neev-powered app cannot:

- let a third-party application request scoped API access on a user's
  behalf (authorization-code flow with consent),
- issue machine-to-machine credentials to integration partners
  (client-credentials flow),
- act as the IdP for a family of first- and second-party apps
  ("Sign in with TAILLOG").

For an "enterprise-grade auth package", this is the largest remaining
capability gap. It is also the largest remaining build — hence this
RFC exists to fix *direction and scope* first.

## 2. The build-vs-Passport question (answered honestly)

Laravel Passport is the ecosystem's full OAuth2 server. The
design-principles litmus test ("would Sanctum make you write this?")
cuts both ways here:

**Why not just recommend Passport alongside neev:**

- Passport issues *its own* tokens from *its own* tables, guarded by
  its own middleware — a second, parallel token system next to neev's
  `access_tokens`, with none of neev's machinery: no per-token
  permissions integration, no session UI visibility, no login-attempt
  audit, no tenant scoping, no SPA-cookie interplay.
- Critically, the **authorization endpoint is an authentication
  moment**, and Passport knows nothing about neev's MFA gates, tenant
  SSO enforcement, `EnsureContextSSO`, or RFC 003 policies. Bolting
  Passport on means the most sensitive flow in the system bypasses
  everything neev enforces.
- Two token vocabularies is precisely the "parts library" failure mode
  the design principles warn about.

**Why not naively rebuild Passport:**

- Full OAuth2 (all grants, JWT access tokens, introspection, OIDC
  id_tokens, dynamic client registration) is a protocol-compliance
  treadmill with real security surface.

**Resolution: a deliberately minimal OAuth 2.1 subset, native to
neev's primitives.** OAuth 2.1 already deletes the dangerous legacy
(implicit grant, password grant, bare auth-code without PKCE), which
shrinks the surface to something a package this size can own well:

| In scope | Explicitly out (v1) |
|---|---|
| Authorization-code + **PKCE (mandatory)** | Implicit & password grants (dead in 2.1) |
| Refresh tokens (rotating) | JWT access tokens / introspection endpoint |
| Client-credentials grant | OIDC `id_token` / discovery / dynamic client registration |
| Device grant (already RFC 004) | Token exchange (RFC 8693) |

Access tokens are **neev `AccessToken` rows** (opaque
`{id}|{plaintext}`, hashed, revocable in the session/token UI, tenant
scoped, permission-carrying) — scopes map onto the existing per-token
`permissions` json. One token system, everywhere.

## 3. Design sketch

### 3.1 Schema

```
oauth_clients
├── id, name
├── client_id (public), client_secret (hashed, nullable — public clients use PKCE only)
├── redirect_uris (json)
├── owner_type + owner_id (nullable morph — platform-, tenant-, or team-owned clients)
├── first_party (boolean — skips the consent screen)
├── allowed_scopes (json), revoked (boolean)
└── timestamps

oauth_authorization_codes      (code hash, client, user, scopes, PKCE challenge, redirect_uri, expiry ≤ 60s, single-use)
oauth_refresh_tokens           (token hash, access_token_id FK, rotation chain, expiry)
```

`access_tokens` gains a nullable `client_id` — the only change to an
existing table.

### 3.2 Endpoints (under `route_prefix`)

- `GET {prefix}/oauth2/authorize` — behind the **full neev auth
  stack**: the user authenticates via whatever applies to them
  (password/MFA/passkey/SSO/RFC-003 policy), then sees the consent
  screen (Blade-kit page; headless JSON variant for SPA-rendered
  consent). `first_party` clients skip consent.
- `POST {prefix}/oauth2/token` — code+PKCE exchange, refresh rotation,
  client credentials.
- `POST {prefix}/oauth2/revoke` — RFC 7009, mapping to AccessToken
  deletion (existing revocation semantics).

*(Path segment `oauth2/` deliberately distinct from the existing
`oauth/{service}` consumer routes.)*

### 3.3 Management

- CLI: `neev:oauth:client` (create/list/revoke) mirroring the existing
  command families; tenant-owned clients for RFC 003-style
  multi-tenant ecosystems.
- Events: `ClientCreated`, `AccessGranted`, `AccessRevoked` — audit
  and notification hooks per the composition layer.

### 3.4 Layer mapping

- **Invariants:** PKCE mandatory (no exception, not configurable);
  exact-match redirect URIs; hashed secrets/codes; single-use codes;
  rotating refresh tokens with reuse detection (rotation-family
  revocation on replay).
- **Values:** token/refresh/code lifetimes, scope catalogue.
- **Composition:** consent page in the Blade kit (app-owned once
  ejected); events; scopes = existing token permissions.

## 4. Sequencing (why this waits for RFC 004)

RFC 004 forces the shared core into existence with a tenth of the
surface: an authorization record created by an unauthenticated client,
approved by an authenticated user on another surface, redeemed for a
neev token, with polling/expiry/single-use semantics. Every one of
those mechanisms (and their tests) is reused here. Shipping 004 first
means 005 starts from proven primitives instead of a blank page —
and gives a real-world checkpoint to validate demand before the
largest build in the package's history.

Proposed order: RFC 003 (policies) → RFC 004 (device grant) →
**re-review this RFC** → phased implementation (authorize+token with
PKCE first; client credentials second; refresh rotation hardening
third).

## 5. Open questions for maintainer review

1. **OIDC:** should v1 also issue an `id_token` (making "Sign in with
   <YourApp>" standards-compliant OIDC rather than plain OAuth)?
   Proposal: no for v1 — consumers of first-party ecosystems can use
   the existing user endpoint with a scoped token; OIDC discovery/JWKS
   is a v2 once client demand is concrete.
2. **Scope vocabulary:** free-form strings mapped to token
   `permissions`, or a registered scope catalogue per app? Proposal:
   app-registered catalogue (config or DB) — free-form scopes on a
   consent screen are a phishing aid.
3. **Passport coexistence note:** document "if you already run
   Passport, keep it — do not run both servers for the same audience"?
   Proposal: yes, one honest paragraph.
