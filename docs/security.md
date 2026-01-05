# Security Features

Complete guide to Neev's security features and best practices.

---

## Overview

Neev provides comprehensive security features:

- Brute force protection
- Password policies
- Login tracking and monitoring
- Session management
- IP geolocation
- Suspicious activity detection

---

## Brute Force Protection

### Configuration

```php
// config/neev.php
'login_soft_attempts' => 5,    // Progressive delays after this
'login_hard_attempts' => 20,   // Lockout after this
'login_block_minutes' => 1,    // Lockout duration
```

### How It Works

| Attempts | Behavior |
|----------|----------|
| 1-5 | Normal login speed |
| 6-19 | Progressive delays (1s, 2s, 4s, 8s...) |
| 20+ | Account locked for 1 minute |

### Storage Method

```php
// config/neev.php
'record_failed_login_attempts' => false,  // Use cache (default)
'record_failed_login_attempts' => true,   // Use database
```

**Cache (recommended for performance):**
- Faster lookups
- Auto-expires
- Lost on cache clear

**Database (recommended for compliance):**
- Persistent records
- Auditable
- Manual cleanup required

---

## Password Policies

### Strength Requirements

```php
// config/neev.php
'password' => [
    'required',
    'confirmed',
    Password::min(8)
        ->max(72)
        ->letters()
        ->mixedCase()
        ->numbers()
        ->symbols(),
    PasswordHistory::notReused(5),
    PasswordUserData::notContain(['name', 'email']),
],
```

### Password History

Prevents password reuse:

```php
PasswordHistory::notReused(5)  // Cannot reuse last 5 passwords
```

Passwords are stored hashed in the `passwords` table.

### Personal Data Prevention

Prevents using personal information:

```php
PasswordUserData::notContain(['name', 'email'])
```

Checks that password doesn't contain user's name or email.

### Password Expiry

```php
// config/neev.php
'password_soft_expiry_days' => 30,  // Warning period
'password_hard_expiry_days' => 90,  // Forced change
```

**Soft expiry:** Users see warnings but can still access the app.

**Hard expiry:** Users must change password before continuing.

---

## Login Tracking

### Tracked Information

For each login attempt:

| Field | Description |
|-------|-------------|
| `method` | Login method (password, passkey, sso, etc.) |
| `multi_factor_method` | MFA method used |
| `ip_address` | User's IP address |
| `platform` | Operating system |
| `browser` | Browser name and version |
| `device` | Device type (Desktop, Mobile, Tablet) |
| `location` | City, country (via GeoIP) |
| `is_success` | Whether login succeeded |
| `is_suspicious` | Flagged as suspicious |

### Login Methods

```php
LoginAttempt::Password     // password
LoginAttempt::Passkey      // passkey
LoginAttempt::MagicAuth    // magic_auth
LoginAttempt::OAuth        // oauth
LoginAttempt::SSO          // sso
```

### View Login History

**API:**

```bash
curl -X GET https://yourapp.com/neev/loginAttempts \
  -H "Authorization: Bearer {token}"
```

**Web:**

```http
GET /account/loginAttempts
```

### Retention

```php
// config/neev.php
'last_login_attempts_in_days' => 30,
```

Clean up old records:

```bash
php artisan neev:clean-login-attempts
```

---

## IP Geolocation

### Configuration

```php
// config/neev.php
'geo_ip_db' => 'app/geoip/GeoLite2-City.mmdb',
'edition' => env('MAXMIND_EDITION', 'GeoLite2-City'),
'maxmind_license_key' => env('MAXMIND_LICENSE_KEY'),
```

### Setup

