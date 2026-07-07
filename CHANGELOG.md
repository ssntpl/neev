# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed
- **BREAKING: Magic-link authentication rebuilt as stateful, single-use tokens** — replaces the stateless signed-URL flow. Opaque, high-entropy tokens are stored **hashed** (SHA-256) in the new `magic_link_tokens` table; only the plain token ever leaves the app (in the emailed URL).
  - **Single-use by deletion** — the token row is deleted on successful redemption, so a link can never be replayed. Generating a new link **invalidates the user's previous link** for that channel.
  - **Config-driven, extensible channels** — new `magic_link.channels` config (`web`, `mobile`, and any host-defined channel such as `desktop`, added by config with no code change). A channel with a `scheme`/`universal_link` is built as a deep link; otherwise `base_url` + `path`. Send accepts a `channel` parameter.
  - **Optional browser/device binding** (`magic_link.bind_to_browser`) and **confirmation-required flow** (`magic_link.require_confirmation`) to defeat email-scanner auto-consumption.
  - **Single route for login + confirmation** — `GET|POST /neev/loginUsingLink` (API) and `GET|POST /login-link/verify` (Blade): GET opens the link (validate-only when confirmation is required), POST is the explicit confirm. New `GET|POST /neev/loginUsingLink/validate` checks a token without consuming it. Redemption routes are now rate-limited (`throttle:10,1`).
  - **New `magic_link` config block** — single engine (no driver switch): `expires_in` (default 10 min), `bind_to_browser`, `require_confirmation`, `channels`. The legacy `url_expiry_time` no longer governs magic links (still used by password-reset / email-verification links).
  - **API surface** — `MagicLinkManager` service (inject it; no facade). `forWeb()`/`forMobile()`/`generate()` return an array (`url`, `token`, `expires_in`, `channel`, `model`); `validate()`/`consume()` return a `MagicLinkResult` whose status is a string constant (`MagicLinkResult::VALID`, `EXPIRED`, `INVALID`, `BINDING_MISMATCH`, `PENDING_CONFIRMATION`, `INACTIVE_USER`). Token mechanics (`generateToken`/`hashToken`/`findByToken`) live on the `MagicLinkToken` model.
  - **Removed the legacy web route** `GET /login/{id}` (`login.link`); the Blade flow now redeems at `GET|POST /login-link/verify` (`login.link.verify`).
  - Note: a valid magic link still completes login **without** enforcing MFA (unchanged, by design).

### Added
- **Lifecycle events** — `MagicLinkGenerated`, `MagicLinkConsumed`, `MagicLinkRejected` for auditing, notifications, and abuse detection.
- **`neev:clean-magic-links` command** — deletes expired magic-link tokens; schedule alongside `neev:clean-login-attempts`.

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

[Unreleased]: https://github.com/ssntpl/neev/compare/v0.4.4...HEAD
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
