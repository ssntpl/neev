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
'login_throttle' => [
    'delay_after' => 3,          // Failed attempts before progressive delay kicks in
    'max_delay_seconds' => 300,  // Maximum delay in seconds (exponential backoff caps here)
],
```

### How It Works

Failed attempts are counted per email + IP address (cached for 1 hour). Once the count reaches `delay_after`, each subsequent failed attempt triggers an exponentially growing delay:

| Failed Attempts | Behavior |
|-----------------|----------|
| 1 to `delay_after` - 1 | Normal login speed |
| `delay_after` and beyond | Delay of `2^(attempts - delay_after)` seconds before the next attempt is allowed |
| Higher counts | Delay keeps doubling, capped at `max_delay_seconds` (default 300s / 5 minutes) |

While a delay is active, login attempts are rejected with a throttle validation error that includes the seconds remaining. There is no permanent lockout — the delay never exceeds `max_delay_seconds`. A successful login clears the counter and any pending delay.

### Logging Failed Attempts

```php
// config/neev.php
'log_failed_logins' => false,  // Cache-only counting (default)
'log_failed_logins' => true,   // Also persist failed attempts to the database
```

Throttle counters always live in the cache. When `log_failed_logins` is `true`, each failed login is additionally recorded in the `login_attempts` table (with IP, browser, platform, location, `is_success => false`).

**Cache only (recommended for performance):**
- Faster
- Auto-expires
- Lost on cache clear

**Database (recommended for compliance):**
- Persistent records
- Auditable
- Cleaned up by `neev:clean-login-attempts`

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

Passwords are stored hashed on the `users` table, with password history maintained as a JSON column.

### What a Password Change Revokes

Changing or resetting a password drops the account's other **sessions** and
**login tokens** — the credentials the old password vouched for. The session and
login token making the request are spared, so whoever changed the password stays
signed in.

Sessions can only be dropped on the **database** session driver, the one where
another session is reachable at all. On `file`, `redis` or `cookie`, attach
Laravel's `AuthenticateSession` middleware to your authenticated routes: it
stores the password hash in the session and invalidates any session whose hash no
longer matches, which is driver-agnostic and the only thing that works there.

**API tokens are not revoked.** They are credentials the user created
deliberately, not a by-product of signing in, so ending a team's integrations
because someone rotated their password is the application's decision rather than
the package's. Make it from a `PasswordChanged` listener:

```php
Event::listen(function (PasswordChanged $event) {
    app(AuthService::class)->revokeApiTokens($event->user);
});
```

The same primitives are available directly: `revokeOtherSessions()`,
`revokeLoginTokens()` and `revokeApiTokens()` on `AuthService`.

### Personal Data Prevention

Prevents using personal information:

```php
PasswordUserData::notContain(['name', 'email'])
```

Checks that password doesn't contain user's name or email.

### Password Expiry

```php
// config/neev.php
'password_expiry_days' => 90,  // Days before password expires. 0 = disabled.
```

Expiry is measured from the user's `password_changed_at` timestamp. The user model exposes:

```php
$user->passwordExpiresAt();          // Carbon|null — null when disabled or no timestamp
$user->isPasswordExpired();          // bool
$user->isPasswordExpiringSoon(7);    // bool — expiring within the given days
```

Enforcement ships as middleware, registered under the `neev-password-not-expired` alias (`Ssntpl\Neev\Http\Middleware\EnsurePasswordNotExpired`). Apply it to routes that should be blocked once the password expires:

```php
Route::middleware(['neev:api', 'neev-password-not-expired'])->group(function () {
    // Protected routes
});
```

When the authenticated user's password has expired:

- **API (JSON) requests** receive a `403` response: `{"error": "password_expired", "message": "Your password has expired. Please change your password."}`
- **Web requests** are aborted with a `403` and the same message

Unauthenticated requests pass through unchanged. Set `password_expiry_days` to `0` to disable expiry entirely.

---

## OAuth / Social Login Bypass

> **Warning:** everything in the sections above — and the MFA gate — applies only to password-based login. OAuth/social login (the `oauth` providers list in `config/neev.php`) is a separate, complete authentication path:
>
> - The OAuth callback logs the user in (web) or issues a full access token (API) **without checking the user's enrolled MFA methods**. A user with active TOTP or email MFA is never prompted for a second factor when signing in via OAuth.
> - Accounts created via OAuth have **no password**, so strength rules, password history, and password expiry never apply to them. Their email is marked verified automatically.
>
> The effective security of an OAuth-enabled account is therefore the security of the linked Google/GitHub/Microsoft/Apple account — your MFA and password policies do not add to it.

If MFA or organization-controlled credentials are a compliance requirement:

- **Keep the `oauth` list empty or minimal.** Providers not in the list return 404 on both the redirect and callback routes, which fully disables the path.
- **For per-organization enforcement, use tenant/team SSO with the `neev-ensure-sso` middleware.** It rejects (API) or redirects (web) any authenticated session that was not established via SSO, closing the OAuth side door for that organization.
- **If MFA must be universal across all login methods**, implement an application-level step-up check after login — Neev does not provide one for OAuth sessions.

See [Authentication → OAuth / Social Login](./authentication.md#security-warning-oauth-bypasses-mfa-and-password-policies) for details.

---

## Open Redirect Protection

A guest who hits a protected page is bounced to login with the page they
wanted stored in the session (`url.intended`), and the Blade login form also
takes an explicit `redirect` parameter — either way the user lands back where
they were going. Anything reachable that way is attacker
controlled: a link to *your* login page, carrying *their* destination, is a
phishing primitive — it wears your domain, and your site appears in the
referrer of whatever it lands on.

A destination is accepted only when it is on this site:

| Value | Followed | Why |
|-------|----------|-----|
| `/settings` | yes | A path on this site |
| `https://this-site.example/settings` | yes | Absolute, but on the current host — this is the shape the auth middleware stores |
| `https://evil.example/x` | no | Another host |
| `//evil.example` | no | Protocol-relative — a browser resolves it to `https://evil.example` |
| `/\evil.example` | no | Browsers normalise `\` to `/` in the authority, so this is the case above |
| `/` | no | Nothing to return to; `neev.home` is the better answer |
| `/login`, `/register`, `/logout`, the verification notice, the reset request | no | Auth pages are not a destination — landing there would bounce the user in a circle |
| `''`, an array, any non-string | no | Not a destination |

Anything rejected falls back to `config('neev.home')`. The check lives in
`AuthService::safeRedirect()`, and `AuthService::intendedUrl()` applies it to
both the form value and the stored destination.

Password login is not the only step that can interrupt the journey:

- **Email verification wins over the destination.** An unverified account is
  sent to `verification.notice`, so `redirect` cannot be used to step past it.
  The stored destination survives the detour and is applied once the address
  is verified.
- **MFA parks the destination.** The password step redirects to the challenge,
  so the destination is stored in the session as `mfa_redirect` and applied
  after the code is verified, then cleared. It is re-validated on the way out,
  and a login carrying no `redirect` clears any parked value — a destination
  never survives into a later, unrelated login.

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
LoginAttempt::MagicAuth    // magic auth
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
'login_history_retention_days' => 30,
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
'maxmind' => [
    'db_path' => 'app/geoip/GeoLite2-City.mmdb',
    'edition' => env('MAXMIND_EDITION', 'GeoLite2-City'),
    'license_key' => env('MAXMIND_LICENSE_KEY'),
],
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

// Returns: ['city' => 'Mountain View', 'state' => 'California', 'country' => 'United States', 'country_iso' => 'US', 'latitude' => 37.386, 'longitude' => -122.0838, 'timezone' => 'America/Los_Angeles']
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
| `mfa_jwt` | Short-lived JWT used only for MFA verification (not stored) |

### Token Storage

Tokens are hashed before storage. A random 40-character plaintext is generated and the `AccessToken` model's `'token' => 'hashed'` cast hashes it on write; verification uses `Hash::check()`:

```php
$plainTextToken = Str::random(40);
$token = $user->accessTokens()->create([
    'token' => $plainTextToken,  // Stored hashed via the 'hashed' cast
    // ...
]);
```

The plaintext is returned to the client once (as `{id}|{plaintext}`) and cannot be recovered from the database.

### Token Format

Tokens are formatted as `{id}|{token}`:

```
1|abc123def456ghi789jkl012mno345pqr678stu901
```

### Token Expiry

Login tokens carry an **idle** window rather than a fixed lifetime: each
authenticated request past the half-way point of the window slides the
deadline forward, and `neev.login_token_max_lifetime_minutes` caps how
far it can be slid from the moment the token was issued. An active user
is not signed out mid-session; an idle or stolen token still dies on
schedule. API tokens do not slide.

```php
// Login tokens (idle window, slid on use; configured in
// neev.login_token_expiry_minutes and capped by
// neev.login_token_max_lifetime_minutes)
$token = $user->createLoginToken(config('neev.login_token_expiry_minutes', 1440));

