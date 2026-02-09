# Multi-Factor Authentication (MFA)

Complete guide to implementing and managing multi-factor authentication with Neev.

---

## Overview

Neev supports multiple MFA methods that provide an extra layer of security beyond passwords:

| Method | Description | Security Level |
|--------|-------------|----------------|
| Authenticator | TOTP via apps (Google Authenticator, Authy) | Highest |
| Email OTP | 6-digit code sent to email | Medium |
| Recovery Codes | Single-use backup codes | Emergency only |

---

## Configuration

### Enable MFA Methods

```php
// config/neev.php
'multi_factor_auth' => [
    'authenticator',  // TOTP via authenticator apps
    'email',          // OTP via email delivery
],
```

### Recovery Codes

```php
// config/neev.php
'recovery_codes' => 8,  // Number of codes generated
```

### OTP Settings

```php
// config/neev.php
'otp_expiry_time' => 15,  // Minutes before OTP expires
'otp_min' => 100000,      // Minimum OTP value (6 digits)
'otp_max' => 999999,      // Maximum OTP value (6 digits)
```

---

## Authenticator Apps (TOTP)

Time-based One-Time Password using apps like Google Authenticator, Authy, 1Password.

### Setup Flow

1. User navigates to Security settings
2. Clicks "Enable Authenticator"
3. Scans QR code with authenticator app
4. Enters verification code
5. MFA is enabled

### API: Enable Authenticator

```bash
curl -X POST https://yourapp.com/neev/mfa/add \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"auth_method": "authenticator"}'
```

**Response:**

```json
{
  "qr_code": "<svg xmlns=\"http://www.w3.org/2000/svg\">...</svg>",
  "secret": "JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP",
  "method": "authenticator"
}
```

### Display QR Code

```html
<!-- In your view -->
<div class="qr-code">
    {!! $response['qr_code'] !!}
</div>
<p>Or enter manually: {{ $response['secret'] }}</p>
```

### Verify Setup

Before enabling, verify the user can generate valid codes:

```bash
curl -X POST https://yourapp.com/neev/mfa/otp/verify \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "auth_method": "authenticator",
    "otp": "123456"
  }'
```

### How TOTP Works

1. Shared secret is stored on device and server
2. Both generate codes using current time + secret
3. Codes are valid for 30 seconds
4. 29-second leeway allows for clock drift

### Supported Apps

- Google Authenticator (Android, iOS)
- Authy (Android, iOS, Desktop)
- Microsoft Authenticator (Android, iOS)
- 1Password (all platforms)
- Bitwarden (all platforms)

---

## Email OTP

6-digit codes sent to the user's verified email.

### Setup Flow

1. User navigates to Security settings
2. Clicks "Enable Email OTP"
3. Receives confirmation email with code
4. MFA is enabled

### API: Enable Email OTP

```bash
curl -X POST https://yourapp.com/neev/mfa/add \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"auth_method": "email"}'
```

**Response:**

```json
{
  "message": "Email Configured."
}
```

### Send OTP

```bash
curl -X POST https://yourapp.com/neev/email/otp/send \
  -H "Content-Type: application/json" \
  -d '{
    "email": "john@example.com",
    "mfa": true
  }'
```

### Verify OTP

```bash
curl -X POST https://yourapp.com/neev/mfa/otp/verify \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "auth_method": "email",
    "otp": "123456"
  }'
```

---

## Recovery Codes

Single-use backup codes for when primary MFA is unavailable.

### Generate Recovery Codes

```bash
curl -X POST https://yourapp.com/neev/recoveryCodes \
  -H "Authorization: Bearer {token}"
```

**Response:**

```json
{
  "status": "Success",
  "message": "New recovery codes are generated.",
  "data": [
    "abc123defg",
    "hij456klmn",
    "opq789rstu",
    "vwx012yzab",
    "cde345fghi",
    "jkl678mnop",
    "qrs901tuvw",
    "xyz234abcd"
  ]
}
```

### Using Recovery Codes

```bash
curl -X POST https://yourapp.com/neev/mfa/otp/verify \
  -H "Authorization: Bearer {mfa_token}" \
  -H "Content-Type: application/json" \
  -d '{
    "auth_method": "recovery",
    "otp": "abc123defg"
  }'
```

### Important Notes

- Codes are **single-use** - each code works only once
- Codes are stored **hashed** - cannot be recovered if lost
- Show codes **only once** - prompt user to save them
- Regenerating codes **invalidates** all previous codes

### Best Practices

```html
<!-- Show recovery codes after generation -->
<div class="recovery-codes">
  <h3>Save these recovery codes</h3>
  <p class="warning">
    These codes will only be shown once. Store them securely.
  </p>
  <ul>
    @foreach($codes as $code)
      <li><code>{{ $code }}</code></li>
    @endforeach
  </ul>
  <button onclick="downloadCodes()">Download as text file</button>
  <button onclick="window.print()">Print codes</button>
</div>
```

---

## MFA During Login

### Login Flow with MFA

