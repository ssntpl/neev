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
- Invite members via email with role assignment
- Role-based access control with Laravel ACL integration
- Domain-based auto-joining (federation) with DNS verification
- Team switching for multi-team users
- Public/private team visibility
- Team activation/deactivation with reasons

### Security Features
- **Progressive rate limiting** with configurable thresholds
- **Account lockout** after failed attempts with time-based recovery
- **Password history** to prevent reuse (configurable depth)
- **Password expiry policies** (soft warnings + hard expiry)
- **Login attempt tracking** with GeoIP location data
- **Session management** with device tracking
- **Suspicious login detection** and flagging
- **Email verification** with configurable methods (link or OTP)

### Multi-Tenancy & Identity Strategy
- **Shared identity** (users global, teams as collaboration) or **Isolated identity** (users scoped to tenant)
- **Subdomain-based tenant isolation** with custom domain support
- **DNS verification** for custom domains
- **Per-tenant authentication configuration**
- **Per-tenant SSO integration** with auto-provisioning
- **Context-aware middleware** for tenant/team resolution

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
# Optional - MaxMind license for GeoIP tracking
MAXMIND_LICENSE_KEY="your-maxmind-key"
MAXMIND_EDITION="GeoLite2-City"
```

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
    protected $fillable = [
        'name',
        'username', // if username support enabled
        'active',
        // Add your custom fields
    ];
    
    // Add your custom relationships and methods
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
    "password_confirmation": "SecurePass123!",
    "username": "johndoe"  // Optional, if username support enabled
  }'

# Login
curl -X POST https://yourapp.com/neev/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "john@example.com",
    "password": "SecurePass123!"
  }'
```

**Response (no MFA):**

```json
{
  "status": "Success",
  "token": "1|abc123def456...",
  "email_verified": true,
  "preferred_mfa": null
}
```

**Response (with MFA):**

```json
{
  "status": "Success",
  "token": "1|abc123def456...",
  "email_verified": true,
  "preferred_mfa": "authenticator"
}
```

When MFA is required, complete verification:

```bash
curl -X POST https://yourapp.com/neev/mfa/otp/verify \
  -H "Authorization: Bearer {mfa_token}" \
  -d '{"auth_method": "authenticator", "otp": "123456"}'
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
  -d '{"attestation": "{...webauthn_response...}", "name": "My MacBook"}'

# Get login options
curl -X POST https://yourapp.com/neev/passkeys/login/options \
  -d '{"email": "john@example.com"}'

# Login with passkey
curl -X POST https://yourapp.com/neev/passkeys/login \
  -d '{"email": "john@example.com", "assertion": "{...webauthn_assertion...}"}'
```

### OAuth / Social Login

Enable in configuration:

```php
// config/neev.php
'oauth' => ['google', 'github', 'microsoft', 'apple'],
```

Configure credentials in `config/services.php`:

```php
'google' => [
    'client_id' => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'redirect' => env('GOOGLE_REDIRECT_URI'),
],
```

Redirect URLs:
- `GET /oauth/{provider}` - Redirect to provider
- `GET /oauth/{provider}/callback` - Handle callback

**Note**: OAuth bypasses MFA requirements and password policies.

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
| POST | `/neev/forgotPassword` | Reset password with OTP | No |

### Email Endpoints

| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| POST | `/neev/email/send` | Send verification email | Yes |
| GET | `/neev/email/verify` | Verify email address | Yes |
| POST | `/neev/email/update` | Update email address | Yes |
| POST | `/neev/email/otp/send` | Send email OTP | No |
| POST | `/neev/email/otp/verify` | Verify email OTP | No |
| POST | `/neev/emails` | Add email address | Yes |
| DELETE | `/neev/emails` | Delete email address | Yes |
| PUT | `/neev/emails` | Set primary email | Yes |

### MFA Endpoints

| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| POST | `/neev/mfa/add` | Enable MFA method | Yes |
| DELETE | `/neev/mfa/delete` | Disable MFA method | Yes |
| POST | `/neev/mfa/otp/verify` | Verify MFA code | MFA Token |
| POST | `/neev/recoveryCodes` | Generate recovery codes | Yes |

### Passkey Endpoints

| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| GET | `/neev/passkeys` | List user's passkeys | Yes |
| GET | `/neev/passkeys/register/options` | Get registration options | Yes |
| POST | `/neev/passkeys/register` | Register passkey | Yes |
| POST | `/neev/passkeys/login/options` | Get login options | No |
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
| GET | `/neev/tenant-domains/{id}` | Get domain details | Yes |
| DELETE | `/neev/tenant-domains/{id}` | Delete domain | Yes |
| POST | `/neev/tenant-domains/{id}/verify` | Verify domain | Yes |
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
| GET | `/email/change` | `email.change` | Change email form |
| PUT | `/email/change` | `email.update` | Process email change |
| POST | `/logout` | `logout` | Logout |

### Account Routes (/account prefix)

| Method | Route | Name | Description |
|--------|-------|------|-------------|
| GET | `/account/profile` | `account.profile` | Profile page |
| GET | `/account/emails` | `account.emails` | Email management |
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
  -H "Authorization: Bearer {mfa_token}" \
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
$user->switchTeam($team); // Switch active team
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

### Identity Strategy

```php
// config/neev.php
'tenant' => false,                 // Enable tenant isolation
```

**Shared Identity (Default)**:
- Users are global across the application
- Same user can belong to multiple teams
- Teams are collaboration units

**Isolated Identity**:
- Users are scoped to tenants
- Same email can exist in different tenants
- Tenant resolved before authentication

### Tenant Isolation

```php
// config/neev.php
'tenant' => true,
```

Enables:
- Subdomain-based tenant routing (`acme.yourapp.com`)
- Custom domain support with DNS verification
- Per-tenant authentication configuration
- Automatic model scoping via `BelongsToTenant` trait

### Tenant SSO

```php
// Configure per-tenant SSO
$team->authSettings()->create([
    'auth_method' => 'sso',
    'sso_provider' => 'entra',
    'sso_client_id' => 'your-client-id',
    'sso_client_secret' => encrypt('your-secret'),
    'auto_provision' => true,
]);
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

These are pre-composed groups you apply to route groups.

| Group | Pipeline |
|-------|----------|
| `neev:web` | TenantMiddleware → ResolveTeamMiddleware → NeevMiddleware (session auth) → EnsureTenantMembership → BindContextMiddleware |
| `neev:api` | TenantMiddleware → ResolveTeamMiddleware → NeevAPIMiddleware (token auth) → EnsureTenantMembership → BindContextMiddleware |
| `neev:tenant` | TenantMiddleware → ResolveTeamMiddleware → BindContextMiddleware |

### Middleware Aliases

These can be applied individually to specific routes.

| Alias | Description |
|-------|-------------|
| `neev:active-team` | Blocks access when team is inactive/waitlisted |
| `neev:tenant-member` | Ensures user is a member of the current tenant |
| `neev:resolve-team` | Resolves team from route parameter (slug or ID) |
| `neev:ensure-sso` | Enforces SSO-only access for the current context |

### Usage

```php
// Apply middleware group
Route::middleware(['neev:web'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
});

// Apply individual aliases after the group
Route::middleware(['neev:web', 'neev:active-team'])->group(function () {
    Route::get('/projects', [ProjectController::class, 'index']);
});
```

---

## Security Features

### Rate Limiting

```php
// config/neev.php
'login_throttle' => [
    'delay_after' => 3,            // Progressive delays start after 3 attempts
    'max_delay_seconds' => 300,    // Maximum delay (5 minutes)
],
```

**Behavior**:
1. Attempts 1-3: Normal speed
2. Attempts 4+: Exponential backoff delays
3. After max delay: Account temporarily locked

### Password Policies

```php
// config/neev.php
'password' => [
    'required',
    'confirmed',
    Password::min(8)->max(72)->letters()->mixedCase()->numbers()->symbols(),
    PasswordHistory::notReused(5),     // Cannot reuse last 5 passwords
    PasswordUserData::notContain(['name', 'email']), // No personal data
],
'password_expiry_days' => 90,          // Force change after 90 days
```

### Login Tracking

Each login attempt records:
- Login method (password, passkey, sso, oauth, magic_auth)
- MFA method used (if any)
- IP address and GeoIP location
- Browser, platform, device information
- Success/failure status and suspicious activity flags

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
'tenant' => false,                 // Multi-tenant isolation (users scoped to tenant)
'team' => false,                   // Team management
'support_username' => false,       // Username login support
'oauth' => [],                     // OAuth providers: ['google', 'github', 'microsoft', 'apple']
'multi_factor_auth' => ['authenticator', 'email'], // Available MFA methods
'email_verification_method' => 'link', // 'link' or 'otp'
'otp_length' => 6,                 // OTP code length (4, 6, or 8)
```