1. Register at [MaxMind](https://www.maxmind.com/en/geolite2/signup)
2. Generate a license key
3. Add to `.env`:

```env
MAXMIND_LICENSE_KEY=your-license-key
```

4. Download database:

```bash
php artisan neev:download-geoip
```

### Usage

```php
use Ssntpl\Neev\Services\GeoIP;

$geoIP = app(GeoIP::class);
$location = $geoIP->getLocation('8.8.8.8');

// Returns: "Mountain View, California, US"
```

### Automatic Updates

Set up a scheduled task:

```php
// app/Console/Kernel.php
$schedule->command('neev:download-geoip')->monthly();
```

---

## Session Management

### Active Sessions

View all active sessions:

```bash
curl -X GET https://yourapp.com/neev/sessions \
  -H "Authorization: Bearer {token}"
```

**Response:**

```json
{
  "status": "Success",
  "data": [
    {
      "id": 1,
      "last_used_at": "2024-01-15T10:00:00Z",
      "attempt": {
        "ip_address": "192.168.1.1",
        "browser": "Chrome 120",
        "platform": "macOS",
        "location": "San Francisco, CA, US"
      }
    }
  ]
}
```

### Logout Current Session

```bash
curl -X POST https://yourapp.com/neev/logout \
  -H "Authorization: Bearer {token}"
```

### Logout All Sessions

```bash
curl -X POST https://yourapp.com/neev/logoutAll \
  -H "Authorization: Bearer {token}"
```

### Logout Specific Session (Web)

```http
POST /account/logoutSessions
Content-Type: application/x-www-form-urlencoded

session_id=abc123
```

### Session Database Driver

For full session management, use the database driver:

```env
SESSION_DRIVER=database
```

```bash
php artisan session:table
php artisan migrate
```

---

## Token Security

### Token Types

| Type | Description |
|------|-------------|
| `login` | Session token after login |
| `api_token` | API access token |
| `mfa_token` | Temporary MFA verification token |

### Token Storage

Tokens are hashed before storage:

```php
$plainTextToken = hash('sha256', Str::random(40));
$token = $user->accessTokens()->create([
    'token' => $plainTextToken,  // Stored hashed
    // ...
]);
```

### Token Format

Tokens are formatted as `{id}|{token}`:

```
1|abc123def456ghi789jkl012mno345pqr678stu901
```

### Token Expiry

```php
// Login tokens (default 24 hours, 60 min if MFA pending)
$token = $user->createLoginToken(1440);

// API tokens (optional expiry)
$token = $user->createApiToken('name', ['read'], 43200);  // 30 days
```

### Token Permissions

```php
$token = $user->createApiToken('name', ['read', 'write', 'delete']);

// Check permissions
if ($token->can('write')) {
    // Allowed
}
```

---

## API Middleware Security

### NeevAPIMiddleware

The API middleware provides:

1. **Token Extraction:**
   - Bearer token from Authorization header
   - `token` query parameter
   - `token` request body

2. **Token Validation:**
   - Checks token exists
   - Validates hash matches
   - Checks expiry
   - Validates MFA completion

3. **Email Verification:**
   - Blocks unverified users (if enabled)
   - Allows verification-related endpoints

4. **User Context:**
   - Sets authenticated user on request
   - Updates `last_used_at` timestamp

---

## Recovery Codes

### Generation

```php
$codes = $user->generateRecoveryCodes();
// Returns array of 8 plain-text codes
```

### Storage

Codes are stored hashed:

```php
$this->recoveryCodes()->create([
    'code' => Hash::make($code),
]);
```

### Usage

Each code is single-use:

```php
public function verifyMFAOTP($method, $otp): bool
{
    if ($method === 'recovery') {
        $code = $this->recoveryCodes->first(function ($rc) use ($otp) {
            return Hash::check($otp, $rc->code);
        });

        if ($code) {
            $code->code = Str::random(10);  // Invalidate
            $code->save();
            return true;
        }
    }
    // ...
}
```

---

## Suspicious Login Detection

### Factors Considered

- New IP address
- New device/browser
- New location
- Failed MFA attempts
- Multiple simultaneous sessions

### Implementation

```php
// In LoginAttempt model
public function isSuspicious(): bool
{
    // Compare with recent login history
    $recentAttempts = $this->user->loginAttempts()
        ->where('is_success', true)
        ->latest()
        ->take(10)
        ->get();

    // Check for new location
    if (!$recentAttempts->contains('location', $this->location)) {
        return true;
    }

    // Check for new IP
    if (!$recentAttempts->contains('ip_address', $this->ip_address)) {
        return true;
    }

    return false;
}
```

---

## Account Protection

### Account Deactivation

```php
$user->deactivate();  // Sets active = false
$user->activate();    // Sets active = true
```

Deactivated users cannot log in.

### Account Deletion

```php
// Requires password confirmation
$user->delete();
```

All related data is cascade deleted.

---

## Secure Email Handling

### Multiple Emails

Users can have multiple email addresses:

```php
$user->emails;        // All emails
$user->email;         // Primary email
```

### Email Verification

Each email must be verified separately:

```php
$email->verified_at;  // Null if unverified
```

### Primary Email

Only verified emails can be set as primary:

```php
if ($email->verified_at) {
    $email->is_primary = true;
    $email->save();
}
```

---

## Audit Logging

### Events to Listen For

```php
// LoggedInEvent
use Ssntpl\Neev\Events\LoggedInEvent;

// LoggedOutEvent
use Ssntpl\Neev\Events\LoggedOutEvent;
```

### Example Listener

```php
class SecurityAuditListener
{
    public function handleLogin(LoggedInEvent $event)
    {
        AuditLog::create([
            'user_id' => $event->user->id,
            'action' => 'login',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    public function handleLogout(LoggedOutEvent $event)
    {
        AuditLog::create([
            'user_id' => $event->user->id,
            'action' => 'logout',
            'ip_address' => request()->ip(),
        ]);
    }
}
```

---

## Security Headers

Recommended headers for your application:

```php
// In middleware
public function handle($request, Closure $next)
{
    $response = $next($request);

    return $response
        ->header('X-Frame-Options', 'DENY')
        ->header('X-Content-Type-Options', 'nosniff')
        ->header('X-XSS-Protection', '1; mode=block')
        ->header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
        ->header('Content-Security-Policy', "default-src 'self'");
}
```

---

## Best Practices

### 1. Enable All Security Features

```php
// config/neev.php
'email_verified' => true,
'multi_factor_auth' => ['authenticator', 'email'],
'record_failed_login_attempts' => true,
```

### 2. Use HTTPS

```env
APP_URL=https://yourapp.com
```

### 3. Configure Strong Password Rules

```php
Password::min(12)
    ->letters()
    ->mixedCase()
    ->numbers()
    ->symbols()
    ->uncompromised()
```

### 4. Implement Rate Limiting

```php
// routes/api.php
Route::middleware('throttle:60,1')->group(function () {
    // API routes
});
```

### 5. Monitor Login Attempts

Set up alerts for:
- Multiple failed login attempts
- Logins from new locations
- Concurrent sessions from different IPs

### 6. Regular Security Audits

- Review login attempt logs
- Check for inactive users
- Audit API token usage
- Verify domain configurations

### 7. Keep Dependencies Updated

```bash
composer update
php artisan neev:download-geoip
```

---

## Compliance Considerations

### GDPR

- Users can delete their accounts
- Users can export their data
- Email consent for communications
- Data retention policies

### SOC 2

- Login tracking and audit logs
- MFA enforcement
- Password policies
- Session management

### HIPAA

- Strong authentication required
- Audit logging mandatory
- Session timeouts
- Encryption in transit and at rest

---

## Maintenance Commands

```bash
# Clean old login attempts
php artisan neev:clean-login-attempts

# Clean old password history
php artisan neev:clean-passwords

# Update GeoIP database
php artisan neev:download-geoip
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

## Next Steps

- [Authentication Guide](./authentication.md)
- [MFA Guide](./mfa.md)
- [API Reference](./api-reference.md)