1. User enters email and password
2. Credentials are validated
3. MFA token is returned instead of full token
4. User is redirected to MFA verification
5. User enters TOTP code or email OTP
6. Full access token is returned

### Web Flow

```php
// After successful password verification
if (count($user->multiFactorAuths) > 0) {
    session(['email' => $email]);
    return redirect(route('otp.mfa.create',
        $user->preferredMultiAuth?->method ??
        $user->multiFactorAuths()->first()?->method
    ));
}
```

### API Flow

**Step 1: Login**

```bash
curl -X POST https://yourapp.com/neev/login \
  -d '{"email": "john@example.com", "password": "password"}'
```

Response includes `preferred_mfa`:

```json
{
  "status": "Success",
  "token": "1|mfa_token...",
  "preferred_mfa": "authenticator"
}
```

**Step 2: Verify MFA**

```bash
curl -X POST https://yourapp.com/neev/mfa/otp/verify \
  -H "Authorization: Bearer 1|mfa_token..." \
  -d '{"auth_method": "authenticator", "otp": "123456"}'
```

Response with full token:

```json
{
  "status": "Success",
  "token": "1|full_access_token...",
  "email_verified": true
}
```

---

## Preferred MFA Method

Users with multiple MFA methods can set a preferred one.

### Set Preferred Method

```bash
# Via API (not directly exposed, use web route)
PUT /account/multiFactorAuth
Content-Type: application/json

{"method": "authenticator"}
```

### How It Works

- When logging in, the preferred method is used first
- Other methods are available as fallbacks
- If preferred method is deleted, another becomes preferred

---

## Removing MFA

### Delete MFA Method

```bash
curl -X DELETE https://yourapp.com/neev/mfa/delete \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"auth_method": "authenticator"}'
```

### Behavior

- If deleting the preferred method, another method becomes preferred
- If deleting the last MFA method, recovery codes are also deleted
- Users can re-enable MFA at any time

---

## User Model Trait

MFA functionality is provided by the `HasMultiAuth` trait:

```php
use Ssntpl\Neev\Traits\HasMultiAuth;

class User extends Authenticatable
{
    use HasMultiAuth;
}
```

### Available Methods

```php
// Get all MFA configurations
$user->multiFactorAuths();

// Get specific MFA method
$user->multiFactorAuth('authenticator');

// Get preferred MFA method
$user->preferredMultiFactorAuth();

// Add MFA method (returns QR code for authenticator)
$user->addMultiFactorAuth('authenticator');

// Verify OTP code
$user->verifyMFAOTP('authenticator', '123456');

// Get recovery codes
$user->recoveryCodes();

// Generate new recovery codes
$user->generateRecoveryCodes();
```

---

## Database Schema

### multi_factor_auths Table

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| user_id | bigint | Foreign key to users |
| method | string | MFA method name |
| secret | string | TOTP secret (encrypted) |
| preferred | boolean | Is this the preferred method |
| otp | string | Current email OTP (if applicable) |
| expires_at | timestamp | OTP expiry time |
| last_used | timestamp | Last successful verification |
| created_at | timestamp | Creation time |
| updated_at | timestamp | Last update time |

### recovery_codes Table

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| user_id | bigint | Foreign key to users |
| code | string | Hashed recovery code |
| created_at | timestamp | Creation time |
| updated_at | timestamp | Last update time |

---

## Security Considerations

### Secret Storage

- TOTP secrets are stored in the database
- Consider encrypting the `secret` column
- Recovery codes are stored hashed (bcrypt)

### Email OTP Security

- Codes expire after configurable time
- Used codes are immediately invalidated
- Rate limit OTP sending to prevent abuse

### Recovery Code Security

- Codes are case-insensitive
- Each code works only once
- Regenerating invalidates all previous codes
- Codes are 10 characters (alphanumeric)

---

## Enforcement Policies

### Require MFA for All Users

```php
// In a middleware or event listener
public function handle($request, $next)
{
    $user = $request->user();

    if (count($user->multiFactorAuths) === 0) {
        return redirect()->route('account.security')
            ->with('warning', 'Please enable MFA for your account.');
    }

    return $next($request);
}
```

### Domain-Based MFA Requirement

When domain federation is enabled, you can require MFA for specific domains:

```php
// Domain rules include MFA enforcement
$domain->rules()->where('name', 'mfa')->first();
```

---

## Troubleshooting

### TOTP Codes Not Working

1. Check device time is accurate
2. Ensure time zone is correct
3. Try adjacent time windows (codes valid 30s)
4. Re-scan QR code to reset secret

### Email OTP Not Received

1. Check spam/junk folder
2. Verify email configuration
3. Check email delivery logs
4. Ensure OTP hasn't expired

### Recovery Codes Not Working

1. Check for typos (case-insensitive)
2. Ensure code hasn't been used
3. Codes might have been regenerated

---

## Next Steps

- [Team Management](./teams.md)
- [Security Features](./security.md)
- [API Reference](./api-reference.md)