### Rate Limiting & Security

```php
// config/neev.php
'login_throttle' => [
    'delay_after' => 3,            // Failed attempts before progressive delay
    'max_delay_seconds' => 300,    // Maximum delay (5 minutes)
],
'log_failed_logins' => false,      // Store in cache (true = database)
'login_history_retention_days' => 30, // Days to retain login records
```

### Password Policies

```php
// config/neev.php
'password_expiry_days' => 90,      // Days until password expires (0 = disabled)
'password' => [
    'required',
    'confirmed',
    Password::min(8)->max(72)->letters()->mixedCase()->numbers()->symbols(),
    PasswordHistory::notReused(5), // Cannot reuse last 5 passwords
    PasswordUserData::notContain(['name', 'email']), // No personal data
],
```

### URLs

```php
'home' => '/dashboard',
```

Post-authentication redirect URL for Blade flows.

### MFA

```php
'multi_factor_auth' => ['authenticator', 'email'],
'recovery_codes' => 8,
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
'url_expiry_time' => 60,  // Magic links, reset links
'otp_expiry_time' => 15,  // OTP codes
```

---

## Console Commands

### Setup & Maintenance

```bash
php artisan neev:install              # Interactive setup wizard
php artisan neev:download-geoip       # Download GeoIP database
php artisan neev:clean-login-attempts # Clean old login records
php artisan neev:clean-passwords      # Clean password history
```

### Tenant/Team Management

```bash
php artisan neev:tenant:create "Acme Corp" --owner=admin@acme.com
php artisan neev:tenant:list --search=acme --json
php artisan neev:tenant:show acme-corp
php artisan neev:member:add user@example.com --team=acme-corp --role=editor
php artisan neev:member:list --team=acme-corp
```

### Domain Management

```bash
php artisan neev:domain:add app.acme.com --team=acme-corp --primary
php artisan neev:domain:verify app.acme.com
php artisan neev:domain:list --unverified
```

### Auth Configuration

```bash
php artisan neev:auth:configure --team=acme-corp --method=sso --sso-provider=entra
php artisan neev:auth:show --team=acme-corp --reveal
```

### Scheduled Tasks

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    $schedule->command('neev:clean-login-attempts')->daily();
    $schedule->command('neev:clean-passwords')->weekly();
    $schedule->command('neev:download-geoip')->monthly();
}
```

---

## Database Schema

### Core Tables

| Table | Description |
|-------|-------------|
| `users` | User accounts with optional `tenant_id` for isolation |
| `emails` | Multiple emails per user with tenant scoping |
| `passwords` | Password history for reuse prevention |
| `otps` | One-time passwords (email verification, MFA) |
| `passkeys` | WebAuthn credentials |
| `multi_factor_auths` | MFA method configurations |
| `recovery_codes` | MFA backup codes (hashed) |
| `access_tokens` | API and login tokens with tracking |
| `login_attempts` | Login history with GeoIP data |

### Team Tables

| Table | Description |
|-------|-------------|
| `teams` | Teams/organizations with activation status |
| `memberships` | Team-user relationships (replaces `team_user`) |
| `team_invitations` | Email-based team invitations |
| `domains` | Email domain federation and custom domains |
| `team_auth_settings` | Per-team SSO configuration |

### Tenant Tables (Multi-Tenancy)

| Table | Description |
|-------|-------------|
| `tenants` | Tenant organizations with hierarchy support |
| `tenant_auth_settings` | Per-tenant SSO configuration |

### Key Features

- **Tenant isolation**: `tenant_id` foreign keys with global scopes
- **Performance indexes**: Optimized queries for large datasets
- **Polymorphic domains**: Support both team and tenant ownership
- **Encrypted secrets**: SSO credentials stored securely

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

- **PHP 8.3+** (uses modern PHP features)
- **Laravel 11.x or 12.x** (dropped Laravel 10 support)
- **Database**: MySQL, PostgreSQL, or SQLite
- **Optional**: Redis/Memcached for distributed rate limiting
- **Optional**: MaxMind GeoLite2 license for IP geolocation

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
