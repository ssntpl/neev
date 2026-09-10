# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security

- **Domain verification is decided from the host, not from the request** (`POST {prefix}/tenant-domains`) — `type` was a client-supplied field, and `type: subdomain` set `verified_at` immediately with no check that the host had anything to do with this installation. Any team owner could therefore mark any domain verified. That is a takeover, not a cosmetic flaw: a verified claim reserves the domain installation-wide (no other team may then take it, so the rightful owner is locked out) and decides which team `@that-domain` signups are federated into. The decision now comes from the host and the claiming team, against the new `platform_domains` config: a tenant's subdomain is its slug, so team `acme` is issued `acme.otper.com` and that one claim is taken on trust, while everything else — another team's slug, one of your own operational hosts like `app.otper.com`, the apex, any outside domain — publishes the DNS TXT record. `type` is ignored entirely. Hosts are stored in one canonical spelling so the decision, the uniqueness reservation and the resolution lookup cannot disagree about `acme.otper.com.` versus `acme.otper.com`. `slug.reserved` gains the operational names that make this hold, and is where you reserve brand names you would not want in front of your domain. See [docs/configuration.md](./docs/configuration.md#platform-domains)
- **`Domain::isVerifiedForEmail()` asks the database for a verified row** — it read the first row for the address's domain and then tested `verified_at`, so it answered "not federated" whenever an unverified claim by another team happened to sort first. Several teams may hold pending claims on one domain, which is exactly when this mattered

- **The Blade MFA challenge no longer trusts the request body** (`POST {prefix}/otp/mfa`) — the route sits outside the authenticated group, as a login challenge must, and it resolved the account from `email` in the request before calling `AuthService::login()` without `viaRequestAuth`, i.e. a bare `Auth::login()` with no credential check. Anyone holding one second factor for an address could sign in as its owner with no password; because `verifyMFAOTP()` skips the enrolled-factor check for the `recovery` method, a single backup code was a complete standalone credential. The account now comes from `session('email')`, set by the password step, and `auth_method` must name a factor the account has enrolled
- **A rejected MFA code no longer opens the gate** — `verifyMFAOTPStore()` stamped `login_attempts.multi_factor_method` before verifying the code and left it set on failure. `NeevMiddleware` reads exactly that column as proof the challenge was answered, so a password-only attacker submitting one deliberately wrong code reached every protected route. The attempt is stamped after verification succeeds, and its id is taken from the session rather than the request
- **`X-Team` requires membership** — `ResolveTeamMiddleware` accepted the header on every route in the neev groups and made the named team the request context without checking whether the caller belonged to it; `TeamScope` then scoped every team-owned model to it, so any signed-in user could read another team's records by setting one header. No package model uses `BelongsToTeam`, so the exposure fell entirely on consuming applications' own models. Enforced in `BindContextMiddleware`, which runs after the authenticating middleware. A team resolved from the host is unaffected, and a team named by a route parameter is still the controller's to authorize — the team profile page stays open to outsiders so they can ask to join
- **The Blade `leave` action is gated on membership** (`DELETE {prefix}/teams/members/leave`) — it took both the team and the subject from the request and checked neither against the caller, so any signed-in user could remove a member from a team they had nothing to do with, revoke that team's invitations, or deactivate an account outright through the domain-federation branch. `TeamApiController::leave()` was already fixed in 0.6.0; the web twin was missed and now carries the same rules

## [0.6.0] - 2026-09-08

### Added
- **Per-platform OAuth clients** — identity providers issue a separate client per platform (Google rejects a web `client_id` from an Android app, and native clients redirect to a custom scheme), but Socialite reads one `services.<provider>` block. Extra clients now live under a `clients` key inside that block and are selected with a `platform` parameter on `GET {prefix}/oauth/{service}/redirect` and `POST {prefix}/oauth/{service}/callback` — the callback too, since the code was issued to one client and must be exchanged with it. An unconfigured platform returns 404 listing those that are configured, rather than silently using the web client. Omitting `platform` is unchanged, and an installation with *only* platform clients (mobile-only, no top-level block) resolves as well. New `Ssntpl\Neev\Services\OAuthClients`. See [docs/authentication.md](./docs/authentication.md#per-platform-clients-web-android-ios)
- **Per-team SSO is reachable in shared mode** — `team_auth_settings`, `neev:auth:configure --team`, and an owner-agnostic `TenantSSOManager` all existed, but `TenantResolver::resolve()` and `TenantMiddleware` both returned early unless `neev.tenant` was on, so a team install never resolved a context and every SSO request answered "No tenant context". Both now also run when `neev.team` is enabled, resolving the `Team` that owns the request's domain. Data scoping is untouched — `TenantScope` and `TeamTenantScope` still key on `neev.tenant`. See [docs/multi-tenancy.md](./docs/multi-tenancy.md#enterprise-sso)
- **`neev:domain:add` asks for the owner** — running it without `--owner-type` / `--owner-id` now prompts for both, the way it already prompted for the domain. Non-interactive runs still require the options. `--owner-id` also accepts a slug, which it always resolved but then wrote verbatim into the integer `owner_id` column
- **Verifying an MFA setup also enrols email OTP** — proving one factor now adds email as a second one, so losing the authenticator app does not lock the account out. Only on successful verification, and skipped when email OTP is already configured, `email` is not in `neev.multi_factor_auth`, or the address is unverified. The verified factor keeps the `preferred` flag. See [docs/mfa.md](./docs/mfa.md#enabled-automatically-alongside-another-factor)
- **`EmailLinks` service** — every link the package emails, and every response it gives when one is followed, now comes from one overridable class (`Ssntpl\Neev\Services\EmailLinks`) instead of being built inline in six controllers. Subclass it and bind it in a service provider to point links at your own pages or change what a followed link answers. Headless installs previously got frontend paths hardcoded to `config('app.url')`; that is now `EmailLinks::base()`. See [docs/email-links.md](./docs/email-links.md)
- **Laravel 13 support** — the framework constraint widens to `^12.0|^13.0` and the CI matrix now runs PHP 8.3/8.4 against both lines. No runtime behaviour changed: every dependency already spanned 13 (`laravel/socialite`, `ssntpl/laravel-acl`, `web-auth/webauthn-lib`, `spomky-labs/otphp`, `geoip2/geoip2`), so the only source edit is a static-analysis annotation in `TeamScope`, matching the idiom `TenantScope` already used. `orchestra/testbench` gains `^11.0` for the Laravel 13 line. Apps on Laravel 12 are unaffected
- **Email verification code alongside the link** — the verification email now carries both a signed link and a numeric code (`$otp` added to the app-owned `email-verify` template's variable contract). The code lets the *waiting* session complete verification in place — cross-device signups, TVs, and environments where security scanners consume single-use links. New endpoints: `POST {prefix}/email/verify-otp` (API) and the Verify form on the Blade kit's verification page. Codes are stored hashed, expire after `otp_expiry_time`, die after 5 wrong attempts, and either proof invalidates the other. Which proofs to show is the app's choice — via its owned email template and UI, not a config toggle. The API resend endpoint now delegates to `AuthService::sendEmailVerification()` (deduplicated)
- **`GET {prefix}/teams/slug/{slug}`** — looks a team up by its readable handle instead of its id, for clients that route on `/t/acme-labs` and never see the id. Same response and same membership gate as `GET {prefix}/teams/{id}`, including answering `400 Team not found` for a team the caller is not in. Slugs are unique installation-wide, so no tenant needs naming
- **Join requests can name the team by `slug`** — `POST {prefix}/teams/request` (API) and `POST {prefix}/teams/members/request` (Blade) accept `slug` alongside `team_id`; `team_id` wins if both are sent. The Blade route also still honours the older owner-`email` plus `team`-name pair. The Blade team profile page — the one team page an outsider can open — now carries the **Request to join** button, and shows **Request pending** once a request is in
- **`Membership::REQUEST_TO_USER` / `Membership::REQUEST_FROM_USER`** — the two directions a pending membership can face were bare strings repeated across the models, traits and controllers, where a typo would surface only as a relation that silently returned nothing
- **`Team::addMember()` takes `$joined` and `$action`** — it could only write a joined membership, so the invitation and join-request flows hand-rolled their own `attach()` and skipped the `MemberAdded` event. `addMember($user, joined: false)` records a pending invitation, and `action: Membership::REQUEST_FROM_USER` a pending request. Note that the event fires and any `$role` is granted for pending memberships too, so listeners and permissions take effect before the user has joined
- **`POST /account/password/reset-link`** (Blade kit, throttled to 5/minute) — mails the ordinary signed reset link to the address on the signed-in account, so a user can set a *first* password or replace one they cannot recall without signing out. `updatePasswordCreate` no longer bounces a signed-in user off their own link, and a reset completed while signed in returns to `/account/security`. See [docs/authentication.md](./docs/authentication.md#accounts-without-a-password)
- **`Ssntpl\Neev\Rules\Password`** — Laravel's `Illuminate\Validation\Rules\Password` plus the `__set_state()` that `php artisan config:cache` needs. `config/neev.php` holds rule objects, and the config cache is written with `var_export()` and read back with `require`, so an object without `__set_state()` made the cached file fatal on load — the failure Laravel reports as a non-serializable config value. The fluent builder is unchanged (`Password::min(8)->symbols()` reads and behaves as before, and returns this class). `Password::upgrade($rules)` converts rules from a `config/neev.php` published before this class existed. The new `Ssntpl\Neev\Support\ExportsState` trait supplies the method and is also used by `PasswordHistory` and `PasswordUserData`
- **`EmailLinks::loginUrl()` and `EmailLinks::verifyEmailUrl()`** — every redirect that used to call `route('login')` or `route('verification.notice')` now goes through these, so a headless install that registers neither route no longer hits a `RouteNotFoundException` on an expired session, a failed SSO callback or an unverified address. They resolve to the Blade routes when the Blade kit is installed, and to `{base}/login` and `{base}/verify-email` otherwise

### Changed
- **Login sessions slide instead of expiring mid-use** — `login_token_expiry_minutes` was an absolute deadline set at login, so a user working continuously in an SPA was signed out 24 hours in, and the auth cookie was dropped by the browser at the same moment. It is now an **idle** window: an authenticated request past the half-way point of the window pushes the deadline forward (on the write that already records `last_used_at`, so no extra query, and only on a minority of requests), and in SPA cookie mode the response re-issues the auth cookie with the same deadline so the browser's copy tracks the token's. The new `login_token_max_lifetime_minutes` (`NEEV_LOGIN_TOKEN_MAX_LIFETIME_MINUTES`, default 43200 — 30 days, `0` disables) caps how far a session can be slid from the moment it was issued, so a stolen token cannot be kept alive indefinitely by using it. Only login tokens slide; API tokens are deliberate long-lived credentials and keep the expiry they were issued with, as do tokens issued without one. Existing installs keep their configured number — it now means that much *inactivity* rather than that long from login. No refresh endpoint and no token rotation: the package's login token is an opaque, revocable, DB-backed reference token, so the renewal rides on the requests the client is already making. Note that `expires_in` in the login response is now a floor rather than the session's real deadline once it has been used. See [docs/spa-authentication.md](./docs/spa-authentication.md#48-how-long-a-session-lasts)
- **An expired token's 401 carries `"code": "token_expired"`** — the response was indistinguishable from the one for a malformed or revoked token, so a client could not tell "your session ended, sign in again" from "something is wrong". The `message` is unchanged
- **Middleware aliases renamed from `neev:*` to `neev-*`** (breaking) — `neev-verified-email`, `neev-password-not-expired`, `neev-active-team`, `neev-active-tenant`, `neev-tenant-member`, `neev-resolve-team`, `neev-ensure-sso`. A colon is Laravel's separator between a middleware name and its parameters, so `neev:verified-email` resolved as the `neev` middleware taking a `verified-email` argument rather than as its own alias. The middleware *groups* (`neev:web`, `neev:api`, `neev:login`, `neev:tenant`) are unchanged — group names are resolved separately and take no parameters
- **Emailed links no longer require a session** — `GET {prefix}/email/verify` moved out of the authenticated route group, and the check tying the link to the currently-signed-in user was dropped. A mail client opens a link in whichever browser it likes, rarely the one holding the session, so requiring a token there broke most real clicks. The signature is the credential; the link acts on the account it was minted for. The same applies to the Blade kit's `/email/verify/{id}/{hash}`, which no longer redirects to login first
- **`{prefix}/email/change/verify` now answers `GET` as well as `POST`** so a clicked link reaches it directly. `POST` is retained for SPAs that forward the signed query from their own page
- **An unverified address can request and use a password reset link** — a forgotten password is exactly the case where the user may never have finished verifying, so requiring verification first stranded them. `resetPassword` and the Blade update-password flow dropped the same check
- **Following a magic link verifies an unverified address** — the link was mailed to that address and came back signed, which proves inbox control just as the verification mail would. Applies to both the web and API flows
- **The Blade login page offers the passwordless methods to unverified accounts** — the password page hid the OAuth buttons, "Login Via Link" and the passkey button behind `$email_verified`, so an account that never finished verifying was left with only the password it may not have, while the underlying routes accepted it perfectly well. Each of those methods proves control of the address on its own, and following one verifies it. The API surface never had the gate. Password login still stops at `verification.notice` — a password says nothing about the inbox
- **The OAuth callback adopts an unverified address instead of refusing it** — the provider authenticated the address, which is the same claim our verification mail makes. Previously the callback returned `401` (API) or redirected to login (web), leaving the user with no way forward. Note the trade-off documented in [docs/security.md](./docs/security.md#oauth-and-email-verification)
- **Team and role actions are authorised by membership** — `updateTeam`, `getDomains`, `requestAction`, `leave`, the Blade team pages (`members`, `settings`, `domain`), and `roleChange` now require the caller to be a joined member of the team, answering `403 You cannot perform this action on this team.` A missing team is refused the same way as an inaccessible one, so the endpoints no longer confirm which team ids exist. `roleChange` additionally requires the *target* user to be a member — a team-scoped role is meaningless for an outsider — and team invitations can only be accepted or rejected by the account holding the invited address
- **Adding an MFA method reports failures as `422`** instead of a `200` carrying an error message. Affects `Email is not verified.` (new) and `Email already Configured.` (previously `200`). A `400` still means the method name is not one neev supports
- **`VerifyUserEmail` splits `$expiry` into `$link_expiry` and `$otp_expiry`** — the link and the code expire on different clocks (`url_expiry_time` vs `otp_expiry_time`), and the template said one number for both. The `email-verify` template now renders each next to the proof it applies to
- **`AccessToken::mfa_token` removed** (breaking) — `NeevAPIMiddleware` confined a token of that type to the MFA endpoints by matching the request path against the configured route prefix. Nothing ever minted one: the API MFA step-up is a short-lived JWT guarded by the `neev:login` group, so the type was dead weight and the path matching a second, weaker copy of a rule the route groups already enforce. A token now stands or falls on its hash and its expiry alone. `AccessTokenFactory::mfa()` went with it. Applications referencing `AccessToken::mfa_token` must drop the reference — see [UPGRADING.md](./UPGRADING.md)
- **API routes regrouped under `Route::prefix()`** — the `email`, `mfa`, `passkeys`, `apiTokens`, `teams` and `domains` blocks were flat lists of full paths. Every URI is unchanged; a new `tests/Unit/ApiRouteMapTest.php` pins the map so a stray slash inside a group cannot silently move an endpoint
- **`*_auth_settings.sso_client_id` is `string` rather than `text`** — indexable, and OAuth client ids are well under 255 characters. The passkey columns keep `text`: a base64url-encoded RS256 COSE public key is 376 characters, so a `string` column would truncate every TPM-backed credential (Windows Hello). SQLite does not enforce `VARCHAR` length, so the test suite cannot catch that — only MySQL and PostgreSQL would, in production
- **`User` declares casts via `casts()` rather than a `$casts` property** — Eloquent merges `casts()` into `$casts`, so an application's `User` subclass declaring its own `$casts` no longer silently replaces neev's
- **Team routes register only when `neev.team` is on** — the web `/teams/*` group, the `/account/teams` page, and the API `teams`, `domains` and `changeTeamOwner` blocks were registered unconditionally, so an install running without teams exposed endpoints whose controllers assume a team context. With `'team' => false` the paths now answer `404` and `route('teams.create')` throws — wrap links to them in `@if (config('neev.team'))`. See [docs/configuration.md](./docs/configuration.md)
- **`GET {prefix}/tenant-domains/current` reports the context `type` and `context`** — it returned the resolved context under a `team` key whatever it actually was, and resolution from a host or the `X-Tenant` header always yields a `Tenant`. `team` is kept, populated only when the context really is a Team and `null` otherwise; new code should read `context` and branch on `type`
- **Tenant SSO routes run under `TenantMiddleware`** — the callback looked for a tenant that nothing had resolved yet
- **`neev:install` warns and continues when the database is unreachable** instead of failing. The command is documented to run *before* `migrate`, nothing it does after the check touches the database, and the migration that drops the `users` table repeats the same "must be empty" check where it actually matters
- **`TenantSSOManager::findOrCreateUser()` reloads the user after creating it**, so `Registered` listeners see the database-side column defaults rather than the unsaved attributes

### Fixed
- **Teams were not scoped to the resolved tenant** — `Team` carried no tenant scope at all, so `GET {prefix}/teams` listed every team a user belonged to across tenants, and any team could be fetched by id or slug from inside another tenant. The new `Ssntpl\Neev\Scopes\TeamTenantScope` applies the same rules `TenantScope` applies to users (disabled → no scope; enabled with no tenant → platform teams only; enabled with a tenant → that tenant's teams). Teams cannot reuse `TenantScope` itself: `users.tenant_id` holds the resolved context id, which is a team id in shared mode, while `teams.tenant_id` is a real foreign key into `tenants`. `Tenant::teams()`, the console team resolver and domain-owner resolution bypass the scope deliberately. See [docs/multi-tenancy.md](./docs/multi-tenancy.md#teams-are-scoped-the-same-way)
- **Removing a member left their team role behind** — `Team::removeUser()`, `neev:member:remove`, and both `requestAction` reject paths detached the membership without deleting the team-scoped role, so the role silently came back if the user was added again. Reject matters because it also removes an already-joined member, not just a pending request
- **`neev:member:add --role` could half-succeed** — `Team::addMember()` attaches first and assigns the role second, so an unknown role threw with the user already in the team. The call is now transactional and the command reports `Role not found`. Under tenant isolation it also refuses a user from a different tenant than the team
- **`neev:tenant:create` created rows a disabled install cannot reach** — it made a team in shared mode with `neev.team` off, and in isolated mode `--owner` created a team regardless. Both are now refused. Every option is also validated before the first row is written: the owner used to be resolved *after* the tenant was created, so a misspelt email left an ownerless tenant behind
- **`neev:tenant:create --owner` left its team outside the tenant** — `tenant_id` is deliberately not fillable, so mass assignment dropped it and the tenant's own team was created at platform level (invisible once that tenant resolves). It is force-created now
- **`neev:install` trusted its arguments** — `tenant` and `teams` were compared with `=== 'yes'`, so `Yes`, `y` or a typo silently meant *no*, and an unknown `kit` failed inside `neev:ui` after the config had already been published and rewritten. All three arguments are now validated before anything is published, with every bad value reported at once, and `neev:ui`'s exit code is propagated instead of discarded
- **`mfa_pending_setup_retention_days = 0` deleted every pending MFA setup** — including one created seconds earlier, while the user still had the QR code on screen. Zero (or less) now disables the cleanup, matching `login_history_retention_days`
- **A domain could be claimed by two owners of the same kind** — the federate endpoints matched only within the team's own relation, so a domain another team held simply got a second row. Uniqueness is now per owner *type*: a tenant and a team may both federate one company domain, but two teams (or two tenants) may not, and only a **verified** claim reserves it. `Domain::findByHostForOwnerType()` is the shared helper — it returns the verified claim, so a null return is also the availability answer. See [docs/teams.md](./docs/teams.md#who-may-claim-a-domain)
- **`Team::resolveByDomain()` did not check the owner type** — it returned `$domain->owner` unconditionally, so a tenant-owned host produced a `Tenant` from a method declared `?static`. Both resolvers now query for their own owner type, which also stops a tenant's row shadowing the team's when both hold the host
- **The "outside members" warning counted each domain in isolation** — with two federated domains, a member on the second was flagged as outside the first and both showed the warning. A member is outside only when their address matches none of the team's verified domains
- **`redirect` on the Blade login form was an open redirect** — the check was "non-empty, not `/`, starts with `/`", which a protocol-relative `//evil.example` satisfies while a browser resolves it to `https://evil.example`. `/\evil.example` did the same via the backslash browsers normalise into the authority, and a non-string `redirect` (`redirect[]=/x`) reached `str_starts_with()` and raised a `TypeError`. All now fall back to `config('neev.home')`. See [docs/security.md](./docs/security.md#open-redirect-protection)
- **A guest bounced to login never got back to the page they wanted** — `NeevMiddleware` redirected to `route('login')` without recording where the user was headed, so every sign-in ended on `config('neev.home')` and the `redirect` form field only worked if the application had wired it up itself. The middleware now uses `redirect()->guest()`, which parks the destination in the session as `url.intended`, and every login entry point — password, MFA, magic link, passkey, OAuth, registration and email verification — sends the user there via the new `AuthService::intendedUrl()`. An explicit `redirect` on the form still wins, and the destination is consumed on use so it cannot leak into a later login. `EnsureEmailIsVerified` records the destination the same way, so verifying an address finishes the journey the user started. See [docs/security.md](./docs/security.md#open-redirect-protection)
- **`UserAuthController::safeRedirect()` moved to `AuthService::safeRedirect()`** and now also accepts an absolute URL on the current host (the shape `redirect()->guest()` stores) and rejects auth pages (`/login`, `/register`, `/logout`, the verification notice, the password reset request) as destinations, since landing back on those bounces the user in a circle. Other hosts, protocol-relative and backslash forms, `/`, and non-strings are rejected as before
- **The intended destination was lost to an MFA challenge** — the password step redirects to the challenge rather than the destination, so a user with a second factor always landed on `neev.home`. The destination is now parked in the session as `mfa_redirect`, re-validated and applied after the code is verified, then cleared; a login carrying no `redirect` clears any parked value, so one cannot leak into a later login
- **A failed role assignment left a half-built membership** — inviting a member attached the pivot row and *then* granted the role, so a `role` that did not resolve threw after the attach and left the user in the team with no permissions, having already been emailed "you're in". Attach and grant are now one transaction on all four paths (invite and accept-invitation, API and Blade); an unresolvable role rolls the membership back and answers `Role not found.`, and the mail is only sent once the membership is committed
- **Acting on a join request from the Blade form is now the owner's call** — accepting admits someone to the team and can hand them a role, which is exactly what inviting does, and inviting is owner-only. A missing `team_id` also reached `$team->hasMember()` on `null`; it is refused now. The API counterpart (`PUT {prefix}/teams/request`) still allows any member — the two are not yet consistent, and [docs/teams.md](./docs/teams.md#membership-is-what-authorises-a-team-action) records that
- **`GET {prefix}/domains/rules` reported a missing domain as a permissions failure** — an unknown `domain_id` and someone else's domain gave the same "you do not have the required permissions" message, so a caller with a stale id had nothing to act on. A domain that does not exist now answers `Domain not found.`
- **Team name uniqueness is scoped to the tenant** — `(name, user_id)` was unique installation-wide, so two tenants could not each hold an owner's "Acme". The index is now `(tenant_id, name, user_id)`. The redundant `(tenant_id, slug)` index was dropped; `slug` carries its own installation-wide unique index, which is stricter and is what lets slug lookups skip the tenant filter. **Caveat:** SQL treats `NULL`s as distinct in a unique index, so on installs running without tenants (`tenant_id IS NULL`) the new index does not fire and an owner *can* now repeat a team name where the old index blocked it
- **Team owners could not revoke a team invitation** — `PUT {prefix}/teams/leave` refused any action whose subject is the owner, and an owner passing no `user_id` is their own subject, so the guard returned `403` before the invitation branch was reached. Revoking an invitation is now handled as its own action: its subject is the invitation, not a member, so the owner and membership rules for removal no longer apply to it
- **Anyone signed in could revoke anyone's team invitation** — the guard's self-branch let any authenticated caller through on the strength of an `invitation_id` alone, without checking the invitation was addressed to them. Cancelling now requires being a member of the team or being the invitee
- **Email OTP cannot be enrolled on an unverified address** — an email second factor is only as trustworthy as the inbox the code lands in. `addMultiFactorAuth('email')` now returns `Email is not verified.` rather than activating the factor. Authenticator apps are unaffected: they prove possession of a device, not of an inbox
- **Registering through a team invitation checks the address matches** — holding the invitation link marks the new account's address verified, so the address being registered has to be the one the invitation was sent to. Previously only the `sha1(email)` hash was checked, which any holder of the link satisfied for any address
- **OAuth registration no longer fails on providers that return no name** — GitHub returns a null name whenever the account has no display name set, which is the common case. Registration now falls back to the provider's nickname, then to the email's local part (`ada.lovelace@example.com` → `Ada Lovelace`)
- **`ResolveTeamMiddleware` accepts a route-model-bound `Team`** — when a route type-hints the model, Laravel hands the middleware a `Team` rather than an id or slug, which previously fell through to a slug lookup on the stringified model and 404'd
- **`PasswordHistory` rule blocked first-time registration** — with the default `neev.password` rules, `PasswordHistory::notReused()` failed with "Unable to verify password history." whenever no user could be resolved, which is exactly the first-time-signup case (no authenticated user; the submitted email belongs to nobody yet). The rule now passes vacuously when there is no user — there is no history to reuse. Reported by a consuming-app developer
- **Accounts without a password could not delete their account, change their password, or change their email** — an account created through OAuth, tenant SSO, a magic link or a passkey holds `users.password = null`, and `Hash::check()` against a null hash can never succeed, so each of those flows refused it forever. Account deletion (`DELETE {prefix}/deleteUser` and the Blade dialog) now takes the authenticated session as the confirmation and does not require `password` when there is none; change-password answers `403 Your account has no password yet. Use the emailed link to set one.`; changing the address still costs a password and answers `403 Set a password on your account before changing your email address.` The Blade security page shows **Set Password** with the emailed-link button instead of the current-password form, and the delete dialog drops the password field. See [docs/authentication.md](./docs/authentication.md#accounts-without-a-password)
- **A team created inside a tenant did not record its tenant** — `Team` deliberately does not use `BelongsToTenant`, whose global scope would break the resolution that reads Teams, and the `tenant_id` assignment that trait provides was missing with it. `Team` now assigns `tenant_id` on create from the resolved context, in isolated mode only — in shared mode the resolved context is itself a Team, which must never become a team's parent

### Removed
- **Remember-me** (breaking) — the checkbox on the Blade login page, the `remember` flag honoured by `LoginRequest::authenticate()`, and `remember_token` from `User::$hidden`. A remembered session sidesteps the session lifetime, MFA and password-expiry policies neev exists to enforce, and nothing in the package ever issued or cleared the cookie beyond passing the flag to `Auth::login()`. Applications that want it can call `Auth::login($user, true)` in their own login path

## [0.5.0] - 2026-07-02

### Added
- **`RegistrationService`** — central registration logic (validation rules, user creation, invitation acceptance with `InvalidInvitationException`, federated-domain team rules, OAuth registration, transaction ownership, `Registered` event); previously duplicated with drift across four controllers. `Domain::isVerifiedForEmail()` replaces five copies of the domain-verification check; unused `MembershipService` removed
- **SPA consumer guide** (`docs/spa-authentication.md`) — completes SPA cookie mode phase 4: backend/CORS/axios setup, all auth flows with exact response shapes, the SSO → SPA hand-off, and troubleshooting
- Documentation: identity-mode decision matrix (multi-tenancy.md), authoritative middleware usage & ordering guide (architecture-internals.md), queue/tenant-context job pattern (multi-tenancy.md), and a verified, prominent warning that OAuth login bypasses MFA and password policies with mitigations (authentication.md, security.md)
- **Headless core + Blade starter kit (RFC 002, phase A)** — the package is now fully headless by default, Fortify-style:
  - New `ui` config value (`NEEV_UI`: `'blade'` | `null`). `null` (default) registers no Blade page routes — API, OAuth/SSO, and email flows work standalone. `'blade'` registers the page routes, rendered from **app-owned** views
  - The Blade page templates moved from package-loaded views to `stubs/blade/views/`; `php artisan neev:ui blade` ejects them to `resources/views/vendor/neev` where they belong to the app (existing published views keep working — same path)
  - **Email templates are ejected to the app by the installer** (always, regardless of kit) so they're editable from day one; the package keeps fallback copies so headless installs still send mail. The per-template variable contract is documented in `docs/rfcs/002-starter-kits.md` §5.5 and treated as API
  - `neev:install` gains a starter-kit prompt (`blade`/`none`) and third argument; new `neev:ui {kit} [--force]` command for kit ejection on existing apps (never overwrites app files without `--force`)
  - Headless email links point at the app's frontend (`{app.url}/verify-email?...`, `/register?invitation_id=...`) instead of the unregistered Blade routes
  - New publish tags: `neev-blade-kit`, `neev-mail` (replacing `neev-views`)
- **Configurable route prefix** — new `route_prefix` config key (`NEEV_ROUTE_PREFIX`, default `neev`) namespaces every machine-facing route the package registers: the API namespace, OAuth redirect/callback, tenant SSO, and `/csrf-cookie`. Blade UI pages (`/login`, `/account/...`) stay at the root. Route names are unchanged. The MFA-token route gate in `NeevAPIMiddleware` now follows the prefix (previously hardcoded — customised route files silently broke MFA step-up)

### Changed
- **BREAKING: OAuth and tenant-SSO routes moved under the route prefix** — `/oauth/{service}[/callback]` → `/neev/oauth/{service}[/callback]`, `/sso/redirect|callback` → `/neev/sso/...`, and `/api/tenant/auth` → `/neev/tenant/auth`. **Update the redirect URIs registered with your identity providers** (Entra/Google/Okta app registrations, OAuth apps)
- **SPA cookie mode — phase 1 (plumbing)** — same-origin SPAs can now authenticate via an HttpOnly cookie instead of JS-stored bearer tokens:
  - `EnsureSpaRequestsAreStateful` middleware (in the `neev:api` and `neev:login` groups): for requests from a configured stateful origin, validates a signed double-submit CSRF token on state-changing methods (419 on failure) and promotes the auth cookie to an `Authorization: Bearer` header; a no-op for everything else, so existing bearer callers are untouched
  - `GET /neev/csrf-cookie` issues the CSRF cookie; the token is HMAC-signed to the app key (defeats subdomain cookie injection, no server-side state)
  - New `spa` config block: `stateful` origin allowlist (exact host, `host:port`, `*.wildcard`), cookie names/attributes; empty list disables the feature entirely
  - `StatefulOriginResolver` and `SpaCsrfToken` services
- **SPA cookie mode — phase 2 (cookie issuance)** — for stateful-origin callers, all token-issuing endpoints (`/neev/login`, `/neev/register`, `/neev/loginUsingLink`, `/neev/mfa/otp/verify`, passkey login, OAuth API callback) now deliver the token in the HttpOnly auth cookie and **omit `token` from the JSON body**; the MFA step carries the short-lived JWT in the cookie and swaps it for the login token on verification; `POST /neev/logout` expires the cookie for cookie-authenticated sessions (`logoutAll` keeps it — the current session survives). Non-SPA callers see byte-identical responses. New `SpaCookieResponder` service. The consumer migration guide (phase 4) follows per `docs/spa-cookie-mode.md`
- **SPA cookie mode — phase 3 (web-redirect callbacks)** — tenant-SSO callbacks with a stateful `redirect_uri` deliver the token via the HttpOnly cookie and keep it **out of the URL fragment** (fragments are JS-visible and XSS-exfiltratable; non-stateful targets keep the fragment flow); web OAuth callbacks on a stateful host additionally issue the SPA cookie alongside the session login. The auth cookie is auto-excluded from Laravel's cookie encryption (`EncryptCookies::except()` registered by the provider) so web-group redirects emit it readable by the API routes
- **MFA setup verification (pending → active)** — adding an authenticator now creates the method in `pending` status; it only becomes `active` (and enforced at login) after the user proves the setup by submitting a valid TOTP to the new `POST /neev/mfa/setup/verify` endpoint (web: the Verify form on the security page). Previously the method was enforced the moment the QR code was generated — abandoning setup locked the user out at next login. `MfaMethodAdded` now fires on activation, not row creation; pending setups cannot satisfy MFA challenges, be set preferred, or count towards recovery-code eligibility
- **`neev:clean-pending-mfa-setups` command** — deletes pending setups older than `mfa_pending_setup_retention_days` (default 2); schedule it alongside `neev:clean-login-attempts`
- `status` column on `multi_factor_auths` (`pending`/`active`); `activeMultiFactorAuths()` relation and `verifyMfaSetup()` on `HasMultiAuth`
- **`MultiFactorAuth::activate()`** — sanctioned escape hatch for programmatic activation (admin provisioning, imports, tests): skips the OTP proof but keeps the invariants (preferred-flag assignment, `MfaMethodAdded` event)
- **Events system expansion** — neev now fires Laravel's native auth events where semantics match, plus Neev-specific events for package concepts:
  - Native: `Illuminate\Auth\Events\Registered` (web/API/OAuth registration, SSO auto-provision), `Illuminate\Auth\Events\PasswordReset` (web/API reset flows); `Lockout` was already fired by `LoginRequest`
  - Neev: `PasswordChanged` (any password change incl. resets), `EmailVerified` (first-time email verification; dispatched after commit), `MfaMethodAdded`, `MfaMethodRemoved`, `RecoveryCodesGenerated`, `TeamCreated`, `TeamDeleted`, `MemberAdded`, `MemberRemoved`, `TenantCreated`, `SsoUserProvisioned`
  - Model-lifecycle events (`TeamCreated`, `TeamDeleted`, `TenantCreated`, `MemberAdded`, `MemberRemoved`) implement `ShouldDispatchAfterCommit` so listeners never observe rolled-back state
- **`removeMultiFactorAuth()` on `HasMultiAuth`** — centralises MFA method removal (preferred-flag reassignment, recovery-code cleanup) previously duplicated across the web and API controllers
- **`DELETE /neev/sessions/{id}`** — revoke a single login session remotely; the current session is protected (use logout), other users' sessions return 404
- Specification for SPA cookie mode with Sanctum-style CSRF token (`docs/spa-cookie-mode.md`, proposed)

### Changed
- **BREAKING: `LoggedInEvent` → `LoggedIn`, `LoggedOutEvent` → `LoggedOut`** — event classes renamed to match Laravel's unsuffixed past-tense convention (consistent with the existing domain events). Update any listeners referencing the old class names

### Removed
- **BREAKING: Laravel 11 support dropped** — `laravel/framework` requirement is now `^12.0` (testbench `^10.0`). Laravel 11 is past security-EOL with permanently-unpatched advisories, which newer Composer versions refuse to resolve. Apps on Laravel 11 should stay on neev `<=0.4.5`

## [0.4.5] - 2026-06-02

### Added
- **Passkey multi-origin support** — new `neev.relying_party_id` and `neev.allowed_origins` config keys let `PasskeyController` verify WebAuthn ceremonies against multiple origins (apex + subdomains, staging + production) bound to a single relying party ID. `CheckOrigin` replaced with `CheckAllowedOrigins`; RP ID and origin list are no longer hardcoded to `APP_URL`
- **`JwtLoginMiddleware` + `neev:login` middleware group** — MFA step-up flow now uses a short-lived JWT between the password step and MFA verification
- **`JwtSecret` service** — dedicated JWT signing secret via `NEEV_JWT_SECRET` env (`neev.jwt_secret`), falling back to `APP_KEY`
- `login_token_expiry_minutes` config key; `email_verified` field in auth responses
- `AuthService::createApiToken()` and auth building blocks: `sendEmailVerification()`, `sendEmailChangeVerification()`, `verifyEmailSignature()`, `changePassword()`
- `User::findByEmail()`, `User::uniqueEmailRule()`; `hasVerifiedEmail()` / `markEmailAsVerified()` on `NeevAuthenticatable`

### Changed
- **BREAKING: login flow** — login responses now return `auth_state` (`authenticated` / `mfa_required`); MFA verification happens via `POST /neev/mfa/otp/verify` under the `neev:login` group using the step-up JWT
- **BREAKING: users table consolidation** — `email`, `email_verified_at`, `password`, `password_history` (JSON), `password_changed_at` are now columns on `users`; multi-email-per-user support removed
- **BREAKING: email verification always uses signed URLs** — OTP-based email verification removed; API password reset switched from OTP to signed URLs

### Removed
- **BREAKING:** `emails` and `passwords` tables; `Email` and `Password` models; `VerifyEmail` trait; `EmailFactory`
- **BREAKING:** email management routes/controllers/views (add / delete / set-primary email)
- `neev:clean-passwords` (`CleanOldPasswords`) command — the JSON `password_history` array is self-trimming
- `email_verification_method` config key (always signed URLs now)

## [0.4.4] - 2026-03-11

### Fixed
- **Cross-tenant token authentication** — `NeevAPIMiddleware` no longer bypasses `TenantScope` when looking up the authenticated user; the scope now naturally rejects tokens from other tenants at the auth layer instead of relying on downstream `EnsureTenantMembership` middleware
- **`AccessToken` tenant scoping** — added `BelongsToTenant` trait to `AccessToken` model so token lookups are tenant-scoped, preventing cross-tenant token resolution before hash verification
- **Bearer token extraction** — API tokens are now only accepted via the `Authorization: Bearer` header; removed insecure fallback to query string and request body (tokens in URLs leak via logs, referrers, and browser history)

### Changed
- **Email verification extracted to dedicated middleware** — removed hardcoded email verification checks (with fragile bypass path lists) from `NeevMiddleware` and `NeevAPIMiddleware`; added `EnsureEmailIsVerified` middleware registered as `neev:verified-email` alias for consuming apps to apply where needed
- **Consolidated migration indexes** — merged all indexes from the separate `add_performance_indexes` migration into their original table creation migrations; deleted `2025_01_01_000013_add_performance_indexes.php`

### Removed
- **`platform_team_id`** column from `tenants` table — the concept of a "platform team" linked to a tenant was only used by CLI commands and had no runtime purpose; `CreateTenantCommand` still creates a default team when `--owner` is provided but no longer links it via a foreign key
- **`managed_by_tenant_id`** column from `tenants` table — the reseller/tenant hierarchy feature had no runtime implementation and the "platform as tenant" model conflicts with `TenantScope` design (platform operates as `tenant_id = NULL`)
- **`--tenant` option** from `neev:member:add` and `neev:member:list` commands — member management now requires `--team` directly
- **`--managed-by` option** from `neev:tenant:create` command
- `platformTeam()` relationship from `Tenant` model and `managedTenant()` from `Team` model
- `managedBy()` and `managedTenants()` relationships from `Tenant` model

## [0.4.3] - 2026-03-10

### Fixed
- **TenantScope scopes to platform users when no tenant resolved** — when tenant isolation is enabled but no tenant is resolved, queries now scope to `WHERE tenant_id IS NULL` (platform users only) instead of silently running unscoped, preventing cross-tenant data leakage while supporting platform-level users
- Moved `TenantScope` PHPStan suppression from baseline to inline `@phpstan-ignore` comments colocated with the calls

### Changed
- **Renamed `current_team_id` → `default_team_id`** — clarifies this is a user preference (which team to land on after login), not the request-scoped team context (which comes from `TenantResolver`/`ContextManager`)
- **Renamed `currentTeam()` → `defaultTeam()`** on `HasTeams` trait — relationship accessor for the user's default team preference
- **Renamed `switchTeam()` → `setDefaultTeam()`** on `HasTeams` trait — persists the user's default team preference to the database
- **Renamed `switchTeam()` → `setDefaultTeam()`** on `TeamApiController` — API endpoint moved from `PUT /neev/teams/switch` to `PUT /neev/teams/default`

### Added
- `TenantResolver::runInContext()` — run a callback within a specific tenant/team context, with automatic state save/restore (useful for platform code provisioning tenant resources outside a request)
- `tenant_id` is now mass-assignable on `User` and `Email` models, allowing platform code to explicitly set tenant ownership when creating records outside tenant context

## [0.4.2] - 2026-03-09

### Fixed
- `Domain::$type` reference in `TenantDomainController` — column was removed in v0.4.0 polymorphic refactor but `store()` and `regenerateToken()` still referenced it
- `PasskeyController` using plain arrays instead of `PublicKeyCredentialDescriptor` objects for WebAuthn credential descriptors
- Unreachable ternary branch in `Password::checkPasswordWarning()`
- Redundant `stripos` Chrome guard in Safari detection (`LoginAttempt::getClientDetails`)

### Changed
- PHPStan baseline reduced from 318 to 109 errors (66% reduction)
- Added comprehensive `@property` docblocks to all 17 Eloquent models for static analysis and IDE support
- Added `@return static` to `model()` factory methods on User, Team, and Tenant for proper type narrowing
- `TenantResolver::resolvedContext()` now returns intersection PHPDoc type (`ContextContainerInterface & IdentityProviderOwnerInterface & HasMembersInterface`)
- Removed dead code branches where values are provably non-null (after `create()`, Socialite `user()`, etc.)
- Removed unnecessary nullsafe operators on type-hinted parameters
- Removed always-true `instanceof HasMembersInterface` check in `EnsureTenantMembership`

### Removed
- `test_callback_redirects_when_oauth_user_is_null` test — tested an impossible code path (Socialite `user()` never returns null)

## [0.4.1] - 2026-03-09

### Changed
- **Role management overhaul** — removed `role` column from `team_user` pivot table; roles are now exclusively managed via laravel-acl's polymorphic `acl_role_assignments` table
- `RoleController` is now generic — uses `assignRole()` for any resource (Team, Tenant, or null for global) instead of hardcoding Team pivot updates
- `TenantSSOManager::ensureMembership()` accepts both Team and Tenant via `HasMembersInterface & IdentityProviderOwnerInterface` intersection type
- `TenantSSOController` uses the resolved context directly instead of Team-only `current()` lookup
- `Tenant::hasMember()` checks direct `tenant_id` membership first, then falls back to indirect team membership
- `Team::addMember()` encapsulates pivot attach + role assignment

### Removed
- `role` column from `team_user` pivot table — use laravel-acl `assignRole()`/`getRole()` instead
- Dead `$team->default_role` code from 6 controllers (was never a real DB column)
- `addMember()` from `HasMembersInterface` and `Tenant` model (tenant membership is implicit via `tenant_id` at user creation)

### Fixed
- Role scoping now respects identity mode: Team when `team=true`, Tenant when `tenant=true, team=false`, global when both false
- SSO auto-provisioning works for both Team and Tenant contexts
- `Tenant::activated_at` / `inactive_reason` columns are now functional with `isActive()`, `activate()`, `deactivate()` methods

## [0.4.0] - 2026-03-08

### Changed
- **Config overhaul** — reduced from ~30 keys to ~20 with 2 orthogonal identity flags (`tenant` + `team`)
- **Domain model** — polymorphic `owner` (morphTo) replaces `team_id` + `tenant_id`; plaintext verification token; DNS verification consolidated into single `$domain->verify()` method
- Progressive `login_throttle` replaces hard lockout
- Single `password_expiry_days` replaces soft/hard split
- Single `otp_length` replaces `otp_min`/`otp_max`
- GeoIP configs grouped under `maxmind` namespace
- All enforcement middleware is opt-in (none auto-applied)

### Added
- `NeevAuthenticatable` umbrella trait (combines `HasMultiAuth` + `HasAccessToken` + `VerifyEmail` + auth relationships + password expiry helpers)
- `EnsureTenantIsActive`, `EnsurePasswordNotExpired` middleware (opt-in)
- Domain re-verification support: `verification_failed_at` column, `VerifyDomainJob`, `VerifyAllDomainsJob`
- Domain events: `DomainVerified`, `DomainReverified`, `DomainVerificationFailed`
- Database indexes for frequently queried columns
- Domain-to-tenant cache (5 min TTL), auth settings cache (30 min TTL)
- Database schema documentation (`docs/db-schema.dbml`)

### Removed
- `EmailDomainValidator` service and all waitlist/free email logic
- 30+ obsolete config keys: `identity_strategy`, `tenant_isolation`, `tenant_auth`, `email_verified`, `require_company_email`, `domain_federation`, `magicauth`, `dashboard_url`, `frontend_url`, and more

## [0.3.0] - 2025-03-01

### Added
- Email verification method configuration (`email_verification_method`) — choose between 'link' or 'otp' verification
- `GET /neev/passkeys` endpoint to list user's passkeys
- `GET /neev/teams/invitations` endpoint to get user's invitations and join requests
- Verification method returned in API responses for email verification flows

### Changed
- `POST /neev/logoutAll` now keeps current session active and only logs out other devices
- Email verification flows now respect the configured verification method
- Improved code structure with separate `sendMailLink()` function

### Fixed
- Missing route imports and method corrections

## [0.2.0] - 2025-02-20

### Added
- **Identity strategy system** — choose between `shared` (users global, teams as collaboration) and `isolated` (users scoped to tenant, tenant resolved before auth) via `config('neev.identity_strategy')`
- **Tenant model** (`Ssntpl\Neev\Models\Tenant`) — dedicated identity boundary for isolated mode with slug-based resolution, managed-by hierarchy, and SSO ownership
- **TenantAuthSettings model** — per-tenant SSO configuration (mirrors TeamAuthSettings for isolated mode)
- **ContextManager service** — request-scoped singleton holding resolved tenant, team, and user; immutable after binding
- **Entity-agnostic contracts** — `ContextContainerInterface`, `ResolvableContextInterface`, `IdentityProviderOwnerInterface`, `HasMembersInterface` — same service code works for both Team and Tenant
- **MembershipService** — entity-agnostic membership checks via `HasMembersInterface`
- **IdentityProviderService** — entity-agnostic SSO/auth queries via `IdentityProviderOwnerInterface`
- **BindContextMiddleware** — locks ContextManager after middleware pipeline, clears after response
- **EnsureContextSSO middleware** (`neev:ensure-sso`) — enforces SSO-only access for teams/tenants with SSO configured
- **ResolveTeamMiddleware** (`neev:resolve-team`) — resolves team from route parameter (numeric ID or slug)
- **TeamScope** global scope and `BelongsToTeam` trait — team-level model scoping (complements existing `BelongsToTenant`)
- **`tenants` migration** — `id`, `name`, `slug` (unique), `managed_by_tenant_id` (self-reference for reseller model)
- **`tenant_auth_settings` migration** — SSO config for tenants in isolated mode
- **TenantFactory and TenantAuthSettingsFactory** for testing
- **Install wizard stubs** — published migration stubs for hard user isolation (`tenant_id` on users table)
- **Architecture documentation** — `docs/architecture.md` (identity strategy, tenant vs team concepts, context lifecycle) and `docs/architecture-internals.md` (interfaces, patterns, coding standards)
- Comprehensive test suite — 996 tests, 1886 assertions

### Changed
- **TenantResolver** now resolves `Tenant` in isolated mode and `Team` in shared mode via the `ContextContainerInterface` abstraction. Backward-compatible `current()` method still returns Team
- **TenantResolver** populates `ContextManager` automatically on resolution
- **Middleware pipeline** reordered: TenantMiddleware → ResolveTeamMiddleware → Auth → EnsureTenantMembership → BindContextMiddleware
- **TenantMiddleware** now uses `TenantResolver::resolve()` which handles X-Tenant header, subdomain, and custom domain resolution in a unified flow
- **EnsureTenantMembership** updated to work with `ContextManager` and `MembershipService`
- **TenantSSOManager** refactored to accept `IdentityProviderOwnerInterface` — works with both Team and Tenant
- **TenantSSOController** updated to use `ContextManager` for tenant resolution and `IdentityProviderOwnerInterface` for SSO
- **Team model** now implements `ContextContainerInterface`, `ResolvableContextInterface`, `IdentityProviderOwnerInterface`, `HasMembersInterface`
- **Domain model** gains `tenant_id` foreign key and `tenant()` relationship for isolated mode
- **AccessToken model** gains tracking fields (`last_used_ip`, `user_agent`, etc.)
- **BelongsToTenant trait** updated to be identity-strategy-aware — resolves to `teams` or `tenants` table depending on config
- **NeevServiceProvider** registers `ContextManager` as scoped singleton, registers new middleware aliases
- **TenantScope** updated to read from `ContextManager` instead of `TenantResolver` directly
- Minimum PHP version bumped to 8.3
- Dropped Laravel 10 support (Laravel 11.x and 12.x only)
- CI workflows updated for PHP 8.3

### Fixed
- Fixed copy-paste error in API reference docs — "Send Verification Email" response now correctly says "Verification email has been sent"
- Fixed duplicated "Customizing Routes" section in web routes documentation

### Documentation
- **docs/README.md** rewritten as organized documentation hub (Guides / Reference / Architecture)
- **docs/multi-tenancy.md** — added Identity Strategy section, ContextManager section, fixed TenantResolver method signatures, added BelongsToTeam docs, updated database schema
- **docs/configuration.md** — added `identity_strategy` and `tenant_model` options
- **docs/installation.md** — added multi-tenancy and architecture links
- **README.md** — added `identity_strategy` to feature toggles, middleware aliases table, updated database schema, added architecture docs link, added Contributing/Security links
- **CLAUDE.md** — updated with all new directories, models, middleware, services, traits, and patterns
- **TODO.md** — updated stale items (tests, PHPStan, Pint, CI now marked complete)
- Cross-links added between all documentation files

## [0.1.2] - 2025-02-11

### Added
- Comprehensive test suite with 60%+ line coverage
- Codecov coverage integration
- CI badges (code style, static analysis, tests, coverage)

### Changed
- Minimum PHP version bumped to 8.3
- Dropped Laravel 10 support
- CI workflows updated for PHP 8.3

## [0.1.1] - 2025-12-16

### Added
- Code quality tooling (PHPStan level 5, Pint PSR-12)
- GitHub Actions CI workflows (tests, static analysis, code style)
- Community files (CONTRIBUTING.md, CODE_OF_CONDUCT.md, SECURITY.md, CHANGELOG.md)
- Database factories

### Changed
- Simplified and refined codebase for clarity and consistency
- Hash OTPs at rest, fix model casts, clean up token handling

### Fixed
- Security vulnerabilities and bugs for public release
- MFA setup bug fix
- Recovery codes stored as hashed values

## [0.1.0] - 2025-11-26

### Added
- Initial public release
- Password-based authentication with strong validation
- Magic link (passwordless) authentication
- Passkey/WebAuthn support (biometric, hardware keys)
- OAuth/Social login (Google, GitHub, Microsoft, Apple)
- Multi-factor authentication (TOTP authenticator apps, email OTP)
- Recovery codes for MFA backup
- Team management with invitations and role-based access
- Domain federation for automatic team joining
- Multi-tenancy with subdomain and custom domain support
- Per-tenant SSO configuration (Microsoft Entra ID, Google Workspace, Okta)
- Model-level tenant isolation via `BelongsToTenant` trait
- Brute force protection with progressive delays and lockout
- Password history to prevent reuse
- Password expiry policies (soft warning + hard expiry)
- Login attempt tracking with GeoIP location
- Session management
- API token authentication with permissions
- Comprehensive Blade views and email templates
- Artisan commands for installation, GeoIP download, and cleanup

[Unreleased]: https://github.com/ssntpl/neev/compare/v0.6.0...HEAD
[0.6.0]: https://github.com/ssntpl/neev/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/ssntpl/neev/compare/v0.4.5...v0.5.0
[0.4.5]: https://github.com/ssntpl/neev/compare/v0.4.4...v0.4.5
[0.4.4]: https://github.com/ssntpl/neev/compare/v0.4.3...v0.4.4
[0.4.3]: https://github.com/ssntpl/neev/compare/v0.4.2...v0.4.3
[0.4.2]: https://github.com/ssntpl/neev/compare/v0.4.1...v0.4.2
[0.4.1]: https://github.com/ssntpl/neev/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/ssntpl/neev/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/ssntpl/neev/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/ssntpl/neev/compare/v0.1.2...v0.2.0
[0.1.2]: https://github.com/ssntpl/neev/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/ssntpl/neev/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/ssntpl/neev/releases/tag/v0.1.0
