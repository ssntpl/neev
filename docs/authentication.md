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

Enforcement is opt-in: apply the `neev-password-not-expired` middleware alias (`EnsurePasswordNotExpired`) to routes that should reject users with expired passwords. Helpers are available on the user: `passwordExpiresAt()`, `isPasswordExpired()`, `isPasswordExpiringSoon()`.

### Accounts Without a Password

An account created through OAuth never sets a
password: `users.password` is `null`. That is a normal, fully usable account —
it simply has nothing to check a typed password against, so every flow that
would ordinarily ask for one has to decide what to do instead.

| Action | Account with a password | Account without one |
|--------|-------------------------|---------------------|
| Change password | Current password required | Refused — offered an emailed link instead |
| Set a password | — | `POST /account/password/reset-link` mails a signed link |
| Change email address | Current password required | Refused until a password is set |
| Delete account | Current password required | The signed-in session is the confirmation |

**Setting the first password.** There is nothing to prove ownership with except
the address on the account, so the only route is the link:

```http
POST /account/password/reset-link
```

It mails the same signed link the forgot-password flow sends, is rate limited to
5 requests per minute, and lands on the ordinary reset form. Following it while
still signed in returns to `/account/security` with the password set. The
security page offers the same button to anyone who has simply forgotten their
current password, so a reset never means signing out first.

The Blade security page reads `$user->password` and shows **Set Password** with
the emailed-link button, or **Change Password** with the current-password form
plus a *Don't remember your current password?* link. If you build your own
frontend, branch on the same value.

**Changing the address.** The address is what owns the account — it is where
every reset and confirmation goes — so changing it always costs a password.
An account without one is sent to `/account/security` to set a password first;
the API answers `403` with *Set a password on your account before changing your
email address.* The Blade change-email page hides the form and links to the
security page rather than showing a field that cannot be submitted.

**Deleting the account.** Where there is no password to check, the authenticated
session is the confirmation, and the `password` field is not required. The
confirmation dialog drops the password input and asks for a plain yes.

---

## Magic Link Authentication

Passwordless login via secure email links. Always available — no config toggle.

Magic links are **stateful and single-use**: an opaque, high-entropy token is
stored **hashed** in the `magic_link_tokens` table (only the plain token ever
leaves the app, inside the emailed URL). On successful use the row is **deleted**,
so a link can never be replayed, and issuing a new link **invalidates the user's
previous link** for that channel.

> A valid magic link completes login **without** enforcing MFA, even if the user
> has MFA enabled (by design — same posture as OAuth login). See [security.md](./security.md).

### Flow

1. User enters email and requests a link (`POST /neev/sendLoginLink`)
2. Receives an email with a secure link containing an opaque `token`
3. Opens the link → the token is validated and consumed
4. Automatically logged in (web: session; API: a login token)

Following the link also **marks an unverified address verified**: the link was
mailed to that address and came back signed, which proves inbox control just
as the verification mail would. An unverified account is therefore not turned
away from its own magic link.

Where the link points depends on the frontend. Under the Blade kit it goes
straight to `login.link`. Headless, following it mints an access token — and a
token must not travel in a URL — so the link lands on your `/login-link` page
carrying the signed query, which your page forwards to
`GET {prefix}/loginUsingLink` to exchange for the token. Both are controlled
by [`EmailLinks`](./email-links.md).

### API Example

**Request Link:**

```bash
curl -X POST https://yourapp.com/neev/sendLoginLink \
  -H "Content-Type: application/json" \
  -d '{"email": "john@example.com", "channel": "web"}'
```

**Use Link:**

```bash
# Opening the link only validates it — the response asks for confirmation.
curl -X GET "https://yourapp.com/neev/loginUsingLink?token=THE_OPAQUE_TOKEN"
# => {"auth_state": "confirmation_required", "channel": "web", ...}

# The user's explicit confirm consumes the link and logs them in.
curl -X POST "https://yourapp.com/neev/loginUsingLink" \
  -H "Content-Type: application/json" \
  -d '{"token": "THE_OPAQUE_TOKEN"}'
# => {"auth_state": "authenticated", "token": "...", ...}
```

Login and the confirmation step share one route: `GET` opens the link, `POST` is
the explicit confirm. `GET|POST /neev/loginUsingLink/validate` checks a token
without consuming it. Redemption is rate-limited (`throttle:10,1`).

A `GET` never consumes a link while `require_confirmation` is on (the default),
and that matters because links are single-use: scanning mail gateways (Outlook
SafeLinks, Mimecast) prefetch `GET` links, so a consuming `GET` would let the
scanner burn the link before the user clicks it. Your frontend should treat
`confirmation_required` as "render a confirm button that POSTs the token back".

### Channels

