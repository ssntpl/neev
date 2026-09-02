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

**Middleware aliases renamed (action required if you use them).**
The opt-in alias middleware now use a hyphen instead of a colon:

| Old | New |
|-----|-----|
| `neev:verified-email` | `neev-verified-email` |
| `neev:password-not-expired` | `neev-password-not-expired` |
| `neev:active-team` | `neev-active-team` |
| `neev:active-tenant` | `neev-active-tenant` |
| `neev:tenant-member` | `neev-tenant-member` |
| `neev:resolve-team` | `neev-resolve-team` |
| `neev:ensure-sso` | `neev-ensure-sso` |

A colon is Laravel's separator between a middleware name and its
parameters, so `neev:verified-email` resolved as the `neev` middleware
taking a `verified-email` argument rather than as its own alias. Search
your routes for the colon form and rename. The middleware **groups**
(`neev:web`, `neev:api`, `neev:login`, `neev:tenant`) are unchanged —
group names are looked up separately and take no parameters. Earlier
sections of this file still show the colon form; they described the
release they belong to.

**Emailed links now work without a session (action required for
headless frontends).**
`GET {prefix}/email/verify` moved out of the authenticated route group,
and `{prefix}/email/change/verify` now answers `GET` as well as `POST`.
The signature is the credential, so:

- Stop sending `Authorization: Bearer …` to these endpoints — you may,
  but it is no longer needed and no longer influences the result.
- A verification link acts on the account it was minted for, not on
  whoever is signed in. If your frontend relied on the old behaviour of
  refusing a link that did not match the current session, that check is
  gone.
- These endpoints answer JSON only when the request asks for it
  (`Accept: application/json`). A plain browser GET gets a redirect —
  to `neev.home` on success, or to `login` with an error bag for a
  failed email change.

**Where emailed links point is now `EmailLinks` (action required only
if you patched it).**
Link building moved out of the controllers into
`Ssntpl\Neev\Services\EmailLinks`. If you were overriding controllers
or filtering mail to rewrite URLs, replace that with a subclass:

```php
// app/Providers/AppServiceProvider.php — register()
$this->app->bind(
    \Ssntpl\Neev\Services\EmailLinks::class,
    \App\Services\AppEmailLinks::class,
);
```

One default changed: the headless **verification** link used to point at
`{app.url}/verify-email?…` and now points straight at the API route that
performs the verification, because that flow finishes on the click and
needs no page of yours. If you want it back on your page, override
`verificationUrl()` — see [docs/email-links.md](./docs/email-links.md).
Password-reset, magic-link, and invitation links still land on your
frontend as before.

**Verification is now inferred from other proofs (behaviour change, no
action required).**
An unverified address is marked verified when the user signs in through
OAuth, follows a magic link, or registers through a team invitation —
each demonstrates control of the inbox. Previously these paths refused
the user instead. The OAuth case carries a trade-off worth reading:
[docs/security.md](./docs/security.md#oauth-and-email-verification).

Relatedly, an **unverified address can now request and use a password
reset link**. If your app depended on reset being unavailable to
unverified users, add that check in your own layer.

**Email OTP now requires a verified address (behaviour change).**
`POST {prefix}/mfa/add` with `auth_method: email` answers `422
Email is not verified.` for an unverified account instead of enrolling
the factor. Note also that **every** failure from this endpoint is now
`422` rather than a `200` carrying an error message — including
`Email already Configured.`, which used to return `200`. Clients that
branched on the message inside a `200` need updating.

**Team actions are authorised by membership (behaviour change).**
Team update, domain listing, join-request handling, member removal, the
Blade team pages, and role changes now require the caller to be a joined
member and answer `403 You cannot perform this action on this team.`
Two things follow:

- A team that does not exist is refused the same way as one that is not
  yours. Endpoints that used to answer `400 Team not found` now answer
  `403`, deliberately — they no longer confirm which team ids exist.
- Owning a team and belonging to it are separate records. Every path
  that creates a team attaches the creator as a member, but if you
  created teams directly in your own code, backfill the pivot:

  ```php
  Team::whereDoesntHave('allUsers', fn ($q) => $q->whereColumn('users.id', 'teams.user_id'))
      ->each(fn ($team) => $team->addMember($team->owner));
  ```

**`VerifyUserEmail` constructor changed (action required if you send it
yourself).**
`$expiry` split into `$link_expiry` and `$otp_expiry`, because the link
and the code expire on different clocks. The new signature is
`(url, username, purpose, link_expiry, otp_expiry, otp)`. If you ejected
the `email-verify` template before this release, `{{ $expiry }}` no
longer resolves — use `{{ $link_expiry }}` and `{{ $otp_expiry }}`.

**`User` casts moved to a `casts()` method (no action required).**
Declared as a method rather than a `$casts` property, so a subclass
declaring its own `$casts` no longer silently replaces neev's. If you
had been re-declaring neev's casts in your subclass to work around this,
you can drop them.

**Email verification code (additive; one schema note).**
Verification emails now carry a numeric code alongside the signed link,
verifiable via `POST {prefix}/email/verify-otp` or the Blade kit's
verification page. The `otp` table gains an `attempts` column — the
package edits its migration in place, so existing installs add it
themselves: `$table->unsignedTinyInteger('attempts')->default(0);`
Apps that ejected the `email-verify` template before this release
won't show the code until they add the `$otp` block (see the stub
template) — everything else works regardless.

