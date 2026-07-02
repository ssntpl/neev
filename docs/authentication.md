# Authentication Guide

Comprehensive guide to Neev's authentication system, covering all supported methods and flows.

---

## Authentication Methods

Neev supports multiple authentication methods:

| Method | Description | Configuration |
|--------|-------------|---------------|
| Password | Traditional email/password login | Always available |
| Magic Link | Passwordless via email | Always available |
| Passkey/WebAuthn | Biometric or hardware key | Always available |
| OAuth | Social login (Google, GitHub, etc.) | `oauth` config |
| Tenant SSO | Enterprise SSO (Entra ID, Okta) | Per-tenant/per-team auth settings in DB |

---

## Password Authentication

### Registration Flow

1. User submits registration form with name, email, password
2. System validates password against rules
3. User account is created with email (unverified)
4. If teams enabled, personal team is created (skipped for invitation-link signups and verified federated domains)
5. Verification email is sent
6. User is logged in and redirected

**API Example:**

```bash
curl -X POST https://yourapp.com/neev/register \
  -H "Content-Type: application/json" \
  -d '{
    "name": "John Doe",
    "email": "john@example.com",
    "password": "SecurePass123!",
    "password_confirmation": "SecurePass123!"
  }'
```

**Response:**

```json
{
  "auth_state": "authenticated",
  "token": "1|abc123def456...",
  "expires_in": 1440,
  "mfa_options": null,
  "email_verified": false
}
```

### Login Flow

1. User enters email/username
2. System checks if user exists
3. User enters password
4. System validates password
5. If MFA enabled, redirect to MFA verification
6. User is logged in and redirected

**API Example:**

```bash
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
  "auth_state": "authenticated",
  "token": "1|abc123def456...",
  "expires_in": 1440,
  "mfa_options": null,
  "email_verified": true
}
```

**Response (with MFA):**

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

When MFA is required, the returned token is a short-lived JWT (type `mfa`, expiry set by `mfa_jwt_expiry_minutes`, default 30). Send it as a Bearer token to the verification endpoint (protected by the `neev:login` middleware group, which authenticates the MFA JWT):

```bash
curl -X POST https://yourapp.com/neev/mfa/otp/verify \
  -H "Authorization: Bearer jwt_mfa_token..." \
  -H "Content-Type: application/json" \
  -d '{
    "auth_method": "authenticator",
    "otp": "123456"
  }'
```

On success, the endpoint returns `auth_state: "authenticated"` with a regular access token in the `{id}|{plaintext}` format. `expires_in` is returned in minutes. Accepted `auth_method` values are the user's configured MFA methods (`authenticator`, `email`) or `recovery` for recovery codes.

---

## Password Policies

### Strength Requirements

Default password rules (configurable in `config/neev.php`):

- Minimum 8 characters
- Maximum 72 characters (bcrypt limit)
- Must contain letters
- Must contain uppercase and lowercase
- Must contain numbers
- Must contain special characters

### Password History

Prevents reusing recent passwords:

```php
// config/neev.php
PasswordHistory::notReused(5)  // Cannot reuse last 5 passwords
```

### Personal Data Prevention

Prevents using personal information in passwords:

```php
// config/neev.php
PasswordUserData::notContain(['name', 'email'])
```

### Password Expiry

Configure password aging:

```php
// config/neev.php
'password_expiry_days' => 90,  // Days before password expires. 0 = disabled.
```

Enforcement is opt-in: apply the `neev:password-not-expired` middleware alias (`EnsurePasswordNotExpired`) to routes that should reject users with expired passwords. Helpers are available on the user: `passwordExpiresAt()`, `isPasswordExpired()`, `isPasswordExpiringSoon()`.

---

## Magic Link Authentication

Passwordless login via secure email links. Always available — no config toggle.

### Flow

1. User enters email on login page
2. Clicks "Send Login Link"
3. Receives email with secure link
4. Clicks link to authenticate
5. Automatically logged in

### API Example

**Request Link:**

```bash
curl -X POST https://yourapp.com/neev/sendLoginLink \
  -H "Content-Type: application/json" \
  -d '{"email": "john@example.com"}'
```

**Use Link:**

```bash
curl -X GET "https://yourapp.com/neev/loginUsingLink?id=1&signature=abc123&expires=1234567890"
```

### Link Expiry

Configure in `config/neev.php`:

```php
'url_expiry_time' => 60,  // Minutes
```

---

## Passkey / WebAuthn Authentication

Biometric authentication using fingerprints, face recognition, or hardware security keys.

### Configuration

Configured in `config/neev.php`:

```php
// Relying Party ID — the domain passkeys are bound to. Defaults to the
// host parsed from APP_URL (e.g. "example.com").
'relying_party_id' => parse_url(config('app.url'), PHP_URL_HOST),

// Origins permitted to complete WebAuthn ceremonies. Add every origin
// (including subdomains/alternate hosts) the browser may report. The
// origin must be reachable on the relying party ID above.
'allowed_origins' => [
    config('app.url'),
],
```

