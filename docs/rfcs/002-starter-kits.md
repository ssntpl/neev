# RFC 002 — Headless Core + Starter Kits

> **Status:** Proposed (design agreed with maintainer 2026-07-02; Phase A pending implementation)
> **Drivers:** neev as a Fortify-class drop-in for fresh Laravel installs; TAILLOG/otper React rebuilds
> **Depends on:** SPA cookie mode (`docs/spa-cookie-mode.md`), configurable `route_prefix`

## 1. Problem

Neev currently ships 63 Blade page templates that are auto-loaded from
the package (`view('neev::...')`) with publish-to-override. This is the
worst of both worlds:

- Consumers who **don't** publish get UI that silently changes under
  them on every package update.
- Consumers who **do** publish own a fork anyway, but with the ceremony
  of the `vendor/neev` namespace and no clear signal that upstream
  updates no longer apply.
- Headless consumers (API-only, or React SPAs) carry dead-weight Blade
  routes and views they never use.

The Laravel ecosystem settled this pattern years ago: **Fortify** is a
headless backend that ships zero views; **Jetstream/Breeze and the
Laravel 12 starter kits** are scaffolding that is copied into the app
once and becomes app-owned code. Nobody "updates" starter-kit UI from
upstream — that is the point.

## 2. Goals

- **The package works completely headless.** Install, migrate,
  configure — every API flow (and every web *flow*, given app-supplied
  views) functions with no kit installed. A consumer building their own
  frontend needs nothing from the kits.
- **UI ships as starter kits** the installer copies into the app. From
  that moment the files are the app's: committed to its repo, edited
  freely, never touched by package updates.
- Kit selection at install time: `blade` or `none` now; `react` later
  (§8). The architecture must accommodate more kits without redesign.
- **Email templates become app-owned on install too** (maintainer
  decision): the installer copies them into the app so they are
  immediately editable, while the package retains shipped fallbacks so
  headless installs still send mail out of the box.

## 3. Non-goals

- **The React kit itself.** Future feature (§8); this RFC only reserves
  its architectural slot.
- Moving web *flow logic* out of the package. Controllers, middleware,
  session/MFA handling stay in neev (§5.1).
- A UI-theming system. The kit is plain files; theming = editing them.

## 4. Background — what exists today

| Asset | Today | After this RFC |
|---|---|---|
| Page views (`auth/`, `account/`, `team/`, `components/`, `layouts/`, `navigation-menu`, `welcome`) | Auto-loaded from package; publishable | Live only in `stubs/blade/`; copied by installer; not loaded from the package |
| Email views (`emails/`, 5 files) | Auto-loaded from package; publishable | Copied to the app by the installer (app-owned); package copy remains as fallback |
| Web controllers (`UserAuthController`, `UserController`, `TeamController`, …) | Package | Package (unchanged) |
| Web UI routes (`/login`, `/account/...`, `routes/neev.php` web section) | Always loaded | Loaded only when `ui = 'blade'` |
| API routes, OAuth/SSO redirect+callback | Always loaded (under `route_prefix`) | Unchanged — always loaded |

## 5. Design

### 5.1 The package stays the flow layer

Web controllers, middleware, and session/MFA logic remain in the
package — they are auth flow, not presentation, and must stay
composer-updatable (this mirrors Jetstream sitting on Fortify's
package-owned actions). The app can still take over any of it,
explicitly:

- **Route-level:** publish `routes/neev.php` (`neev-routes` tag) and
  point any route at an app controller. The published copy takes
  precedence over the package's.
- **Class-level:** extend a package controller and rebind in the route
  file, or swap models via `user_model`/`team_model`/`tenant_model`.
- **Event-level:** listen to the events system for side effects
  without touching flows.

Per `docs/design-principles.md`, these are layer-4 escape hatches —
explicit app code, never config flags.

### 5.2 The `ui` config value

```php
// config/neev.php
'ui' => env('NEEV_UI'), // 'blade' | null
```

- `'blade'` — the web UI route section of `routes/neev.php` loads and
  the controllers render app-owned views. Set by the installer when
  the Blade kit is chosen.
- `null` (default) — headless: no Blade page routes are registered.
  API routes, OAuth/SSO endpoints, and email sending are unaffected.

This is an install-mode value with direct precedent (Fortify's
`'views'` toggle), not a runtime behaviour branch; recorded as such in
the design-principles applied-decisions table.

Future kits extend the accepted values (`'react'` would keep Blade
page routes off and drive frontend scaffolding instead).

### 5.3 Stub layout (in-repo)

```
stubs/
├── blade/
│   └── views/            # today's resources/views, minus emails/
│       ├── auth/ …
│       ├── account/ …
│       ├── team/ …
│       ├── components/ …
│       └── layouts/ …
└── mail/
    └── views/emails/     # the 5 email templates
```

Kits live in this repository (versioned with the package, one PR
surface). If a future kit grows heavy (React with a build chain), it
can graduate to its own repo without affecting this design.

### 5.4 View resolution