**`AccessToken::mfa_token` removed (action required only if you
reference it).**
`NeevAPIMiddleware` used to confine a token of that type to the MFA
endpoints by matching the request path against the configured route
prefix. Nothing in the package ever minted one — the API MFA step-up is
a short-lived JWT guarded by the `neev:login` group — so the type was
dead weight and the path check a second, weaker copy of a rule the
route groups already enforce. A token is now judged on its hash and its
expiry alone.

Grep your app for `AccessToken::mfa_token` and `AccessTokenFactory::mfa()`
(also removed) and drop the references; the constant no longer exists,
so a reference is a fatal error rather than a silent no-op. Any rows
already stored with `token_type = 'mfa_token'` keep working — the column
is a plain string and the type is no longer consulted — but they are now
accepted on every API route, so delete them if that matters:

```php
AccessToken::where('token_type', 'mfa_token')->delete();
```

**Team name uniqueness scoped to the tenant (schema change).**
`teams` swaps `unique(['name', 'user_id'])` and
`unique(['tenant_id', 'slug'])` for a single
`unique(['tenant_id', 'name', 'user_id'])`. Two tenants can now each
hold an owner's "Acme"; slug uniqueness is unchanged, carried by the
`slug` column's own installation-wide unique index (stricter than the
per-tenant one that was dropped, and what lets slug lookups skip the
tenant filter).

The package edits its migration in place, so existing installs make the
swap themselves:

```php
Schema::table('teams', function (Blueprint $table) {
    $table->dropUnique(['name', 'user_id']);
    $table->dropUnique(['tenant_id', 'slug']);
    $table->unique(['tenant_id', 'name', 'user_id']);
});
```

Deduplicate first if any `(tenant_id, name, user_id)` triple repeats, or
the index will not build.

> **Caveat:** SQL treats `NULL`s as distinct in a unique index, so on
> installs running without tenants (`tenant_id IS NULL` on every row)
> the new index does not fire and one owner *can* hold two teams with
> the same name — the old `(name, user_id)` index blocked that. If that
> matters to you, enforce it in validation or add a partial index for
> `tenant_id IS NULL`.

**Acting on a join request from the Blade form is owner-only (action
required if you relied on members doing it).**
`PUT {prefix}/teams/members/request/action` now refuses anyone but the
team owner. Accepting a request admits someone to the team and can hand
them a role, which is what inviting does, and inviting is owner-only.
The API counterpart (`PUT {prefix}/teams/request`) is unchanged and
still allows any member.

