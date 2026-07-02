# Design Principles

How neev decides what is enforced, what is configurable, and what is
left to the consuming application. Every feature proposal and PR should
be judged against this document.

The goal is the "drop-in" promise: **install, migrate, flip two flags,
and a complete, secure auth flow works with zero application code** —
while never boxing a real application in.

The pattern neev follows is the one proven by Sanctum, Fortify, and
Devise: **strong opinions at the flow layer, full freedom at the code
layer, and very few knobs in between.** Both failure modes are real:
too many config options produced neev's pre-0.4.0 config (three
interacting flags, eight combinations, some invalid); too much left to
the developer produces a parts library instead of a product.

---

## The Four Layers

### 1. Security invariants — enforced, never configurable

Behaviour where a wrong choice is a vulnerability. No config key may
exist to weaken these, because any such key will eventually be set
wrong in production. The package's core value is being trustworthy by
default; a knob here is a liability, not flexibility.

Current invariants:

- Tokens, OTPs, and recovery codes are stored hashed; MFA secrets and
  SSO client secrets encrypted
- `TenantScope` is fail-closed: no resolved tenant → platform records
  only, never unscoped
- API tokens are accepted only via the `Authorization: Bearer` header
- An unverified (pending) MFA setup never gates login and never
  satisfies an MFA challenge
- CSRF is validated on state-changing SPA-cookie requests (when SPA
  cookie mode ships)

### 2. Product policies — configurable values

Where reasonable applications genuinely differ, and the difference is
a **value**, not a behaviour branch: expiry minutes, retention days,
enabled MFA methods, password rules, slug rules, the `tenant`/`team`
identity flags. These live in `config/neev.php` with
safe-for-production defaults.

### 3. Composition — opt-in primitives

The package ships mechanisms fully built; the application decides
*where* they apply. Nothing branches inside neev's code:

- Enforcement middleware aliases (`neev:verified-email`,
  `neev:password-not-expired`, `neev:active-team`,
  `neev:active-tenant`, `neev:ensure-sso`) — attach per route group
- Events (native `Registered`/`PasswordReset`/`Lockout` plus the
  `Ssntpl\Neev\Events` set) — the integration point for
  notifications, audit, analytics, and anything product-specific

### 4. Escape hatches — the code layer stays open

Swappable models (`user_model`, `team_model`, `tenant_model`),
publishable routes and views, and public model APIs. A developer who
needs to bypass a flow can — in explicit, reviewable application code,
never via a config flag.

---

## Escape hatches: named and safe

An escape hatch is an explicit line of code a reviewer can see and
question. A config boolean is a silent global weakening every flow
inherits. That distinction is why bypasses live at the code layer.

This is Laravel's own pattern — `forceCreate()` bypasses fillable,
`Auth::login($user)` bypasses credential checks. The dangerous
primitives exist, but you must reach for them by name.

**Corollary: don't pretend the escape hatch doesn't exist — make it
keep the invariants.** When bypassing is legitimate (admin
provisioning, data imports, tests), provide a named method that skips
only the check the caller takes responsibility for, while preserving
everything else. Example: `MultiFactorAuth::activate()` skips the OTP
proof but still assigns the preferred flag and fires `MfaMethodAdded`.
Raw attribute manipulation would silently break both.

---

## The guardrail questions

Ask these before adding anything:

**Before adding a config key:** is this a *value* two real consuming
applications (e.g. TAILLOG, otper) demonstrably need to differ on — or
a *behaviour branch*? Values (numbers, lists, names) are cheap.
Booleans that change control flow are expensive: each one doubles the
test matrix and must be understood by every reader of the config.
Behaviour branches should become events, middleware, or overridable
methods instead. "Someone might need it" is not a requirement.

**Before leaving something to the developer:** does the golden path
still work with zero application code? Enforcement *placement* may be
the app's job (attaching `neev:verified-email` is one documented
line). Enforcement *implementation* may not — shipping a config key
without the middleware that honours it is a broken promise.

**When in doubt, the litmus test:** *would Fortify or Sanctum make the
developer write this?* Notifications — yes; leave them to the app,
triggered by neev's events. TOTP verification before an authenticator
becomes enforceable — no; the package owns it.

---

## Applied decisions

Worked examples of the layers in action, for calibration:

| Decision | Layer | Rationale |
|---|---|---|
| MFA pending → active requires OTP proof in shipped flows; no config toggle | 1 | A toggle would be a footgun; the flow is what neev is responsible for |
| `MultiFactorAuth::activate()` exists for programmatic activation | 4 | Legitimate bypass, explicit in app code, keeps event + preferred invariants |
| `login_throttle` delays are configurable numbers | 2 | Apps differ on tolerance; it's a value, not a branch |
| Email verification enforcement is a middleware alias, not automatic | 3 | Which routes require it is product policy; the mechanism is fully shipped |
| Push notifications rejected from the package (PR #25) | — | Fails the litmus test; events give the app everything it needs |
| Old `identity_strategy`/`tenant_isolation`/`tenant_auth` flags removed in favour of `tenant` + `team` | 2 | Interacting behaviour-branch booleans; the invalid combinations proved it |
| SPA cookie mode: stateful-origin allowlist configurable, CSRF signing not | 1 + 2 | Which origins is a value; whether CSRF tokens are signed is an invariant |