The `neev::` view namespace remains, registered with **two paths in
priority order**:

1. `resource_path('views/vendor/neev')` — the app-owned copy (where
   the installer ejects kits and mail templates; also today's publish
   target, so already-published consumers keep working unchanged).
2. The package's internal path — which after Phase A contains **only
   the email fallbacks**.

Consequences:

- Page views resolve **only** from the app. `ui = 'blade'` without the
  kit copied is a clear "view not found" error naming the file — not a
  silently different page.
- Email views resolve from the app when ejected, package fallback
  otherwise. Headless installs send mail with zero setup.

*(Considered and rejected: ejecting to bare `resources/views/neev/`
with non-namespaced references. It reads more "app-owned", but breaks
every published-views consumer, collides with app view names, and
loses the two-path fallback that keeps headless mail working.)*

### 5.5 Email templates — app-owned with a variable contract

The installer **always** copies `stubs/mail/views/emails/` into the
app (regardless of kit choice), so email templates are the app's from
day one. The package pre-fills all dynamic values; templates just
place them. The contract per template:

| Template (`neev::emails.*`) | Mailable | Variables |
|---|---|---|
| `email-verify` | `VerifyUserEmail` | `$url` (signed verification link), `$username`, `$purpose`, `$expiry` (minutes) |
| `email-otp` | `EmailOTP` | `$username`, `$otp`, `$expiry` |
| `login-link` | `LoginUsingLink` | `$url` (signed magic link), `$expiry` |
| `team-invitation` | `TeamInvitation` | `$team`, `$username`, `$url` (accept link), `$expiry`, `$userExist` |
| `team-join-request` | `TeamJoinRequest` | `$team`, `$username`, `$owner`, `$teamId` |

This table becomes part of the published docs — it is the "placeholder"
API the app writes against. Adding a variable is additive; removing or
renaming one is BREAKING and goes through CHANGELOG/UPGRADING.

Mailables (subjects, when mail is sent) stay in the package: sending
is auth flow. An app needing full control listens to the events system
and sends its own mail instead.

### 5.6 Install flow

```
php artisan neev:install
  → Multi-tenant isolation? (yes/no)          [existing]
  → Team support? (yes/no)                    [existing]
  → Frontend starter kit? (blade/none)        [new]
```

- `blade`: copy `stubs/blade/views/` → `resources/views/vendor/neev/`,
  set `'ui' => 'blade'` in the published config.
- `none`: leave `ui` null; no page views copied.
- Always: copy `stubs/mail/views/emails/` →
  `resources/views/vendor/neev/emails/`.
- Non-interactive form: `php artisan neev:install yes no --kit=blade`.
- Re-runnable for kit ejection on an existing install (e.g.
  `neev:install --kit=blade` alone), refusing to overwrite existing
  files without `--force`.

### 5.7 Routes under the prefix (recorded decision)

All machine-facing routes (API, OAuth redirect/callback, SSO,
`/csrf-cookie`) bind to `route_prefix` — see PR #44. Blade UI pages
stay at root; they are end-user URLs, and apps that want them moved
publish the routes file. Published routes remain the sanctioned way to
re-mount anything (different prefix, domain routing, replacing
controllers) — the package places no constraint on how the app loads
them.

## 6. Breaking changes & migration

This ships in a **major breaking release** (expected and accepted):

- The package no longer auto-loads page views. Consumers upgrading:
  - already published views → unaffected (same path wins the
    namespace).
  - relied on package views → run `neev:install --kit=blade` (or
    publish once) and set `ui = 'blade'`.
  - headless/API-only → nothing to do; page routes simply disappear
    (they were dead weight).
- New config keys: `ui`. Republished config recommended.
- Email templates: existing installs keep working via the package
  fallback; running the installer copies them into the app.

## 7. Phasing

| Phase | Scope |
|---|---|
| **A** | Move page views to `stubs/blade/`; `ui` config + conditional web-route loading; installer kit prompt + mail-template ejection; view-namespace re-registration; UPGRADING/docs |
| **B** | React starter kit (future — extracted from the TAILLOG SPA work, riding on SPA cookie mode: `/csrf-cookie`, `withCredentials`, `auth_state` branching, MFA step) |
| **C** | Docs polish: starter-kit section in README ("headless like Fortify, batteries available like Jetstream"), kit-authoring notes |

## 8. Open questions

1. **Blade kit granularity.** Eject everything (auth + account + team
   + components) as one kit, or split (auth-only vs full account/team
   UI)? Proposal: one kit for v1; splitting is additive later.
2. **Welcome/navigation views.** `welcome.blade.php` and
   `navigation-menu.blade.php` are app-shell, not auth UI — include in
   the Blade kit or drop? Proposal: include; deleting is the app's
   one-keystroke decision.
3. **`neev:install` on existing apps.** The fresh-install guard
   (non-empty `users` table aborts) conflicts with re-running for kit
   ejection — likely a separate `neev:ui` command or a flag that skips
   the guard for kit-only operations.