For multi-origin setups (e.g. apex domain plus subdomains, or staging plus production), list every allowed origin explicitly:

```php
'allowed_origins' => [
    'https://app.example.com',
    'https://admin.example.com',
],
```

### Registration Flow

1. User authenticates with password
2. Goes to Security settings
3. Clicks "Add Passkey"
4. Browser prompts for biometric/key
5. Passkey is registered and stored

### Login Flow

1. User enters email
2. Sees "Login with Passkey" option
3. Browser prompts for biometric/key
4. Authenticated immediately

### API Example

**Generate Registration Options:**

```bash
curl -X GET https://yourapp.com/neev/passkeys/register/options \
  -H "Authorization: Bearer {token}"
```

**Register Passkey:**

```bash
curl -X POST https://yourapp.com/neev/passkeys/register \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "attestation": "{...webauthn_response...}",
    "name": "MacBook Pro"
  }'
```

**Login with Passkey:**

```bash
# Get options
curl -X POST https://yourapp.com/neev/passkeys/login/options \
  -d '{"email": "john@example.com"}'

# Authenticate
curl -X POST https://yourapp.com/neev/passkeys/login \
  -d '{
    "email": "john@example.com",
    "assertion": "{...webauthn_assertion...}"
  }'
```

### JavaScript Example

```javascript
// Register Passkey
async function registerPasskey() {
  // Get options from server
  const optionsRes = await fetch('/neev/passkeys/register/options', {
    headers: { 'Authorization': `Bearer ${token}` }
  });
  const options = await optionsRes.json();

  // Decode challenge
  options.challenge = base64UrlDecode(options.challenge);
  options.user.id = base64UrlDecode(options.user.id);

  // Create credential
  const credential = await navigator.credentials.create({
    publicKey: options
  });

  // Send to server
  await fetch('/neev/passkeys/register', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      attestation: JSON.stringify({
        id: credential.id,
        rawId: base64UrlEncode(credential.rawId),
        type: credential.type,
        response: {
          clientDataJSON: base64UrlEncode(credential.response.clientDataJSON),
          attestationObject: base64UrlEncode(credential.response.attestationObject)
        },
        challenge: options.challenge
      }),
      name: 'My Device'
    })
  });
}
```

---

## OAuth / Social Login

Authenticate via third-party providers.

### Available Providers

- Google
- GitHub
- Microsoft
- Apple

### Configuration

1. Enable providers in `config/neev.php`:

```php
'oauth' => [
    'google',
    'github',
],
```

2. Configure credentials in `config/services.php`:

```php
'google' => [
    'client_id' => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'redirect' => env('GOOGLE_REDIRECT_URI'),
],
```

3. Set environment variables:

```env
GOOGLE_CLIENT_ID=your-client-id
GOOGLE_CLIENT_SECRET=your-client-secret
GOOGLE_REDIRECT_URI="${APP_URL}/oauth/google/callback"
```

### Security Note

OAuth and Social login bypasses Neev's MFA requirements and password policies. Users authenticated via OAuth skip the MFA verification step and are not subject to password expiry or complexity rules. If your application requires MFA for all users, consider implementing a post-OAuth MFA check in your application logic, or restrict OAuth to low-sensitivity accounts.

### Flow

1. User clicks "Login with Google"
2. Redirected to Google's consent page
3. User authorizes the application
4. Redirected back with auth code
5. System exchanges code for user info
6. User is created or matched
7. Logged in and redirected (MFA is skipped)

### URLs

- **Redirect:** `GET /oauth/{provider}`
- **Callback:** `GET /oauth/{provider}/callback`

---

## Tenant SSO (Enterprise)

Per-tenant identity provider configuration. There is no config toggle — SSO settings live in the database (`tenant_auth_settings` for tenants, `team_auth_settings` for teams) and default to password auth when no row exists.

### Supported Providers

- **entra** - Microsoft Entra ID (Azure AD)
- **google** - Google Workspace
- **okta** - Okta Identity

### Tenant Configuration

Configure SSO for a tenant (or team) via CLI:

```bash
php artisan neev:auth:configure --tenant=acme --method=sso \
    --sso-provider=entra --sso-client-id=... --sso-client-secret=... --sso-tenant-id=...
```

Or in code:

```php
$tenant->authSettings()->create([
    'auth_method' => 'sso',
    'sso_provider' => 'entra',
    'sso_client_id' => 'your-client-id',
    'sso_client_secret' => 'your-client-secret',  // encrypted automatically via cast
    'sso_tenant_id' => 'your-azure-tenant-id',
    'auto_provision' => true,
    'auto_provision_role' => 'member',
]);
```

### Flow

1. User accesses tenant URL (e.g., `acme.yourapp.com`)
2. System detects tenant requires SSO
3. User redirected to identity provider
4. User authenticates with corporate credentials
5. Redirected back with auth token
6. User is matched or auto-provisioned
7. Logged into tenant

### API Endpoint

```http
GET /api/tenant/auth
```