`sendLoginLink` accepts a `channel` (default `web`). Channels are config-driven —
add your own (e.g. `desktop`) under `magic_link.channels` with no code change. A
channel with a `scheme`/`universal_link` builds a deep link; otherwise a web URL.

A channel that cannot produce a usable URL is rejected at send time with
`MagicLinkChannelException` (HTTP `422`) rather than quietly falling back to a
web link: either the channel is not declared under `magic_link.channels`, or it
declares `scheme`/`universal_link` and both are empty. So `channel=mobile` fails
loudly until `NEEV_MOBILE_SCHEME` (or `NEEV_MOBILE_UNIVERSAL_LINK`) is set.

### Configuration & options

Configured under `magic_link` in `config/neev.php`:

```php
'magic_link' => [
    'expires_in' => 10,             // minutes a link stays valid
    'bind_to_browser' => false,        // restrict redemption to the originating browser/device
    'require_confirmation' => true,    // explicit confirm step; a GET never consumes the link
    'channels' => [ /* web, mobile, ... */ ],
],
```

See [configuration.md](./configuration.md#magic-link) for the full block.

### Calling it in code

Inject the `MagicLinkManager` service (there is no facade):

```php
use Ssntpl\Neev\Services\MagicLink\MagicLinkManager;

$link = $magicLink->forWeb($user);   // ['url', 'token', 'channel', 'expires_at', 'expires_in', 'model']
$result = $magicLink->consume($request);
if ($result->isValid()) { /* $result->user is authenticated */ }
```

`forWeb()`/`forMobile()`/`generate()` refuse rather than mint a link the
recipient could never use. Both checks run **before** the previous link is
invalidated, so a refused send never costs the user the link already in their
inbox:

| Exception | Thrown when |
|---|---|
| `MagicLinkBindingException` | `bind_to_browser` is on but the request has no binding source (`X-Device-Id`, `binding`, or session). |

An unverified address is **not** refused. The link is mailed to that address, so
following it proves control of the inbox exactly as the verification mail would —
redeeming therefore marks the email verified and fires `EmailVerified`. Gating
sends on verification would remove the one path an unverified user has to become
verified, stranding anyone who never received their verification mail.

Lifecycle events `MagicLinkGenerated` (`Ssntpl\Neev\Events\`), `MagicLinkConsumed`,
and `MagicLinkRejected` are fired for auditing/notifications. Prune expired tokens
with `php artisan neev:clean-magic-links`.

The events describe the token with scalars (`$tokenId`, `$channel`, `$expiresAt`)
rather than carrying the `MagicLinkToken` model, so they are safe to handle from a
queue. Carrying the model would break on both counts: the row is deleted the
moment the link is redeemed, so a queued listener restoring it would throw
`ModelNotFoundException`; and a model reachable other than as a top-level property
is serialized by value, which would put the account's password hash and the stored
token hash into the queue payload — and into `failed_jobs`, which is retained
indefinitely. `$user` is a model, which `SerializesModels` reduces to a
class-and-id reference; call `->fresh()` if you need attributes as of the click.

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

### Supported Domains

> **Passkeys are not supported on tenant custom domains.**

`relying_party_id` is a single application-wide value. WebAuthn requires the relying party ID to be
the request origin's host or a registrable suffix of it, so passkeys work only on:

- the configured domain itself — `example.com`
- any subdomain of it — `acme.example.com`, `admin.example.com`

They do **not** work on a tenant's own domain (`acme.com`), even when that domain is DNS-verified and
resolves the tenant correctly for every other purpose. The browser refuses the ceremony before the
request reaches the server: `navigator.credentials.create()` / `.get()` rejects with a
`SecurityError`, and nothing is logged server-side because nothing arrives.

Adding the custom domain to `allowed_origins` does not help. That list is checked server-side, after
the browser has already declined. It widens which origins may *complete* a ceremony under the
configured relying party ID; it cannot change which relying party ID a browser will accept.

**Offer another factor to tenants on custom domains** — magic link, OAuth, or password with MFA. Gate
the passkey option on the request host so those users are not shown a control that cannot work:

```php
$rpId = config('neev.relying_party_id');
$host = request()->getHost();

$passkeysAvailable = $host === $rpId || str_ends_with($host, '.' . $rpId);
```

Subdomain tenants need one piece of configuration: `CheckAllowedOrigins` is constructed without
subdomain matching, so every tenant subdomain that serves passkeys must appear in `allowed_origins`
verbatim. A wildcard is not accepted.

Supporting custom domains would mean deriving the relying party ID per request and recording it
against each credential, since a passkey is cryptographically bound to exactly one relying party ID
for its lifetime — a user would hold a separate passkey per domain. The `passkeys` table has no
column for it today.

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
GOOGLE_REDIRECT_URI="${APP_URL}/neev/oauth/google/callback"
```

### Per-platform clients (web, Android, iOS)

Identity providers issue a **separate client per platform** — Google will not accept a web
`client_id` from an Android app, and a native client redirects to a custom scheme or app
link rather than to your callback URL. Extra clients live under a `clients` key inside the
provider's existing `config/services.php` block:

```php
'google' => [
    // The default client, used when no platform is given. Unchanged.
    'client_id' => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'redirect' => env('GOOGLE_REDIRECT_URI'),

    'clients' => [
        'android' => [
            'client_id' => env('GOOGLE_ANDROID_CLIENT_ID'),
            'client_secret' => env('GOOGLE_ANDROID_CLIENT_SECRET'),
            'redirect' => env('GOOGLE_ANDROID_REDIRECT_URI'),   // e.g. com.acme.app:/oauth
        ],
        'ios' => [ /* ... */ ],
    ],
],
```

A platform block inherits everything it does not override (scopes, guzzle options), so it
usually carries only the credentials that differ. Platform names are yours to choose.

Clients are selected with a `platform` parameter on the API endpoints:

```
GET  {prefix}/oauth/google/redirect?platform=android
POST {prefix}/oauth/google/callback     { "code": "...", "platform": "android" }
```

The callback takes it too, because the authorization code was issued to one client and
must be exchanged with that same client and redirect URI. Requesting a platform that is
not configured returns **404** with the list of platforms that are, rather than silently
falling back to the web client and failing at the provider. Omitting `platform` behaves
exactly as before.

The top-level block is optional: an installation that configures only platform clients
(a mobile-only app) resolves too. The browser flow (`GET {prefix}/oauth/{service}`) always
uses the default client.

### Security Warning: OAuth Bypasses MFA and Password Policies

> **Warning — OAuth is a complete authentication path that skips the MFA gate.**
>
> Password login checks the user's enrolled MFA methods and, when any are active, withholds the session/token until a second factor is verified (`mfa_required` state with a temporary JWT on the API; redirect to the OTP page on the web). The OAuth callback does **not** perform this check: once the provider returns a verified email that matches an account, the user is logged in (web) or issued a full access token (API) immediately — even if that user has TOTP or email MFA enabled. A compromised Google/GitHub/Microsoft/Apple account therefore grants access without the second factor.
>
> Password policies are also inapplicable to OAuth-created accounts: they are created **without a password**, so complexity rules, password history, and password expiry never apply to them, and their email is marked verified automatically (it was verified by the provider).

**What this means for enterprise policy:** if your compliance posture requires MFA for all users (or organization-controlled credentials), enabling app-wide OAuth providers undermines that guarantee — every enabled provider is an alternate front door that skips your MFA and password controls.

> **A previously unverified address is adopted, not refused.** When the
> provider returns an address that matches an existing account whose email was
> never verified, the callback marks it verified and signs the user in. The
> reasoning is that the provider authenticated the address, which is the same
> claim our own verification mail makes. The consequence is that anyone who
> can register an account at your app with an address they do not control, and
> who later controls that address at the provider, reaches the account — so
> keep the `oauth` list to providers whose email claims you trust.

**Mitigations:**

- **Limit or empty the `oauth` providers list** in `config/neev.php`. Providers not in the list 404 on both redirect and callback, so this fully disables the path.
- **Use tenant SSO instead for organizations that need enforced IdP login.** Tenant/team SSO is database-configured per organization, and the `neev-ensure-sso` middleware rejects (API) or redirects (web) any authenticated session that was not established via SSO — including sessions created through app-wide OAuth. See [Multi-Tenancy → Enterprise SSO](./multi-tenancy.md#enterprise-sso).
- **Add an application-level step-up check** after login if MFA must be universal regardless of login method (Neev does not provide this out of the box).

### Flow

1. User clicks "Login with Google"
2. Redirected to Google's consent page
3. User authorizes the application
4. Redirected back with auth code
5. System exchanges code for user info
6. User is created or matched
7. If the matched account's address was not yet verified, it is marked verified — the provider authenticated it
8. Logged in and redirected (MFA is skipped)

The provider buttons are offered to unverified accounts too, on the Blade
password page and through `GET {prefix}/oauth/{service}/redirect` for headless
frontends. Step 7 is why: an unverified address is adopted, not refused.

### Accounts the provider names poorly

Some providers return no display name — GitHub does so whenever the account
has no name set, which is the common case. Registration falls back in order:

1. the provider's `name`, if it is more than whitespace;
2. the provider's nickname/handle;
3. the local part of the email address, with `.`, `_`, and `-` turned into
   spaces and title-cased — `ada.lovelace@example.com` becomes `Ada Lovelace`.

### URLs

- **Redirect:** `GET /neev/oauth/{provider}`
- **Callback:** `GET /neev/oauth/{provider}/callback`

The `/neev` prefix is configurable via `route_prefix` in `config/neev.php` (env `NEEV_ROUTE_PREFIX`). Changing it also changes the callback URLs registered with your OAuth providers.

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
GET /neev/tenant/auth
```

Returns tenant auth configuration:

```json
{
  "auth_method": "sso",
  "sso_enabled": true,
  "sso_provider": "entra",
  "sso_redirect_url": "https://acme.yourapp.com/neev/sso/redirect"
}
```

---

## Email Verification

### Enforcing Verification

Verification emails are always sent on registration. Enforcement is opt-in: apply the `neev-verified-email` middleware alias (`EnsureEmailIsVerified`) to routes that should require a verified email — there is no config toggle.

```php
Route::middleware(['neev:api', 'neev-verified-email'])->group(function () {
    // Routes that require a verified email
});
```

### Flow

1. User registers or changes email
2. Verification email is sent automatically, carrying **both** a signed link and a numeric code
3. User clicks the link, or types the code into the session that is waiting
4. Email is marked as verified; whichever proof was used invalidates the other
5. User can access routes protected by `neev-verified-email`

### The link is the credential

A verification link is opened by whichever browser the mail client hands it
to, which is rarely the one holding the session. So:

- **No login is required** to spend the link. `GET {prefix}/email/verify` and
  the Blade kit's `/email/verify/{id}/{hash}` both accept an anonymous
  request; the signature is what authorises the action.
- **The link verifies the account it was minted for**, not whoever happens to
  be signed in. Opening someone else's link while logged in verifies *their*
  address, not yours.
- **A second click is not an error.** Mail scanners routinely fetch links
  before the recipient sees them, so an already-verified address answers
  `Email verification already done.` with a 200.

The `hash` is bound to the address the link was mailed to, so a link minted
before an address change cannot verify the new one.

Where these links point, and what they answer, is controlled by
[`EmailLinks`](./email-links.md).

### Other ways an address becomes verified

Verification mail is not the only proof of inbox control, and the package
accepts the equivalents rather than sending a redundant email:

| Event | Why it counts |
|-------|---------------|
| OAuth / social login | The provider authenticated the address |
| Following a magic link | The link was mailed to the address and came back signed |
| Registering through a team invitation | The invitation reached that inbox |

In each case an unverified address is marked verified rather than the user
being turned away. Note the security trade-off this implies for OAuth: see
[Security](./security.md#oauth-and-email-verification).

This holds on both surfaces, and it applies to *offering* the method as well
as to accepting it:

- **API** — `POST {prefix}/sendLoginLink`, `GET {prefix}/loginUsingLink`,
  `GET {prefix}/oauth/{service}/redirect` and
  `POST {prefix}/oauth/{service}/callback` never inspect
  `email_verified_at`. The callback and the magic-link exchange return
  `"email_verified": true` because completing them verified the address, and
  the token they issue is a full login token, not a restricted one.
- **Web** — the Blade kit's password page (`auth/login-password.blade.php`)
  shows the OAuth buttons, "Login Via Link" and the passkey button whatever
  the account's verification state, so an unverified user is not left with
  only the password they may not have. The `login.link` and
  `oauth.callback` routes sign the user straight in and land on
  `neev.home`, not on `verification.notice`.

Only password login still stops at the verification notice — a password says
nothing about who controls the inbox, so it cannot stand in for verification.

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

The current password is required: the address is what owns the account, so
changing it is a password-grade action. An account that has no password (OAuth,
magic link, passkey) must [set one first](#accounts-without-a-password).

A confirmation link is mailed to the **new** address; nothing changes on the
account until it is followed. The API confirmation route answers both verbs:

```http
GET  /neev/email/change/verify?id=…&email=…&signature=…   # a clicked link
POST /neev/email/change/verify?id=…&email=…&signature=…   # an SPA forwarding the query
```

Like verification, the signature is the credential — no session is needed. If
somebody else claims the address between the request and the click, the
confirmation answers `409 This email address is already in use.` and the
account keeps its original address.

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
3. **Apply `neev-password-not-expired` middleware** if password aging is a compliance requirement
4. **Apply `neev-verified-email` middleware** to prevent unverified accounts from accessing sensitive routes
5. **Monitor login attempts** for suspicious activity
6. **Use session database** driver for logout-all-devices functionality
7. **Keep GeoIP database** updated for accurate location tracking
8. **Be aware that OAuth bypasses MFA and password policies** — see [the OAuth security warning](#security-warning-oauth-bypasses-mfa-and-password-policies) for mitigations

---

## Next Steps

- [Multi-Factor Authentication](./mfa.md)
- [Team Management](./teams.md)
- [API Reference](./api-reference.md)