// MFA JWTs (configured in neev.mfa_jwt_expiry_minutes)
$mfaJwtExpiry = config('neev.mfa_jwt_expiry_minutes', 30);

// API tokens (optional expiry)
$token = $user->createApiToken('name', ['read'], 43200);  // 30 days
```

### Token Permissions

```php
$token = $user->createApiToken('name', ['read', 'write', 'delete']);
```

Enforce them per route with the `neev-token-can` middleware, which requires
**every** ability listed:

```php
Route::middleware(['neev:api', 'neev-token-can:write'])->put('/posts/{post}', ...);
Route::middleware(['neev:api', 'neev-token-can:read,write'])->post('/sync', ...);
```

Or check one directly — the token is on the request:

```php
$token = $request->attributes->get('neev.access_token');

if ($token?->can('write')) {
    // Allowed
}
```

Two things worth knowing:

- **A login token carries full authority.** It is minted by signing in with
  complete credentials, so it is the API's equivalent of a session rather than a
  scope — `can()` always returns true for one. Only an API token, created
  deliberately with chosen abilities, is scoped.
- **An API token created without abilities can do nothing guarded.**
  `createApiToken('name')` defaults to no permissions, and a scope that was never
  granted is not held. Routes without the middleware are unaffected, so attaching
  it is what turns the column on.

`$token->can('*')` is granted by a `'*'` entry, which `createApiToken()` also
collapses to automatically when every registered permission is passed.

---

## API Middleware Security

### NeevAPIMiddleware

The API middleware provides:

1. **Token Extraction:**
   - Bearer token from the `Authorization` header, in `{id}|{plaintext}` format

2. **Token Validation:**
   - Looks up the token by ID and verifies the plaintext against the stored hash (`Hash::check`)
   - Checks expiry — expired tokens are deleted and rejected with `401`

   A token's `token_type` does not restrict which paths it may reach — a
   token is judged on its hash and its expiry alone. Which endpoints a caller
   can reach is decided by the route groups. The MFA step-up is carried by a
   short-lived JWT rather than an `AccessToken`; see [MFA](./mfa.md).

3. **Account Status:**
   - Rejects deactivated users with `403` ("Your account is deactivated.")

4. **User Context:**
   - Sets authenticated user on request (and on the `ContextManager`)
   - Updates `last_used_at` timestamp

### Email Verification Enforcement

Email verification is enforced by a dedicated middleware, registered under the `neev-verified-email` alias (`Ssntpl\Neev\Http\Middleware\EnsureEmailIsVerified`):

```php
Route::middleware(['neev:api', 'neev-verified-email'])->group(function () {
    // Routes requiring a verified email
});
```

When the authenticated user's email is not verified:

- **API (JSON) requests** receive a `403` response: `{"message": "Email not verified."}`
- **Web requests** are redirected to the `verification.notice` route

Unauthenticated requests pass through unchanged.

---

## Recovery Codes

### Generation

```php
$codes = $user->generateRecoveryCodes();
// Deletes any existing codes, returns array of plain-text codes
// (count from config('neev.recovery_codes'), default 8; 10-char lowercase alphanumeric)
```

### Storage

Codes are stored hashed via the `RecoveryCode` model's `'code' => 'hashed'` cast:

```php
$this->recoveryCodes()->create([
    'code' => $code,  // Hashed on write by the cast
]);
```

### Usage

Each code is single-use — it is deleted after a successful verification:

```php
case 'recovery':
    $code = $this->recoveryCodes->first(function ($recoveryCode) use ($otp) {
        return Hash::check($otp, $recoveryCode->code);
    });
    if ($code) {
        $code->delete();  // Single-use: removed after verification
        return true;
    }
    break;
