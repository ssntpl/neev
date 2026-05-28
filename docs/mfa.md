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

### Pending Setup Retention

```php
// config/neev.php
'mfa_pending_retention_days' => 2,  // Days a pending setup row lives before pruning
```

Used by the `neev:clean-pending-mfa-setups` artisan command (see [Maintenance](#maintenance)).

---

## Authenticator Apps (TOTP)

Time-based One-Time Password using apps like Google Authenticator, Authy, 1Password.

### Setup Flow (two-step)

Authenticator setup is a two-step process so that a `multi_factor_auths` row is only marked `active` after the user proves they scanned the QR code. Half-finished setups stay in `pending` state and are pruned by a scheduled command — they do not enforce MFA at login.

1. User navigates to Security settings and clicks "Enable Authenticator"
2. Server creates a `multi_factor_auths` row with `status = pending` and returns the QR code + TOTP secret
3. User scans the QR code with their authenticator app
4. User enters the current TOTP code from the app
5. Server verifies the OTP against the pending row, promotes `status` to `active`, and the method becomes usable for login

If the user never completes step 4, the pending row stays inactive (no MFA challenge at login) and is eventually deleted by the cron command.

### Step 1 — Start Setup

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

A row is created with `status = pending`. Re-calling `/mfa/add` for the same user replaces the existing pending row with a fresh secret (each Add click restarts setup). Returns `Error` if an `active` authenticator row already exists.

### Step 2 — Verify OTP and Activate

```bash
curl -X POST https://yourapp.com/neev/mfa/setup/otp/verify \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "auth_method": "authenticator",
    "otp": "123456"
  }'
```

**Response (success):**

```json
{
  "status": "Success",
  "method": "authenticator",
  "message": "MFA enabled successfully."
}
```

**Response (wrong OTP, 400):**

```json
{
  "message": "Code verification failed."
}
```

**Response (no pending row, 400):**

```json
{
  "message": "No pending setup found. Please start setup again."
}
```

On success, the row's status transitions to `active` and the method enforces MFA at next login.

### Display QR Code

```html
<!-- In your view (after Step 1) -->
<div class="qr-code">
    {!! $response['qr_code'] !!}
</div>
<p>Or enter manually: {{ $response['secret'] }}</p>
```

### Verify OTP During Login

After an MFA-required login, verify the OTP against an active row (this endpoint is for login only, not setup):

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
3. MFA token is returned instead of full token
4. User is redirected to MFA verification
5. User enters TOTP code or email OTP
6. Full access token is returned

### Web Flow

```php
// After successful password verification — only active rows trigger MFA challenge.
// Pending rows from an abandoned setup do NOT enforce MFA.
if (count($user->activeMultiFactorAuths) > 0) {
    session(['email' => $email]);
    return redirect(route('otp.mfa.create',
        $user->preferredMultiFactorAuth?->method ??
        $user->activeMultiFactorAuths()->first()?->method
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
// Get all MFA rows for the user (any status — pending or active)
$user->multiFactorAuths;

// Get only active MFA rows (filter applied at the relation level)
$user->activeMultiFactorAuths;

// Get a specific MFA method (returns the ACTIVE row for that method, or null)
$user->multiFactorAuth('authenticator');

// Get the pending row for a method (used by setup-verify path only)
$user->pendingMultiFactorAuth('authenticator');

// Get preferred MFA method (active rows only)
$user->preferredMultiFactorAuth;

// Add MFA method
// - 'authenticator': creates a pending row and returns QR code + secret
// - 'email': creates an active row directly (email is already trusted)
$user->addMultiFactorAuth('authenticator');

// Generate new recovery codes (replaces any existing)
$user->generateRecoveryCodes();
```

### MultiFactorAuth Model Methods

```php
use Ssntpl\Neev\Models\MultiFactorAuth;

// Static helpers — pure functions, useful in tests and outside the auth flow.

// Render the SVG QR code for a given secret + user email
$svg = MultiFactorAuth::getQrCodeForAuthenticatorSetup($secret, $user->email);

// Pure TOTP verification (no DB side effects)
$ok = MultiFactorAuth::verifyAuthenticatorOTP($secret, '123456');

// Instance methods on a MultiFactorAuth row.

// Dispatches by row method (authenticator or email). On success,
// promotes pending → active and updates last_used.
$auth->verifyOTP('123456');
```

### RecoveryCode Model Method

```php
use Ssntpl\Neev\Models\RecoveryCode;

// Instance method — verifies a single recovery code by Hash::check.
// Callers should iterate the user's recoveryCodes collection.
$matched = $user->recoveryCodes->first(fn ($c) => $c->verify($input));
if ($matched) {
    $matched->delete();
}
```

---

## Database Schema

### multi_factor_auths Table

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| user_id | bigint | Foreign key to users |
| method | string | MFA method name (`authenticator`, `email`) |
| secret | string | TOTP secret (encrypted at rest) |
| preferred | boolean | Is this the preferred method |
| status | string | `pending` (setup not verified) or `active` (usable) — defaults to `pending` |
| otp | string | Current email OTP (if applicable, hashed) |
| expires_at | timestamp | Email OTP expiry time |
| last_used | timestamp | Last successful verification |
| created_at | timestamp | Creation time |
| updated_at | timestamp | Last update time |

Unique constraint on `(user_id, method)` — at most one row per user per method. Re-clicking "Add" on a half-finished authenticator setup updates the existing pending row with a fresh secret.

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
// In a middleware or event listener — only ACTIVE rows count as "MFA enabled"
public function handle($request, $next)
{
    $user = $request->user();

    if (count($user->activeMultiFactorAuths) === 0) {
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

## Maintenance

### Pruning Abandoned Setups

Pending authenticator setups (rows with `status = pending`) that the user never completed accumulate over time. Neev provides an artisan command to delete them:

```bash
php artisan neev:clean-pending-mfa-setups
```

Deletes pending rows older than `neev.mfa_pending_retention_days` (default: 2 days). Schedule it alongside `neev:clean-login-attempts`:

```php
// In your app's scheduler
$schedule->command('neev:clean-pending-mfa-setups')->daily();
```

Pending rows do not enforce MFA at login (they're invisible to the active relation), so missing the schedule does not lock users out — it only lets the table grow.

---

## Next Steps

- [Team Management](./teams.md)
- [Security Features](./security.md)
- [API Reference](./api-reference.md)
