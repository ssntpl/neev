# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
