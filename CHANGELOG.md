# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/ssntpl/neev/compare/v0.4.2...HEAD
[0.4.2]: https://github.com/ssntpl/neev/compare/v0.4.1...v0.4.2
[0.4.1]: https://github.com/ssntpl/neev/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/ssntpl/neev/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/ssntpl/neev/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/ssntpl/neev/compare/v0.1.2...v0.2.0
[0.1.2]: https://github.com/ssntpl/neev/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/ssntpl/neev/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/ssntpl/neev/releases/tag/v0.1.0