Returns tenant auth configuration:

```json
{
  "auth_method": "sso",
  "sso_enabled": true,
  "sso_provider": "entra",
  "sso_redirect_url": "https://acme.yourapp.com/sso/redirect"
}
```

---

## Email Verification

### Enforcing Verification

Verification emails are always sent on registration. Enforcement is opt-in: apply the `neev:verified-email` middleware alias (`EnsureEmailIsVerified`) to routes that should require a verified email — there is no config toggle.

```php
Route::middleware(['neev:api', 'neev:verified-email'])->group(function () {
    // Routes that require a verified email
});
```

### Flow

1. User registers or changes email
2. Verification email is sent automatically
3. User clicks verification link
4. Email is marked as verified
5. User can access routes protected by `neev:verified-email`

### Resend Verification

**Web:**

```http
GET /email/send
```

**API:**

```http
POST /neev/email/send
Authorization: Bearer {token}
```

### Change Email

```http
PUT /email/change
Content-Type: application/x-www-form-urlencoded

email=newemail@example.com
```

---

## Session Management

### View Active Sessions

```http
GET /neev/sessions
Authorization: Bearer {token}
```

Returns:

```json
{
  "data": [
    {
      "id": 1,
      "last_used_at": "2024-01-15T10:00:00Z",
      "attempt": {
        "ip_address": "192.168.1.1",
        "browser": "Chrome",
        "platform": "macOS",
        "location": "San Francisco, CA"
      }
    }
  ]
}
```

### Logout Current Session

```http
POST /neev/logout
Authorization: Bearer {token}
```

### Logout All Other Sessions

Deletes all of the user's login tokens except the current one:

```http
POST /neev/logoutAll
Authorization: Bearer {token}
```

### Logout Specific Session (Web)

```http
POST /account/logoutSessions
Content-Type: application/x-www-form-urlencoded

session_id=abc123
```

---

## Login Tracking

### Tracked Information

For each login attempt, Neev records:

| Field | Description |
|-------|-------------|
| `method` | Login method used (password, passkey, sso, etc.) |
| `multi_factor_method` | MFA method used (if any) |
| `ip_address` | User's IP address |
| `platform` | Operating system |
| `browser` | Browser name |
| `device` | Device type |
| `location` | City, country (via GeoIP) |
| `is_success` | Whether login succeeded |
| `is_suspicious` | Flagged as suspicious |

### Login Methods

| Constant | Value | Description |
|----------|-------|-------------|
| `LoginAttempt::Password` | `password` | Password authentication |
| `LoginAttempt::Passkey` | `passkey` | WebAuthn/passkey |
| `LoginAttempt::MagicAuth` | `magic auth` | Magic link |
| `LoginAttempt::OAuth` | `oauth` | Social login |
| `LoginAttempt::SSO` | `sso` | Tenant SSO |

### View Login History

```http
GET /neev/loginAttempts
Authorization: Bearer {token}
```

---

## Brute Force Protection

### Configuration

```php
// config/neev.php
'login_throttle' => [
    'delay_after' => 3,          // Failed attempts before progressive delay kicks in
    'max_delay_seconds' => 300,  // Maximum delay (exponential backoff caps here)
],
```

### Behavior

Progressive delay instead of a hard lockout:

1. **Attempts 1-2:** Normal login speed
2. **Attempt 3 onwards:** Exponential backoff between attempts (`2^(attempts - delay_after)` seconds), capped at `max_delay_seconds` (default 5 minutes)

Delays are keyed per email + IP. A successful login clears the counter.

### Storage

```php
// config/neev.php
'log_failed_logins' => false,  // Failed attempts tracked in cache only
'log_failed_logins' => true,   // Also record failed attempts in the database
```

---

## Events

### LoggedIn

Fired when a user successfully logs in.

```php
use Ssntpl\Neev\Events\LoggedIn;

class LogSuccessfulLogin
{
    public function handle(LoggedIn $event)
    {
        $user = $event->user;
        // Log activity, send notification, etc.
    }
}
```

### LoggedOut

Fired when a user logs out.

```php
use Ssntpl\Neev\Events\LoggedOut;

class LogSuccessfulLogout
{
    public function handle(LoggedOut $event)
    {
        $user = $event->user;
        // Cleanup, audit logging, etc.
    }
}
```

---

## Security Best Practices

1. **Enable MFA** for all users, especially administrators
2. **Use HTTPS** in production
3. **Apply `neev:password-not-expired` middleware** if password aging is a compliance requirement
4. **Apply `neev:verified-email` middleware** to prevent unverified accounts from accessing sensitive routes
5. **Monitor login attempts** for suspicious activity
6. **Use session database** driver for logout-all-devices functionality
7. **Keep GeoIP database** updated for accurate location tracking
8. **Be aware that OAuth bypasses MFA** — consider additional checks for OAuth users if MFA is a compliance requirement

---

## Next Steps

- [Multi-Factor Authentication](./mfa.md)
- [Team Management](./teams.md)
- [API Reference](./api-reference.md)
