# Neev - Enterprise User Management for Laravel

Neev is a comprehensive Laravel package that provides enterprise-grade user authentication, team management, and security features. It's designed as a complete starter kit for SaaS applications, eliminating the need to build complex user management systems from scratch.

[![Latest Version](https://img.shields.io/packagist/v/ssntpl/neev.svg?style=flat-square)](https://packagist.org/packages/ssntpl/neev)
[![License](https://img.shields.io/packagist/l/ssntpl/neev.svg?style=flat-square)](https://packagist.org/packages/ssntpl/neev)
[![Code Style](https://github.com/ssntpl/neev/actions/workflows/code-style.yml/badge.svg)](https://github.com/ssntpl/neev/actions/workflows/code-style.yml)
[![Static Analysis](https://github.com/ssntpl/neev/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/ssntpl/neev/actions/workflows/static-analysis.yml)
[![Tests](https://github.com/ssntpl/neev/actions/workflows/tests.yml/badge.svg)](https://github.com/ssntpl/neev/actions/workflows/tests.yml)
[![Coverage](https://codecov.io/gh/ssntpl/neev/branch/main/graph/badge.svg)](https://codecov.io/gh/ssntpl/neev)
[![PHP Version](https://img.shields.io/packagist/dependency-v/ssntpl/neev/php?style=flat-square)](https://packagist.org/packages/ssntpl/neev)
[![Total Downloads](https://img.shields.io/packagist/dt/ssntpl/neev.svg?style=flat-square)](https://packagist.org/packages/ssntpl/neev)

---

## Table of Contents

- [Features](#features)
- [Quick Start](#quick-start)
- [Authentication Methods](#authentication-methods)
- [API Reference](#api-reference)
- [Web Routes](#web-routes)
- [Multi-Factor Authentication](#multi-factor-authentication)
- [Team Management](#team-management)
- [Multi-Tenancy](#multi-tenancy)
- [Security Features](#security-features)
- [Configuration](#configuration)
- [Console Commands](#console-commands)
- [Database Schema](#database-schema)
- [Detailed Documentation](#detailed-documentation)

---

## Features

### Authentication Methods
- **Password-based login** with strong password policies
- **Magic link authentication** (passwordless login via email)
- **Passkey/WebAuthn support** (biometric authentication, hardware keys)
- **OAuth/Social login** (Google, GitHub, Microsoft, Apple)
- **Tenant SSO** (Microsoft Entra ID, Google Workspace, Okta)

### Multi-Factor Authentication
- **TOTP authenticator apps** (Google Authenticator, Authy, 1Password)
- **Email OTP** (6-digit codes via email)
- **Recovery codes** (single-use backup codes)

### Team Management
- Create and manage teams/organizations
- Invite members via email
- Role-based access control
- Domain-based auto-joining (federation)
- Team switching for multi-team users

### Security Features
- Brute force protection with progressive delays (exponential backoff)
- Password history to prevent reuse
- Password expiry policies
- Login attempt tracking with GeoIP
- Session management
- Suspicious login detection

### Multi-Tenancy
- Domain-based tenant resolution (`X-Tenant` header or host lookup)
- Custom domain support with DNS verification
- Per-tenant authentication configuration
- Per-tenant SSO integration

---

## Quick Start

### 1. Install via Composer

```bash
composer require ssntpl/neev
```

### 2. Run the Installation Command

```bash
php artisan neev:install
```

### 3. Configure Environment

```env
NEEV_JWT_SECRET="secret-for-mfa-jwts"   # optional, falls back to APP_KEY
MAXMIND_LICENSE_KEY="your-maxmind-key"  # optional, for GeoIP login tracking
```

The post-login redirect is controlled by the `home` key in `config/neev.php` (defaults to `/dashboard`).

### 4. Run Migrations

```bash
php artisan migrate
```

### 5. Update Your User Model

Extend Neev's User model to inherit all traits (including `BelongsToTenant` for automatic tenant scoping):

```php
use Ssntpl\Neev\Models\User as NeevUser;

class User extends NeevUser
{
    // Add your custom fields, relationships, and methods
}
```

Then update `config/neev.php`:

```php
'user_model' => App\Models\User::class,
```

---

## Authentication Methods

### Password Authentication

```bash
# Register
curl -X POST https://yourapp.com/neev/register \
  -H "Content-Type: application/json" \
  -d '{
    "name": "John Doe",
    "email": "john@example.com",
    "password": "SecurePass123!",
    "password_confirmation": "SecurePass123!"
  }'

# Login
curl -X POST https://yourapp.com/neev/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "john@example.com",
    "password": "SecurePass123!"
  }'
```

**Response:**

```json
{
  "auth_state": "authenticated",
  "token": "1|abc123def456...",
  "expires_in": 1440,
  "mfa_options": null,
  "email_verified": true
}
```

`expires_in` is returned in minutes (defaults from `login_token_expiry_minutes` and `mfa_jwt_expiry_minutes`).

**Response (with MFA enabled):**

```json
{
  "auth_state": "mfa_required",
  "token": "jwt_mfa_token...",
  "expires_in": 30,
  "mfa_options": [
    "authenticator",
    "email"
  ],
  "email_verified": true
}
```

### Magic Link (Passwordless)

```bash
# Send login link
curl -X POST https://yourapp.com/neev/sendLoginLink \
  -d '{"email": "john@example.com"}'

# Login via link
curl -X GET "https://yourapp.com/neev/loginUsingLink?id=1&signature=..."
```

### Passkey / WebAuthn

```bash
# Get registration options
curl -X GET https://yourapp.com/neev/passkeys/register/options \
  -H "Authorization: Bearer {token}"

# Register passkey
curl -X POST https://yourapp.com/neev/passkeys/register \
  -H "Authorization: Bearer {token}" \
  -d '{"attestation": "{...}", "name": "My MacBook"}'

# Login with passkey
curl -X POST https://yourapp.com/neev/passkeys/login \
  -d '{"email": "john@example.com", "assertion": "{...}"}'
```

### OAuth / Social Login

Enable in configuration:

```php
// config/neev.php
'oauth' => ['google', 'github', 'microsoft', 'apple'],
```

Redirect URLs:
- `GET /oauth/{service}` - Redirect to provider
- `GET /oauth/{service}/callback` - Handle callback

---

## API Reference

All API routes are prefixed with `/neev`. Include the Bearer token for authenticated endpoints.

### Authentication Endpoints

| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| POST | `/neev/register` | Register new user | No |
| POST | `/neev/login` | Login with credentials | No |
| POST | `/neev/sendLoginLink` | Send magic link | No |
| GET | `/neev/loginUsingLink` | Login via magic link | No |
| POST | `/neev/logout` | Logout current session | Yes |
| POST | `/neev/logoutAll` | Logout all other sessions | Yes |
| POST | `/neev/forgotPassword` | Send password reset link | No |
| POST | `/neev/resetPassword` | Reset password (signed URL) | No |

### Email Endpoints

| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| POST | `/neev/email/send` | Resend verification email | Yes |
| GET | `/neev/email/verify` | Verify email address | Yes |
| POST | `/neev/email/change` | Request email change | Yes |
| POST | `/neev/email/change/verify` | Verify email change (signed URL) | No |

### MFA Endpoints

| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| GET | `/neev/mfa` | List enabled MFA methods | Yes |
| POST | `/neev/mfa/add` | Enable MFA method | Yes |
| PUT | `/neev/mfa/preferred` | Set preferred MFA method | Yes |
| DELETE | `/neev/mfa/delete` | Disable MFA method | Yes |
| POST | `/neev/mfa/otp/verify` | Verify MFA code | MFA JWT |
| POST | `/neev/recoveryCodes` | Generate recovery codes | Yes |

### Passkey Endpoints

| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| GET | `/neev/passkeys` | List user's passkeys | Yes |
| GET | `/neev/passkeys/register/options` | Get registration options | Yes |
| POST | `/neev/passkeys/register` | Register passkey | Yes |
| GET | `/neev/passkeys/login/options` | Get login options | No |
| POST | `/neev/passkeys/login` | Login with passkey | No |
| PUT | `/neev/passkeys` | Update passkey name | Yes |
| DELETE | `/neev/passkeys` | Delete passkey | Yes |

### User Endpoints

| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| GET | `/neev/users` | Get current user | Yes |
| PUT | `/neev/users` | Update user profile | Yes |
| DELETE | `/neev/users` | Delete user account | Yes |
| PUT | `/neev/changePassword` | Change password | Yes |
| GET | `/neev/sessions` | Get active sessions | Yes |
| GET | `/neev/loginAttempts` | Get login history | Yes |

### API Token Endpoints

| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| GET | `/neev/apiTokens` | List API tokens | Yes |
| POST | `/neev/apiTokens` | Create API token | Yes |
| PUT | `/neev/apiTokens` | Update API token | Yes |
| DELETE | `/neev/apiTokens` | Delete API token | Yes |
| DELETE | `/neev/apiTokens/deleteAll` | Delete all API tokens | Yes |

### Team Endpoints

| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| GET | `/neev/teams` | List user's teams | Yes |
| GET | `/neev/teams/invitations` | Get user's invitations and join requests | Yes |
| PUT | `/neev/teams/default` | Set default team | Yes |
| GET | `/neev/teams/{id}` | Get team details | Yes |
| POST | `/neev/teams` | Create team | Yes |
| PUT | `/neev/teams` | Update team | Yes |
| DELETE | `/neev/teams` | Delete team | Yes |
| POST | `/neev/changeTeamOwner` | Transfer ownership | Yes |
| POST | `/neev/teams/inviteUser` | Invite member | Yes |
| PUT | `/neev/teams/inviteUser` | Accept/reject invitation | Yes |
| PUT | `/neev/teams/leave` | Leave team | Yes |
| POST | `/neev/teams/request` | Request to join | Yes |
| PUT | `/neev/teams/request` | Accept/reject request | Yes |
| PUT | `/neev/role/change` | Change member role | Yes |

### Domain Endpoints

| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| GET | `/neev/domains` | List team domains | Yes |
| POST | `/neev/domains` | Add domain | Yes |
| PUT | `/neev/domains` | Update/verify domain | Yes |
| DELETE | `/neev/domains` | Delete domain | Yes |
| GET | `/neev/domains/rules` | Get domain rules | Yes |
| PUT | `/neev/domains/rules` | Update domain rules | Yes |
| PUT | `/neev/domains/primary` | Set primary domain | Yes |

### Tenant Domain Endpoints

| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| GET | `/neev/tenant-domains` | List tenant domains | Yes |
| POST | `/neev/tenant-domains` | Add custom domain | Yes |
| GET | `/neev/tenant-domains/current` | Get current tenant | Yes |
| GET | `/neev/tenant-domains/{id}` | Get domain details | Yes |
| DELETE | `/neev/tenant-domains/{id}` | Delete domain | Yes |
| POST | `/neev/tenant-domains/{id}/verify` | Verify domain | Yes |
| POST | `/neev/tenant-domains/{id}/regenerate-token` | Regenerate verification token | Yes |
| POST | `/neev/tenant-domains/{id}/primary` | Set as primary | Yes |

---

## Web Routes

### Public Routes

| Method | Route | Name | Description |
|--------|-------|------|-------------|
| GET | `/register` | `register` | Registration form |
| POST | `/register` | - | Process registration |
| GET | `/login` | `login` | Login form |
| PUT | `/login` | `login.password` | Show password form |
| POST | `/login` | - | Process login |
| POST | `/login/link` | `login.link.send` | Send magic link |
| GET | `/login/{id}` | `login.link` | Login via magic link |
| GET | `/forgot-password` | `password.request` | Forgot password form |
| POST | `/forgot-password` | `password.email` | Send reset link |
| GET | `/update-password/{id}/{hash}` | `reset.request` | Reset password form |
| POST | `/update-password` | `user-password.update` | Process reset |
| GET | `/otp/mfa/{method}` | `otp.mfa.create` | MFA verification |
| POST | `/otp/mfa` | `otp.mfa.store` | Verify MFA code |
| GET | `/oauth/{service}` | `oauth.redirect` | OAuth redirect |
| GET | `/oauth/{service}/callback` | `oauth.callback` | OAuth callback |
| GET | `/sso/redirect` | `sso.redirect` | Tenant SSO redirect |
| GET | `/sso/callback` | `sso.callback` | Tenant SSO callback |

### Authenticated Routes (neev:web middleware)

| Method | Route | Name | Description |
|--------|-------|------|-------------|
| GET | `/email/verify` | `verification.notice` | Verification pending |
| GET | `/email/send` | `email.verification.send` | Resend verification |
| GET | `/email/change` | `email.change` | Show change email form |
| PUT | `/email/change` | `email.update` | Request email change |
| POST | `/logout` | `logout` | Logout |

### Account Routes (/account prefix)

| Method | Route | Name | Description |
|--------|-------|------|-------------|
| GET | `/account/profile` | `account.profile` | Profile page |
| GET | `/account/security` | `account.security` | Security settings |
| GET | `/account/tokens` | `account.tokens` | API tokens |
| GET | `/account/teams` | `account.teams` | Teams list |
| GET | `/account/sessions` | `account.sessions` | Active sessions |
| GET | `/account/loginAttempts` | `account.loginAttempts` | Login history |
| PUT | `/account/profileUpdate` | `profile.update` | Update profile |
| POST | `/account/change-password` | `password.change` | Change password |
| POST | `/account/multiFactorAuth` | `multi.auth` | Add MFA |
| PUT | `/account/multiFactorAuth` | `multi.preferred` | Set preferred MFA |
| GET | `/account/recovery/codes` | `recovery.codes` | View recovery codes |
| POST | `/account/recovery/codes` | `recovery.generate` | Generate new codes |
| POST | `/account/passkeys/register/options` | `passkeys.register.options` | Passkey options |
| POST | `/account/passkeys/register` | `passkeys.register` | Register passkey |
| DELETE | `/account/passkeys` | `passkeys.delete` | Delete passkey |
| POST | `/account/logoutSessions` | `logout.sessions` | Logout other sessions |

### Team Routes (/teams prefix)

| Method | Route | Name | Description |
|--------|-------|------|-------------|
| GET | `/teams/create` | `teams.create` | Create team form |
| POST | `/teams/create` | `teams.store` | Store new team |
| GET | `/teams/{team}/profile` | `teams.profile` | Team profile |
| GET | `/teams/{team}/members` | `teams.members` | Team members |
| GET | `/teams/{team}/domain` | `teams.domain` | Domain settings |
| GET | `/teams/{team}/settings` | `teams.settings` | Team settings |
| PUT | `/teams/switch` | `teams.switch` | Switch team |
| PUT | `/teams/update` | `teams.update` | Update team |
| DELETE | `/teams/delete` | `teams.delete` | Delete team |
| PUT | `/teams/members/invite` | `teams.invite` | Invite member |
| PUT | `/teams/members/invite/action` | `teams.invite.action` | Accept/reject |
| DELETE | `/teams/members/leave` | `teams.leave` | Leave team |
| POST | `/teams/members/request` | `teams.request` | Request to join |
| PUT | `/teams/members/request/action` | `teams.request.action` | Accept/reject request |
| PUT | `/teams/owner/change` | `teams.owner.change` | Transfer ownership |
| PUT | `/teams/roles/change` | `teams.roles.change` | Change role |

---

## Multi-Factor Authentication

### Enable Authenticator App

```php
// Returns QR code and secret
$result = $user->addMultiFactorAuth('authenticator');
// $result['qr_code'] - SVG QR code
// $result['secret'] - TOTP secret
```

### Enable Email OTP

```php
$user->addMultiFactorAuth('email');
```

### Verify MFA

```php
if ($user->verifyMFAOTP('authenticator', '123456')) {
    // MFA verified
}
```

### Recovery Codes

```php
// Generate 8 recovery codes
$codes = $user->generateRecoveryCodes();
// Store these securely - shown only once!
```

### API Usage

```bash
# Enable MFA
curl -X POST https://yourapp.com/neev/mfa/add \
  -H "Authorization: Bearer {token}" \
  -d '{"auth_method": "authenticator"}'

# Verify MFA during login
curl -X POST https://yourapp.com/neev/mfa/otp/verify \
  -H "Authorization: Bearer {mfa_jwt_token}" \
  -d '{"auth_method": "authenticator", "otp": "123456"}'
```

---

## Team Management

### Create Team

```php
$team = Team::create([
    'name' => 'My Company',
    'user_id' => auth()->id(),
    'is_public' => false,
]);
```

### User's Teams

```php
$user->teams;          // Teams user belongs to
$user->ownedTeams;     // Teams user owns
$user->teamRequests;   // Pending invitations
$user->setDefaultTeam($team); // Set default team for next login
```

### Team Relationships

```php
$team->owner;          // Team owner
$team->users;          // Team members
$team->invitedUsers;   // Pending invitations
$team->joinRequests;   // Join requests
$team->domains;        // Federated domains
```

### API Usage

```bash
# Create team
curl -X POST https://yourapp.com/neev/teams \
  -H "Authorization: Bearer {token}" \
  -d '{"name": "My Team", "public": false}'

# Invite member
curl -X POST https://yourapp.com/neev/teams/inviteUser \
  -H "Authorization: Bearer {token}" \
  -d '{"team_id": 1, "email": "user@example.com", "role": "member"}'

# Accept invitation
curl -X PUT https://yourapp.com/neev/teams/inviteUser \
  -H "Authorization: Bearer {token}" \
  -d '{"team_id": 1, "action": "accept"}'
```

---

## Multi-Tenancy

### Enable Tenant Isolation

```php
// config/neev.php
'tenant' => true,  // isolate users per tenant
'team' => true,    // optional team sub-grouping
```

Tenant context is resolved on each request from the `X-Tenant` header or by looking up the request host in the `domains` table — subdomains and verified custom domains both work.

### Tenant SSO

Per-tenant authentication and SSO are stored per tenant/team (in the `tenant_auth_settings` / `team_auth_settings` tables) rather than in config. Manage them via Artisan:

```bash
php artisan neev:auth:configure   # Configure auth method / SSO for a tenant or team
php artisan neev:auth:show        # Show current auth settings
```

### Get Tenant Auth Config

```bash
curl -X GET https://acme.yourapp.com/api/tenant/auth
```

Response:
```json
{
  "auth_method": "sso",
  "sso_enabled": true,
  "sso_provider": "entra",
  "sso_redirect_url": "https://acme.yourapp.com/sso/redirect"
}
```

### Middleware Groups

| Middleware | Description |
|------------|-------------|
| `neev:web` | Web (session) authentication with tenant/team resolution |
| `neev:api` | API (token) authentication with tenant/team resolution |
| `neev:login` | Temporary MFA JWT authentication (during MFA verification) |
| `neev:tenant` | Tenant resolution from domain, tenant required (no auth) |

### Middleware Aliases

| Alias | Description |
|-------|-------------|
| `neev:active-team` | Blocks access when team is inactive/waitlisted |
| `neev:active-tenant` | Blocks access when tenant is inactive |
| `neev:tenant-member` | Ensures user is a member of the current tenant |
| `neev:resolve-team` | Resolves team from route parameter |
| `neev:ensure-sso` | Enforces SSO-only access for the current context |
| `neev:password-not-expired` | Forces password change when password has expired |
| `neev:verified-email` | Requires a verified email address |

---

## Security Features

### Brute Force Protection

Progressive exponential backoff — there is no hard lockout:

```php
// config/neev.php
'login_throttle' => [
    'delay_after' => 3,          // Failed attempts before delays kick in
    'max_delay_seconds' => 300,  // Exponential backoff caps here
],
```

### Password Policies

```php
// config/neev.php
'password' => [
    'required',
    'confirmed',
    Password::min(8)->max(72)->letters()->mixedCase()->numbers()->symbols(),
    PasswordHistory::notReused(5),
    PasswordUserData::notContain(['name', 'email']),
],
'password_expiry_days' => 90,  // 0 = disabled
```

Password expiry is enforced by applying the opt-in `neev:password-not-expired` middleware alias to your routes.

### Login Tracking

Each login attempt records:
- Login method (password, passkey, sso, etc.)
- MFA method used
- IP address and geolocation
- Browser, platform, device
- Success/failure status

### GeoIP Setup

```bash
# Get MaxMind license key from maxmind.com
# Add to .env:
MAXMIND_LICENSE_KEY=your-key

# Download database
php artisan neev:download-geoip
```

---

## Configuration

### Feature Toggles

```php
// config/neev.php
'tenant' => false,            // Multi-tenant isolation (users scoped to tenant)
'team' => false,              // Team management
'support_username' => false,  // Username login
```

Email verification is enforced by applying the opt-in `neev:verified-email` middleware alias to your routes.

### Post-Auth Redirect

```php
'home' => '/dashboard',  // Redirect after login (Blade flows)
```

### MFA

```php
'multi_factor_auth' => ['authenticator', 'email'],
'recovery_codes' => 8,
'otp_length' => 6,        // 4, 6, or 8 digits
'otp_expiry_time' => 15,  // minutes
```

### OAuth Providers

```php
'oauth' => [
    'google',
    'github',
    'microsoft',
    'apple',
],
```

### Token Expiry

```php
'login_token_expiry_minutes' => 1440,  // Login access tokens
'mfa_jwt_expiry_minutes' => 30,        // Temporary MFA JWTs
'url_expiry_time' => 60,               // Magic links, reset links
'otp_expiry_time' => 15,               // OTP codes
```

---

## Console Commands

### Setup

```bash
php artisan neev:install              # Interactive setup (asks: tenants? teams?)
php artisan neev:download-geoip       # Download GeoIP database
```

### Tenant & Team Management

```bash
php artisan neev:tenant:create        # Create a tenant or team
php artisan neev:tenant:list          # List tenants
php artisan neev:tenant:show          # Show tenant details
php artisan neev:team:activate        # Activate a waitlisted team
```

### Domains & Members

```bash
php artisan neev:domain:add           # Add a domain
php artisan neev:domain:verify        # Verify a domain
php artisan neev:domain:list          # List domains
php artisan neev:member:add           # Add a member
php artisan neev:member:remove        # Remove a member
php artisan neev:member:list          # List members
```

### Auth Settings

```bash
php artisan neev:auth:configure       # Configure tenant/team auth & SSO
php artisan neev:auth:show            # Show tenant/team auth settings
```

### Maintenance

```bash
php artisan neev:clean-login-attempts # Clean old login records
```

### Scheduled Tasks

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('neev:clean-login-attempts')->daily();
Schedule::command('neev:download-geoip')->monthly();
```

---

## Database Schema

### Core Tables

| Table | Description |
|-------|-------------|
| `users` | User accounts (includes password and password history) |
| `otp` | One-time passwords |
| `passkeys` | WebAuthn credentials |
| `multi_factor_auths` | MFA configurations |
| `recovery_codes` | MFA backup codes |
| `access_tokens` | API and login tokens |
| `login_attempts` | Login history |

### Team Tables

| Table | Description |
|-------|-------------|
| `teams` | Teams/organizations |
| `team_user` | Team-user membership pivot table |
| `team_invitations` | Pending invitations |
| `domains` | Email domain federation |
| `domain_rules` | Domain security rules |

### Tenant Tables

| Table | Description |
|-------|-------------|
| `tenants` | Tenant organizations (isolated identity mode) |
| `domains` | Custom tenant domains and domain federation |
| `team_auth_settings` | Per-team auth/SSO config |
| `tenant_auth_settings` | Per-tenant auth/SSO config (isolated mode) |

---

## Detailed Documentation

For comprehensive documentation, see the [docs folder](./docs/):

| Document | Description |
|----------|-------------|
| [Installation](./docs/installation.md) | Complete setup guide |
| [Configuration](./docs/configuration.md) | All configuration options |
| [Authentication](./docs/authentication.md) | Auth flows and methods |
| [API Reference](./docs/api-reference.md) | Complete API documentation |
| [Web Routes](./docs/web-routes.md) | All web route details |
| [MFA](./docs/mfa.md) | Multi-factor authentication |
| [Teams](./docs/teams.md) | Team management guide |
| [Multi-Tenancy](./docs/multi-tenancy.md) | SaaS multi-tenant setup |
| [Security](./docs/security.md) | Security features & best practices |
| [Architecture](./docs/architecture.md) | Identity strategy, tenancy & team design |
| [Architecture Internals](./docs/architecture-internals.md) | Interfaces, patterns & coding standards |

---

## Requirements

- PHP 8.3+
- Laravel 12.x
- MySQL, PostgreSQL, or SQLite

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for setup instructions, coding standards, and how to submit pull requests.

Report security vulnerabilities following the process in [SECURITY.md](SECURITY.md).

---

## License

MIT License. See [LICENSE](LICENSE) for details.

---

## Credits

Created and maintained by [Abhishek Sharma](https://ssntpl.com) at [SSNTPL](https://ssntpl.com).

---

**Ready to get started?** Run `composer require ssntpl/neev` and `php artisan neev:install`!
