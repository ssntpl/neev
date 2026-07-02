# Neev - Project Status & TODO

> Enterprise User Management Package for Laravel
> Last updated: 2026-07-02

---

## Completed Features

### Authentication
- [x] Password-based login/registration
- [x] Magic link (passwordless) authentication
- [x] Passkey/WebAuthn support (biometric, hardware keys)
- [x] OAuth/Social login (Google, GitHub, Microsoft, Apple)
- [x] Password reset via email link
- [x] Password change for authenticated users
- [x] Configurable password complexity rules (min/max, mixed case, numbers, symbols)
- [x] Email verification with signed links

### Multi-Factor Authentication (MFA)
- [x] TOTP authenticator app support (Google Authenticator, Authy, 1Password)
- [x] Email OTP (6-digit codes with configurable expiry)
- [x] Recovery codes (8 single-use backup codes, hash-stored)
- [x] Preferred MFA method tracking per user
- [x] MFA enforcement during login flow
- [x] Setup verification (pending → active): authenticator only enforced after the user proves the setup with a valid TOTP; abandoned setups purged by `neev:clean-pending-mfa-setups`

### Team Management
- [x] Team CRUD with auto-slug generation
- [x] Invite members by email with signed links
- [x] Role-based access (member, admin, owner)
- [x] Accept/reject invitations
- [x] Request-to-join flow for public teams
- [x] Team switching (multi-team users)
- [x] Ownership transfer
- [x] Reserved slug prevention

### Domain Federation
- [x] Email domain claiming by teams
- [x] DNS verification for domains
- [x] Auto-join rules based on email domain
- [x] Primary domain assignment per team
- [x] Custom security rules per domain

### Multi-Tenancy
- [x] Subdomain-based tenant routing
- [x] Custom domain support with DNS verification
- [x] `TenantResolver` service (singleton, hostname-based)
- [x] Per-tenant authentication configuration
- [x] Per-tenant SSO (Microsoft Entra ID, Google Workspace, Okta)
- [x] `TenantSSOManager` with dynamic Socialite configuration
- [x] Auto-provisioning of SSO users
- [x] Single/multi-tenant user mode option

### Security
- [x] Brute force protection (progressive delay via `login_throttle`: exponential backoff after `delay_after` failures, capped at `max_delay_seconds`)
- [x] Login attempt tracking (IP, browser, OS, device, GeoIP location)
- [x] Password history (prevents reusing last N passwords)
- [x] Password expiry enforcement (`password_expiry_days` config + opt-in `neev:password-not-expired` middleware, added in v0.4.0)
- [x] Email verification enforcement (opt-in `neev:verified-email` middleware, added in v0.4.4)
- [x] MaxMind GeoIP integration for IP geolocation

### API & Access Tokens
- [x] Persistent API tokens with permission scoping
- [x] Temporary login tokens with auto-expiry
- [x] Token CRUD (create, update, delete, list, bulk delete)
- [x] SHA256 token hashing

### Email Management
- [x] Single email per user on the `users` table (separate `emails`/`passwords` tables dropped and consolidated onto `users`)
- [x] Email verification tracking
- [x] Email change with reverification
- [x] Mailables: VerifyUserEmail, EmailOTP, LoginUsingLink, TeamInvitation, TeamJoinRequest

### Views & UI
- [x] 66 Blade templates (auth, account, team, components, layouts)
- [x] 35+ reusable Blade components
- [x] Guest & authenticated layouts
- [x] Email templates (5 files)

### Database
- [x] 12 migrations covering the schema (users + login_attempts, otps, passkeys, multi_factor_auths, recovery_codes, tenants, access_tokens, teams + memberships, team_invitations, domains, team_auth_settings, tenant_auth_settings); password history stored as JSON on `users`

### Console Commands
- [x] `neev:install` - Setup wizard (tenant yes/no, teams yes/no)
- [x] `neev:download-geoip` - Download MaxMind GeoLite2 database
- [x] `neev:clean-login-attempts` - Remove old login records
- [x] Tenant commands - create / list / show
- [x] Domain commands - add / verify / list
- [x] Member commands - add / remove / list
- [x] Auth settings commands - configure / show
- [x] Team command - activate

