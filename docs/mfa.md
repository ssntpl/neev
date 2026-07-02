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
'otp_expiry_time' => 15,  // Minutes before email OTP codes expire
'otp_length' => 6,        // OTP length: 4, 6, or 8 digits
```

### MFA JWT Settings

After a password login that requires MFA, the API issues a short-lived JWT used only to complete verification:

```php
// config/neev.php
'mfa_jwt_expiry_minutes' => 30,           // Minutes before the MFA JWT expires
'jwt_secret' => env('NEEV_JWT_SECRET'),   // Signing key; falls back to APP_KEY if not set
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
  "status": "Success",
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

### Verify OTP During Login

Verify the OTP after an MFA-required login:

```bash
curl -X POST https://yourapp.com/neev/mfa/otp/verify \
  -H "Authorization: Bearer {mfa_jwt_token}" \
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
  "status": "Success",
  "method": "email",
  "message": "Email Configured."
}
```

### Sending the OTP

There is no standalone API endpoint to send the MFA email OTP. During API login, when the user's MFA method is `email`, the OTP is generated and emailed automatically as part of the `auth_state: mfa_required` response.

In the web (Blade) flow, users on the MFA verification page can request a resend:

```http
POST /neev/otp/mfa/send   (route: otp.mfa.send)
```

### Verify OTP

```bash
curl -X POST https://yourapp.com/neev/mfa/otp/verify \
  -H "Authorization: Bearer {mfa_jwt_token}" \
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
  -H "Authorization: Bearer {mfa_jwt_token}" \
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
3. A short-lived MFA JWT is returned instead of a full token (and the email OTP is sent automatically if the method is `email`)
4. User is redirected to MFA verification
5. User enters TOTP code or email OTP
6. Full access token is returned

### Web Flow

```php
// After successful password verification
if (count($user->multiFactorAuths) > 0) {
    session(['email' => $user->email]);
    return redirect(route('otp.mfa.create',
        $user->preferredMultiFactorAuth->method ??
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

Response includes `auth_state` and `mfa_options`:

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

**Step 2: Verify MFA**

The `token` from step 1 is a JWT signed with `neev.jwt_secret` (expires after `mfa_jwt_expiry_minutes`, default 30). Send it as the Bearer token to the verify endpoint, which runs under the `neev:login` middleware group (JWT authentication) and is throttled to 5 requests per minute:

```bash
curl -X POST https://yourapp.com/neev/mfa/otp/verify \
  -H "Authorization: Bearer jwt_mfa_token..." \
  -d '{"auth_method": "authenticator", "otp": "123456"}'
```

Response with full token:

```json
{
  "auth_state": "authenticated",
  "token": "1|full_access_token...",
  "expires_in": 1440,
  "mfa_options": null,
  "email_verified": true
}
```

---

## Preferred MFA Method

Users with multiple MFA methods can set a preferred one.

### Set Preferred Method

```bash
curl -X PUT https://yourapp.com/neev/mfa/preferred \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"auth_method": "authenticator"}'
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
| secret | text | TOTP secret (encrypted) |
| preferred | boolean | Is this the preferred method |
| otp | text | Current email OTP, stored hashed (if applicable) |
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

- TOTP secrets are stored encrypted (the `secret` column uses Laravel's `encrypted` cast)
- Email OTP codes are stored hashed (the `otp` column uses the `hashed` cast)
- Recovery codes are stored hashed (the `code` column uses the `hashed` cast)

### Email OTP Security

- Codes expire after `otp_expiry_time` minutes (default 15)
- Used codes are immediately invalidated
- The verify endpoint is throttled to 5 requests per minute

### Recovery Code Security

- Codes are entered exactly as shown (they are generated as lowercase; matching is case-sensitive)
- Each code works only once — it is deleted after use
- Regenerating invalidates all previous codes
- Codes are 10 characters (lowercase alphanumeric)

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

1. Check for typos (codes are lowercase; matching is case-sensitive)
2. Ensure code hasn't been used
3. Codes might have been regenerated

---

## Next Steps

- [Team Management](./teams.md)
- [Security Features](./security.md)
- [API Reference](./api-reference.md)