```

---

## Suspicious Login Detection

The `login_attempts` table includes a boolean `is_suspicious` column (default `false`). Neev stores and exposes this flag but does not currently compute it automatically — flagging logic is left to your application.

### Suggested Factors

- New IP address
- New device/browser
- New location
- Failed MFA attempts
- Multiple simultaneous sessions

### Example Implementation

```php
// In your application (e.g. a LoggedIn listener)
$recentAttempts = $user->loginAttempts()
    ->where('is_success', true)
    ->latest()
    ->take(10)
    ->get();

$isSuspicious = !$recentAttempts->contains('ip_address', $attempt->ip_address)
    || !$recentAttempts->contains('location', $attempt->location);

$attempt->update(['is_suspicious' => $isSuspicious]);
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
$user->delete();
```

All related data is cascade deleted.

The endpoints in front of it — `DELETE /account/accountDelete` and
`DELETE /neev/users` — ask for the current password when the account has one.
An account created through OAuth has no password, so
the authenticated session is the confirmation and `password` is not required.
See [Accounts Without a Password](./authentication.md#accounts-without-a-password).

---

## Secure Email Handling

### Email Address

Each user has a single email address stored directly on the `users` table:

```php
$user->email;         // User's email address
```

### Email Verification

```php
$user->email_verified_at;  // Null if unverified
```

Verification gates the routes you choose to protect with the
`neev-verified-email` middleware — it never gates the act of logging in. Every
passwordless method (magic link, OAuth, passkey) stays available to an
unverified account on both the API and the Blade kit, because each of them
establishes the same proof the verification mail would.

<a id="oauth-and-email-verification"></a>

#### What counts as proof of the address

Verification mail is not the only way an address becomes verified. Anything
that demonstrates control of the inbox is accepted, and the package marks the
address verified rather than sending a redundant email:

| Event | Proof |
|-------|-------|
| Following the verification link or entering the code | Direct |
| Following a magic login link | The link was mailed there and came back signed |
| Registering through a team invitation | The invitation reached that inbox |
| Signing in through OAuth | The provider authenticated the address |

**The OAuth case is the one with a trade-off.** When a provider returns an
address that matches an existing account whose email was never verified, the
callback marks it verified and signs the user in — it does not turn them away.
So an account registered with an address the registrant did not control
becomes reachable by whoever later controls that address at the provider.
Keep the `oauth` list to providers whose email claims you trust, and prefer
tenant SSO with `neev-ensure-sso` where an organization needs enforcement.

#### What verification is *not* required for

- **Password reset.** A forgotten password is exactly the case where the user
  may never have finished verifying, so requiring it first would strand them.
  Both requesting and using a reset link work on an unverified address.
- **Enrolling an authenticator app.** A TOTP device proves possession of a
  device, not of an inbox.

Verification **is** required to enrol email OTP as a second factor: that
factor is only as trustworthy as the inbox the code lands in.

#### Links carry their own authority

Emailed links are signed and time-limited, and the signature — not a session —
is what authorises the action. Verification and email-change links therefore
work without being logged in, which is necessary because a mail client opens
them in whichever browser it likes. Consequences worth knowing:

- A link acts on the account it was minted for, not on whoever is signed in.
- The verification `hash` is bound to the address the link was mailed to, so a
  link minted before an address change cannot verify the new one.
- A second click is not an error; mail scanners routinely fetch links first.
- Expiry comes from `url_expiry_time` (60 minutes by default).

See [Email Links](./email-links.md) for where these links point and how to
change it.

---

## Audit Logging

### Events to Listen For

```php
// LoggedIn
use Ssntpl\Neev\Events\LoggedIn;

// LoggedOut
use Ssntpl\Neev\Events\LoggedOut;
```

### Example Listener

```php
class SecurityAuditListener
{
    public function handleLogin(LoggedIn $event)
    {
        AuditLog::create([
            'user_id' => $event->user->id,
            'action' => 'login',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    public function handleLogout(LoggedOut $event)
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
'multi_factor_auth' => ['authenticator', 'email'],
'log_failed_logins' => true,
'password_expiry_days' => 90,
```

Apply the enforcement middleware to protected routes:

```php
Route::middleware(['neev:api', 'neev-verified-email', 'neev-password-not-expired'])->group(function () {
    // ...
});
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

# Update GeoIP database
php artisan neev:download-geoip
```

### Scheduled Tasks

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    $schedule->command('neev:clean-login-attempts')->daily();
    $schedule->command('neev:download-geoip')->monthly();
}
```

---

## Next Steps

- [Authentication Guide](./authentication.md)
- [MFA Guide](./mfa.md)
- [API Reference](./api-reference.md)
