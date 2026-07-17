# Upgrade Guide

This guide summarises the breaking changes in each release and what a
consuming application must do when upgrading. For the full list of
changes see [CHANGELOG.md](./CHANGELOG.md).

> **Versioning note:** neev is on the 0.x line. Per
> [SemVer](https://semver.org/#spec-item-4), 0.x minor releases may
> contain breaking changes; they are always flagged as **BREAKING** in
> the changelog and listed here.

---

## 0.5.0 → Unreleased

**Magic links are now stateful and single-use (action required).**
The stateless signed-URL flow is gone. Links are opaque tokens stored
hashed in the new `magic_link_tokens` table, deleted on redemption, and
superseded whenever a newer link is issued for the same channel.

- **Run `php artisan migrate`** — the new `magic_link_tokens` table is
  required. Any signed magic links already in users' inboxes stop
  working on deploy; users simply request a new one.
- **Links are single-use and expire faster.** A link can be redeemed
  exactly once, and the default expiry drops from 60 to 10 minutes
  (`magic_link.expires_in`, `NEEV_MAGIC_LINK_EXPIRY`). The legacy
  `url_expiry_time` no longer governs magic links — it still applies to
  password-reset and email-verification links.
- **Redemption now takes a `token` parameter**, not signed-URL query
  params. Frontends must forward the `token` from the link to
  `POST /neev/loginUsingLink`. The emailed URL points at
  `{magic_link.channels.web.base_url}{path}?token=...` (default
  `{app.url}/login-link`), so your `/login-link` page reads `token` from
  the query string and posts it.
- **Clients must handle `confirmation_required`.** Because links are
  single-use, a GET must never consume one: mail-scanning gateways
  (Outlook SafeLinks, Mimecast) prefetch links and would burn them
  before the user clicks. `GET /neev/loginUsingLink` therefore returns
  `{"auth_state": "confirmation_required"}` — render a "confirm sign-in"
  button and `POST` the same token back to complete login.
  `GET|POST /neev/loginUsingLink/validate` checks a token without
  consuming it. To restore one-click GET redemption, set
  `NEEV_MAGIC_LINK_CONFIRMATION=false` — only do this if you are certain
  your users are not behind a scanning mail gateway.
- **Blade users:** the legacy `GET /login/{id}` route (`login.link`) is
  removed; redemption is `GET|POST /login-link/verify`
  (`login.link.verify`). Re-run `php artisan neev:ui blade` or apply the
  new `auth/confirm-login-link.blade.php` view if you ejected the kit.
  Apps that published `routes/neev.php` must re-apply the route change.
- **Unverified users can no longer request a link by default.**
  `POST /neev/sendLoginLink` returns `403` ("Please verify your email
  address before using a login link.") for an unverified address, and
  `MagicLinkManager::generate()` throws `MagicLinkUnverifiedException`.
  Previously a link was mailed and every redemption of it failed with
  "Invalid or expired" — a dead end. Set
  `NEEV_MAGIC_LINK_ALLOW_UNVERIFIED=true` to let unverified users use
  magic links; redeeming one then **marks the email verified** and fires
  `EmailVerified`, since following the link proves inbox control.
- **Malformed input is now a rejection, not a 500.**
  `POST /neev/sendLoginLink` without an `email` returns `422` (it raised
  a `TypeError` before), and a non-scalar `token` on the redemption and
  validate endpoints is treated as an invalid token rather than raising
  "Array to string conversion".
- **Browser binding** (`magic_link.bind_to_browser`, default off) binds
  a link to the device that requested it. When enabled, generation
  throws `MagicLinkBindingException` if the request has no binding
  source (`X-Device-Id` header, a `binding` field, or a session) —
  rather than minting a link that could never be redeemed. Session-less
  API clients must send `X-Device-Id` before enabling it.
- Both refusals above happen **before** the previous link is
  invalidated, so a rejected send never costs the user the working link
  already in their inbox.
- Redemption routes are now rate-limited (`throttle:10,1`).
- Schedule `neev:clean-magic-links` alongside `neev:clean-login-attempts`
  to purge expired tokens.

---

## 0.4.5 → 0.5.0

**The package is now headless by default (RFC 002, action required for
Blade UI users).**
Page views are no longer auto-loaded from the package, and the Blade
page routes (`/login`, `/account/...`) only register when
`config('neev.ui') === 'blade'`.

- **Using the shipped Blade UI?** Run `php artisan neev:ui blade` —
  it ejects the views to `resources/views/vendor/neev` (app-owned from
  then on) and sets `'ui' => 'blade'`. If you had already published
  the views, your files are untouched (same path); just set the `ui`
  config.
- **Headless / SPA / API-only?** Nothing to do — the Blade page routes
  disappear (they were dead weight), and verification/invitation email
  links now point at your frontend (`{app.url}/verify-email?...`,
  `{app.url}/register?invitation_id=...&hash=...`) carrying the
  signed query for the API endpoints.
- Email templates: the installer copies them to
  `resources/views/vendor/neev/emails` so they're yours to edit; the
  package retains fallbacks. The available variables per template are
  documented and stable (see RFC 002 §5.5).
- The `neev-views` publish tag is replaced by `neev-blade-kit` and
  `neev-mail`.

**OAuth/SSO routes moved under the route prefix (action required for
identity providers).**
All machine-facing routes now live under the configurable
`route_prefix` (default `neev`):

| Old path | New path |
|---|---|
| `/oauth/{service}` and `/oauth/{service}/callback` (web) | `/neev/oauth/{service}[/callback]` |
| `/sso/redirect`, `/sso/callback` | `/neev/sso/redirect`, `/neev/sso/callback` |
| `/api/tenant/auth` | `/neev/tenant/auth` |

- **Update the redirect URIs registered at your identity providers**
  (Microsoft Entra, Google, Okta app registrations and OAuth apps) to
  the new callback URLs.
- Apps that published `routes/neev.php` keep their published copy —
  re-publish or re-apply your customisations to pick up the prefix.
- To rename the namespace (e.g. `/auth/...`), set
  `NEEV_ROUTE_PREFIX=auth`. Route *names* (`neev.*` etc.) are
  unchanged either way.

**Authenticator MFA setup now requires verification (behaviour change).**
Adding an authenticator creates it in `pending` status; the user must
submit a valid TOTP (API: `POST /neev/mfa/setup/verify`; web: the
Verify form on the security page) before the method becomes active and
is enforced at login. Consequences for existing installs:

- The `multi_factor_auths` table gains a `status` column. The package
  edits its migration in place; existing installs must add the column
  themselves with `default('active')` so already-configured methods
  keep working: `$table->string('status')->default('active');`
- API clients that add an authenticator must follow up with the
  setup-verify call — until then the method is not enforced and does
  not appear in `mfa_options`.
- `MfaMethodAdded` fires on activation (email OTP: immediately, since
  the account email is already verified).
- Schedule `neev:clean-pending-mfa-setups` to purge abandoned setups
  (`mfa_pending_setup_retention_days`, default 2 days).

**Laravel 11 support dropped.**
`laravel/framework` requirement is now `^12.0`. Laravel 11 is past
security-EOL with permanently-unpatched advisories (Composer ≥2.9
refuses to install it by default). Apps still on Laravel 11 should pin
neev to `<=0.4.5` and plan a framework upgrade.

**Event class renames.**
`Ssntpl\Neev\Events\LoggedInEvent` → `LoggedIn` and
`LoggedOutEvent` → `LoggedOut`. Update listener registrations and
type-hints. Payloads are unchanged (`public $user`).

**New events are additive** — see CHANGELOG for the full list. One
behavioural note: neev now fires `Illuminate\Auth\Events\Registered`.
If your app's User model implements `MustVerifyEmail`, Laravel's
auto-registered `SendEmailVerificationNotification` listener will react
to it — disable that listener or neev's own verification mail to avoid
duplicate emails. (Neev's shipped User model does not implement the
contract, so default installs are unaffected.)

## 0.4.4 → 0.4.5

**Users table consolidation (schema change).**
The `emails` and `passwords` tables were dropped; `email`,
`email_verified_at`, `password`, `password_history` (JSON), and
`password_changed_at` are now columns on `users`. Multi-email-per-user
support was removed.

- The package edits its `create_users_table` migration in place — it
  does **not** ship a data migration for existing installs. Apps
  upgrading with production data must write their own migration to
  copy each user's primary email/password onto `users` and drop the
  two tables.
- The `Email` and `Password` models, the `VerifyEmail` trait, and the
  email management endpoints (add / delete / set-primary email) no
  longer exist. Use `User::findByEmail()`, `User::uniqueEmailRule()`,
  and `hasVerifiedEmail()` / `markEmailAsVerified()` on the user model.
- Remove `neev:clean-passwords` from your scheduler — the command is
  gone (password history is self-trimming).

**Login flow (API contract change).**
- Login responses now return `auth_state` (`authenticated` |
  `mfa_required`). Clients must branch on it instead of assuming a
  token is always present at the top level.
- The MFA step-up between password and OTP verification is a
  short-lived JWT (signed with `NEEV_JWT_SECRET`, falling back to
  `APP_KEY`), verified by `POST /neev/mfa/otp/verify` under the new
  `neev:login` middleware group.

**Email verification / password reset.**
- Always via signed URLs now. OTP-based email verification and
  OTP-based API password reset were removed, along with the
  `email_verification_method` config key.

**Passkeys.**
- New `relying_party_id` and `allowed_origins` config keys. Multi-origin
  deployments (apex + subdomains, staging + production) must list every
  origin in `allowed_origins`.

## 0.4.3 → 0.4.4

- **Email verification is no longer enforced automatically.** The
  hardcoded checks were removed from `NeevMiddleware` /
  `NeevAPIMiddleware`. Attach the `neev:verified-email` alias to the
  routes that require a verified email.
- **API tokens are accepted only via the `Authorization: Bearer`
  header.** The query-string and request-body fallbacks were removed.
- `platform_team_id` and `managed_by_tenant_id` columns were removed
  from `tenants`, along with the related CLI options and relationships.

## 0.4.2 → 0.4.3

- **Renames:** `current_team_id` → `default_team_id` (users table),
  `currentTeam()` → `defaultTeam()`, `switchTeam()` →
  `setDefaultTeam()`, and the endpoint `PUT /neev/teams/switch` →
  `PUT /neev/teams/default`.
- **`TenantScope` is now fail-closed.** With tenant mode enabled and no
  tenant resolved, queries scope to `tenant_id IS NULL` (platform
  users) instead of running unscoped. Platform code that needs to
  operate inside a tenant context outside a request should use
  `TenantResolver::runInContext()`.

## 0.4.0 → 0.4.1

- **`role` column dropped from the `team_user` pivot.** Roles are
  managed exclusively via laravel-acl (`assignRole()` / `getRole()`).
- Dead `$team->default_role` usage removed.

## 0.3.x → 0.4.0 (config overhaul)

The config surface was reduced from ~30 keys to ~20 with two orthogonal
identity flags. Republish the config (`php artisan vendor:publish
--tag=neev-config --force`) and re-apply your customisations.

| Removed key(s) | Replacement |
|---|---|
| `identity_strategy`, `tenant_isolation`, `tenant_isolation_options` | `tenant` boolean |
| `tenant_auth`, `tenant_auth_options` | `tenant_auth_settings` / `team_auth_settings` DB tables (`neev:auth:configure`) |
| `email_verified` | opt-in `neev:verified-email` middleware |
| `domain_federation` | per-domain behaviour on the `domains` table |
| `require_company_email`, free-email list (`EmailDomainValidator` removed) | none yet — planned standalone email-reputation package |
| `magicauth` | magic links always available |
| `login_soft_attempts`, `login_hard_attempts`, `login_block_minutes` | progressive `login_throttle` (`delay_after`, `max_delay_seconds`) |
| `password_soft_expiry_days`, `password_hard_expiry_days` | single `password_expiry_days` + opt-in `neev:password-not-expired` middleware |
| `otp_min`, `otp_max` | `otp_length` |
| `dashboard_url`, `frontend_url` | `home` |
| `geo_ip_db` etc. | `maxmind` array (`db_path`, `edition`, `license_key`) |
| `record_failed_login_attempts` | `log_failed_logins` |
| `last_login_attempts_in_days` | `login_history_retention_days` |

Other breaking changes in 0.4.0:

- **Domain model:** polymorphic `owner` (morphTo) replaces
  `team_id`/`tenant_id`; DNS verification consolidated into
  `$domain->verify()`.
- **Enforcement middleware is opt-in** — nothing is auto-applied.
  Attach `neev:password-not-expired`, `neev:active-tenant`,
  `neev:active-team`, `neev:ensure-sso` where your app needs them.
- `NeevAuthenticatable` umbrella trait added — prefer it over composing
  the individual traits on your User model.