### Middleware
- [x] `neev:web` - Web authentication with MFA (TenantMiddleware > ResolveTeamMiddleware > NeevMiddleware > EnsureTenantMembership > BindContextMiddleware)
- [x] `neev:api` - API token authentication (TenantMiddleware > ResolveTeamMiddleware > NeevAPIMiddleware > EnsureTenantMembership > BindContextMiddleware)
- [x] `neev:login` - MFA step-up JWT authentication (TenantMiddleware > ResolveTeamMiddleware > JwtLoginMiddleware > EnsureTenantMembership > BindContextMiddleware)
- [x] `neev:tenant` - Tenant resolution, required (TenantMiddleware:required > ResolveTeamMiddleware > BindContextMiddleware)
- [x] `neev:active-team` - Blocks inactive teams
- [x] `neev:active-tenant` - Blocks inactive tenants
- [x] `neev:tenant-member` - Verifies user belongs to tenant
- [x] `neev:resolve-team` - Resolves team from route parameter
- [x] `neev:ensure-sso` - Enforces SSO-only access for current context
- [x] `neev:password-not-expired` - Blocks users with expired passwords (opt-in)
- [x] `neev:verified-email` - Blocks users with unverified email (opt-in)

### Configuration
- [x] Minimal config surface (~20 keys, two orthogonal identity flags `tenant` + `team`) after the v0.4.0 config overhaul; per-tenant/team auth behaviour lives in `tenant_auth_settings`/`team_auth_settings` DB tables

### Documentation
- [x] Markdown docs in `docs/` (README, installation, configuration, authentication, API reference, web routes, CLI commands, teams, MFA, multi-tenancy, security, architecture, architecture internals, db schema) plus design docs (`docs/rfcs/`, `config-refactor.md`, `spa-cookie-mode.md`)

---

## In Progress

### Events System
- [x] `LoggedIn` / `LoggedOut` (renamed from `LoggedInEvent`/`LoggedOutEvent`)
- [x] `DomainVerified` / `DomainReverified` / `DomainVerificationFailed` - Domain lifecycle events (v0.4.0)
- [x] Laravel-native events where semantics match: `Registered`, `PasswordReset`, `Lockout`
- [x] `PasswordChanged`, `EmailVerified`, `MfaMethodAdded`, `MfaMethodRemoved`, `RecoveryCodesGenerated`, `TeamCreated`, `TeamDeleted`, `MemberAdded`, `MemberRemoved`, `TenantCreated`, `SsoUserProvisioned`
- [ ] Event listeners / subscribers for common use cases (e.g. security-event email notifications)

---

## Production Readiness Audit — Pending Follow-ups (2026-03-01)

> Items identified during the production readiness audit. All critical, high, and medium code issues have been fixed. These are remaining documentation and feature gaps.

### Documentation Gaps
- [x] **Events system expansion** — Laravel-native events (`Registered`, `PasswordReset`, `Lockout`) plus Neev events for MFA changes, password changes, team/tenant lifecycle, membership, SSO provisioning, email verification, and domain verification. Documented in `docs/README.md`.
- [x] **Identity mode decision matrix** — four-mode matrix + "Four Questions to Decide" in `docs/multi-tenancy.md`, cross-linked from architecture.md.
- [x] **SSO SPA flow documentation** — covered in `docs/spa-authentication.md` (SSO → SPA section: discovery, redirect, cookie vs fragment callback).
- [x] **Middleware usage and ordering documentation** — authoritative "Middleware Usage & Ordering" section in `docs/architecture-internals.md`.
- [x] **Queue/background job tenant context** — documented in `docs/multi-tenancy.md` (Console & Queue Context) with a complete job example.
- [x] **OAuth security bypass documentation** — verified against the callback flows and prominently documented in `docs/authentication.md` + `docs/security.md`, with mitigations (provider allowlist, `neev:ensure-sso`).
- [x] **CORS/SPA guidance** — covered in `docs/spa-authentication.md` (backend setup: cors.php example, credentials, encryption caveats).