**Login `redirect` is validated (action required only if you passed
absolute URLs).**
The Blade login form's `redirect` now has to be a same-site path.
Absolute (`https://…`), protocol-relative (`//host`), `/\host`, bare
`/`, and non-string values fall back to `config('neev.home')`. If you
were passing an absolute URL to send users to another host after login,
that no longer works — it was an open redirect. See
[docs/security.md](./docs/security.md#open-redirect-protection).

**Passkey and SSO columns narrowed to `string` (no action required in
most cases).**
`passkeys.public_key`, `passkeys.aaguid`, `passkeys.transports` and
`sso_client_id` on both auth-settings tables moved from `text` to
`string`, which caps them at 255 characters. Existing rows are
untouched. If your provider issues a client id — or your authenticators
a public key — longer than 255 characters, keep those columns as `text`
in your own migration.

**Remember-me removed (action required only if you relied on it).**
The login page's checkbox, the `remember` flag `LoginRequest` passed to
`Auth::login()`, and `remember_token` in `User::$hidden` are gone. A
remembered session outlives the session lifetime, MFA and
password-expiry policies neev exists to enforce, and nothing in the
package ever issued or cleared the cookie beyond passing that flag. The
`remember_token` column is untouched, so if you want the behaviour back,
call `Auth::login($user, true)` from your own login path. A submitted
`remember` field is now simply ignored.

**Import the password rule from neev (action required if you published
`config/neev.php`).**
`php artisan config:cache` fails on a published config that imports
`Illuminate\Validation\Rules\Password` — the cache is written with
`var_export()` and read back with `require`, and an object without
`__set_state()` makes that file fatal on load. This is what Laravel
reports as a non-serializable config value. Change the import at the top
of your `config/neev.php`:

```php
-use Illuminate\Validation\Rules\Password;
+use Ssntpl\Neev\Rules\Password;
```

`Ssntpl\Neev\Rules\Password` extends Illuminate's rule and adds only
that method, so `Password::min(8)->symbols()` reads and behaves exactly
as before. If you would rather not edit the file, run the rule list
through `Ssntpl\Neev\Rules\Password::upgrade()` where you build it.
`PasswordHistory` and `PasswordUserData` gained the same method in the
package, so they need no change.

**Team routes are registered only when `team => true` (action required
if you run without teams and linked to them).**
The web `/teams/*` group, the `/account/teams` page, and the API
`teams`, `domains` and `changeTeamOwner` blocks used to register
regardless of the `team` config value, exposing endpoints whose
controllers assume a team context. With `'team' => false` those paths
now answer `404` and `route('teams.create')` throws a
`RouteNotFoundException` — wrap any link to them in
`@if (config('neev.team'))`. Installs with teams on are unaffected.

**`GET {prefix}/tenant-domains/current` response shape (action required
only if you read `team`).**
The endpoint returned the resolved context under a `team` key whatever
that context actually was, and resolution from the request host or the
`X-Tenant` header always yields a `Tenant`. It now reports:

```json
{ "data": { "type": "tenant", "context": {…}, "domain": {…}, "team": null } }
```

`team` is kept for older callers but is populated only when `type` is
`team` — that is, only when the application made a Team the context
itself via `TenantResolver::setCurrentTenant()`. Read `context` and
branch on `type`.

**Accounts without a password (behaviour change, no action required).**
Accounts created through OAuth, tenant SSO, a magic link or a passkey
hold `users.password = null`, and `Hash::check()` against a null hash
can never succeed — so those accounts could not be deleted, and their
password and email could not be changed. Now:

- `DELETE {prefix}/deleteUser` and the Blade delete dialog require
  `password` only when the account has one; otherwise the authenticated
  session is the confirmation and the body may be empty. Clients that
  always sent a password are unaffected.
- Change-password answers `403 Your account has no password yet. Use
  the emailed link to set one.`, and it now answers `404 User not
  found.` where it previously dereferenced a missing user.
- Changing the address still costs a password — it is what owns the
  account — and answers `403 Set a password on your account before
  changing your email address.` until one is set.
- The link to set one is the new `POST /account/password/reset-link`
  (Blade kit, 5 requests per minute). Headless frontends build the same
  link with `EmailLinks::passwordResetUrl()`. If you render your own
  security page, branch on `$user->password` the way the ejected stub
  now does. See
  [docs/authentication.md](./docs/authentication.md#accounts-without-a-password).

**Teams now record their tenant on create (behaviour change).**
In isolated mode a `Team` created while a tenant context is resolved
gets that tenant's id, which it previously did not — `Team` does not use
`BelongsToTenant` (its global scope would break the resolution that
reads Teams), and the assignment that trait provides was missing with
it. An explicit `tenant_id` still wins, and in shared mode nothing is
assigned. Teams created before this release keep `tenant_id = NULL`;
backfill them if your queries depend on it.

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
