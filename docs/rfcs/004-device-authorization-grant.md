# RFC 004 — Device Authorization Grant (RFC 8628)

> **Status:** Proposed (awaiting maintainer review)
> **Drivers:** limited-input clients — TVs, consoles, kiosks, CLIs — where typing a password or clicking an email link is impractical
> **Relationship:** builds the token-issuance-to-a-secondary-device core that RFC 005 (full OAuth authorization server) later generalises; independent of RFC 003 but composes with it (the phone-side approval runs the full policy stack)

## 1. Problem

Neev has no answer for devices where the user cannot reasonably
complete any of the existing flows: password entry with a TV remote is
hostile, email links are unclickable on a console, and SSO redirects
are impossible without a full browser. The industry-standard solution
is the OAuth 2.0 **Device Authorization Grant** (RFC 8628) — the
Netflix/YouTube pattern:

1. Device shows a short code and a URL.
2. User opens the URL on their phone/laptop, signs in **normally**,
   enters the code, approves.
3. Device, which has been polling, receives a token and is signed in.

The decisive property: **the actual authentication happens on the
user's own capable device**, so every neev feature — password rules,
MFA, passkeys, tenant SSO, and (once RFC 003 lands) per-group auth
policies — applies unchanged. The TV never sees a credential.

## 2. Scope: first-party only

Phase 1 serves the consuming app's **own** device clients. There is no
third-party client registry, no consent screen listing scopes for an
external application, no client secret dance — that is RFC 005. A
device identifies itself with a self-declared `client_name` (shown to
the user at approval: "Living-room TV wants to sign in") and optional
requested token `permissions` (neev's existing per-token permissions).

## 3. Design

### 3.1 Schema

```
device_authorizations
├── id
├── device_code               (hashed — the polling secret)
├── user_code                 (e.g. "BDWP-HQPK"; indexed, unique among active)
├── client_name               (self-declared, shown at approval)
├── permissions               (json, nullable — requested token permissions)
├── status                    ('pending' | 'approved' | 'denied')
├── user_id                   (nullable FK; set at approval)
├── tenant_id                 (nullable — BelongsToTenant, resolved at creation)
├── last_polled_at            (for interval enforcement / slow_down)
├── expires_at
└── timestamps
```

- `device_code`: high-entropy random, stored hashed (same posture as
  access tokens) — it is a bearer secret for the poll step.
- `user_code`: 8 characters from a 20-character consonant alphabet
  (RFC 8628 §6.1 recommendation), displayed grouped (`BDWP-HQPK`).
  Short-lived (default 10 minutes) and rate-limited, which bounds the
  guessing space safely.

### 3.2 Endpoints (all under `route_prefix`)

| Endpoint | Caller | Purpose |
|---|---|---|
| `POST {prefix}/device/code` | the device | returns `device_code`, `user_code`, `verification_uri`, `verification_uri_complete`, `expires_in`, `interval` |
| `POST {prefix}/device/token` | the device (polling) | RFC 8628 responses: `authorization_pending`, `slow_down`, `expired_token`, `access_denied`, or the token (`{id}\|{plaintext}`, same shape as login) |
| `GET {prefix}/device` (web, Blade kit) + `POST {prefix}/device/approve` (neev:api, for SPAs) | the user's phone | enter/confirm the code, approve or deny — behind normal authentication |

The approval step runs behind the standard auth stack: the user logs
in on their phone via whatever method applies to them (password, MFA,
passkey, SSO — and RFC 003 policies once shipped). This is the whole
point of the pattern and why neev should own it rather than leave it
to apps.

`verification_uri_complete` (URL with the code embedded) enables the
QR-code UX: TV shows a QR, phone scans, user only confirms.

### 3.3 Flow states and enforcement

- Polling before `interval` elapses → `slow_down` (and the interval
  is bumped, per RFC).
- Codes are single-use: approval consumes the `user_code`; token
  issuance consumes the `device_code`; both then reject reuse.
- Expiry: `pending` rows expire (default 10 min); a
  `neev:clean-device-authorizations` command purges them (same pattern
  as pending MFA setups).
- Token issued is a normal neev login token — revocable via the
  existing session UI (`GET/DELETE {prefix}/sessions*`), visible in
  login attempts. Nothing new to audit.

### 3.4 Layer mapping (design principles)

- **Invariants:** hashed `device_code`; single-use codes; approval
  requires full authentication on the secondary device; user-code
  entropy/alphabet not configurable.
- **Values:** code lifetime, poll interval, user-code length
  (bounded), permissions default.
- **Composition:** events — `DeviceAuthorizationRequested`,
  `DeviceAuthorized`, `DeviceAuthorizationDenied` — for the app's
  notification/audit layers.
- **Kit:** the Blade kit gains an activate/approve page; headless apps
  use the two JSON endpoints from their own UI (documented in the SPA
  guide).

## 4. Security considerations (RFC 8628 threat model)

- **Remote phishing** ("enter this code" scams) is the known weakness
  of the pattern. Mitigations neev ships: the approval page always
  names the `client_name` and shows the requesting IP's coarse GeoIP
  location ("A device near Mumbai wants access"), and the docs require
  consumers to display similar context. This mirrors what Google/
  Microsoft do; the residual risk is inherent to the grant and gets a
  prominent docs warning.
- Brute force on `user_code`: bounded by entropy (20^8), short expiry,
  strict throttle on the approval endpoints, and lockout of a code
  after N failed confirmation attempts.
- `device_code` leakage: hashed at rest; polling throttled per code.

## 5. Phasing

| Phase | Scope |
|---|---|
| A | Schema, model, the two device endpoints, polling semantics, cleanup command, events |
| B | Approval endpoints + Blade-kit page + SPA-guide section, QR (`verification_uri_complete`) |
| C | Docs (api-reference, security), device-flow section in the SPA/consumer guides |

## 6. Non-goals

- Third-party clients, scopes-as-consent, client registration — RFC 005.
- CIBA / push-approval flows — out of scope.
- Replacing email OTP verification — orthogonal (see the verification
  discussion; codes there prove mailbox ownership, codes here bind a
  device to an authenticated session).