### Code Cleanup
- [ ] **Remove unused `MembershipService`** — `src/Services/MembershipService.php` is defined but never injected or referenced. Either implement it as the central membership operations service or remove it.
- [ ] **Extract `isDomainVerified()` to shared service** — Currently duplicated as a private method in `UserAuthController`, `UserAuthApiController`, and `OAuthController`. Move to `EmailDomainValidator` service.
- [ ] **Web/API registration feature parity** — Web and API registration controllers have diverged in feature coverage. Consider extracting a `RegistrationService` to centralize: company email enforcement, tenant isolation handling, username support, team creation with activation.
- [x] ~~Lint violations in demo/ directory~~ — obsolete: `demo/` is gitignored (local-only, not shipped).

---

## TODO - Pending Work

### Code TODOs
- [ ] **Email reputation package** — `EmailDomainValidator` (hardcoded free-email list, `require_company_email`) was removed in v0.4.0 on the promise of a standalone email-reputation package (`docs/email-reputation-package.md`, still proposed). Until it ships, consuming apps have no company-email enforcement. Decide v1 scope (classification-only vs network validation) and data sources:
  - https://gist.github.com/ammarshah/f5c2624d767f91a7cbdc4e54db8dd0bf
  - https://github.com/disposable/disposable-email-domains
  - https://github.com/disposable/disposable

### Testing
- [x] **Test suite** - Comprehensive test suite with 60%+ line coverage
  - Unit tests for Services, Middleware, Models, Traits, Scopes
  - Feature tests for Authentication flows, Tenant SSO, Tenant Domains
- [ ] **Expand test coverage** - Additional tests needed:
  - Feature tests for MFA flows (TOTP setup/verify, Email OTP, recovery codes)
  - Feature tests for Team management (CRUD, invitations, membership, switching)
  - Feature tests for API token management
  - Integration tests for rate limiting and brute force protection

### Security Enhancements
- [ ] **SAML 2.0 support** - Currently only OAuth/Socialite-based SSO; SAML would unlock enterprise IdPs (ADFS, PingFederate, etc.)
- [ ] **SMS-based MFA** - Only TOTP and email OTP supported currently
- [ ] **Suspicious login detection & alerts** - Mentioned in docs but no dedicated implementation beyond GeoIP tracking
- [x] **Session management** - Active sessions listing (`GET /neev/sessions`, web views), remote logout of all sessions, and single-session revoke (`DELETE /neev/sessions/{id}`)
- [ ] **Admin override for MFA recovery** - Support-assisted account recovery when all MFA methods lost

### Feature Enhancements
- [ ] **Webhook support** - Allow apps to receive webhook callbacks on auth/team events
- [ ] **Audit logging** - Comprehensive audit trail beyond login attempts (e.g., permission changes, team settings updates, token operations)
- [ ] **Account deletion / data export** - GDPR compliance features (right to deletion, data portability)
- [ ] **Email notifications for security events** - New device login, password changed, MFA disabled, etc.
- [ ] **Admin dashboard views** - Team/user administration for app owners
- [ ] **IP allowlist/blocklist** - Per-tenant or global IP access control
- [ ] **Rate limiting with Redis** - Distributed rate limiting for multi-server deployments (currently cache-based)

### Code Quality
- [ ] **Decompose large controllers** - `UserAuthController.php` (27 methods) and `TeamApiController.php` (26 methods) could benefit from splitting into focused controllers
- [x] **PHPStan / static analysis** - Level 5 with Larastan, integrated in CI
- [x] **PHP CS Fixer / Pint config** - PSR-12 code style enforcement via Pint

### DevOps & CI
- [x] **CI/CD pipeline** - GitHub Actions for tests, static analysis, code style, Codecov coverage
- [ ] **Automated GeoIP database updates** - Scheduled workflow for monthly MaxMind DB refresh
- [ ] **Package publishing automation** - Automated Packagist release on tag

---

## Architecture Notes

- **Feature-flagged design**: All major features (teams, tenancy, federation, MFA) are toggle-able via config
- **Extensible models**: User and Team models are swappable via config
- **Laravel 12.x** compatible, PHP 8.3+
- **Key dependencies**: geoip2/geoip2, web-auth/webauthn-lib, laravel/socialite, spomky-labs/otphp, ssntpl/laravel-acl
