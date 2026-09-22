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
| Delete account | Current password required | One-time code required (`otp`) |
| Log out all sessions | Current password required | One-time code required (`otp`) |

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

**Confirming a sensitive action.** Where there is no password to check, the
proof is a one-time code mailed to the address on the account. The client asks
for one, then sends it with the action:

```http
POST /neev/confirmation/otp          → 200, code mailed
Authorization: Bearer {token}

DELETE /neev/users                   → 200, account deleted
Authorization: Bearer {token}
Content-Type: application/json

{"otp": "123456"}
```

`POST /neev/logoutAll` takes the same field. The Blade flows use
`POST /account/confirmation/otp` (route name `account.confirmation`) and post
`otp` alongside the action; the confirmation dialog swaps its password input for
a code field.

The endpoint is generic — it mails a code and nothing else, to any authenticated
user — so anything else needing proof of mailbox access can use it too. Note
what the code is and is not: for an account reached by OAuth or SSO, the mailbox
already grants a session, so the code re-checks the factor the session was built
on. It stops a stolen cookie or bearer token, not a compromised mailbox.

Two properties worth designing around:

- **A user holds one code at a time.** The code lives in a single row keyed by
  the user, so issuing one replaces any code outstanding for another purpose,
  including an email verification in flight.
- **A code is spent by the action it confirms.** A correct guess deletes it, so
  one code confirms one action — request a fresh one each time. Nothing binds a
  code to the action it was read for, which is exactly why single use matters:
  the same code also satisfies `POST /neev/email/verify-otp`.

---

## Magic Link Authentication

Passwordless login via secure email links. Always available — no config toggle.

Magic links are **stateful and single-use**: an opaque, high-entropy token is
stored **hashed** in the `magic_link_tokens` table (only the plain token ever
leaves the app, inside the emailed URL). On successful use the row is **deleted**,
so a link can never be replayed, and issuing a new link **invalidates the user's
previous link** for that channel. Because of that, issuance is **capped per
account**: `MagicLinkManager::ISSUANCE_LIMIT` (3) links per channel per
`ISSUANCE_WINDOW` (5 minutes), refused with `429` on the API and an error on the
Blade form *before* anything is invalidated, so the link already held survives.
Without the cap, anyone who knew an address could keep its owner's link
permanently dead. **Redeeming a link clears the account's counter for that
channel**: the cap exists to bound how often an *unconsumed* link can be
replaced out from under its owner, and a redemption ends that — so a user who
legitimately requests and uses three links inside one window (a second device, a
lost mail, a re-login after logout) is not locked out for the remainder of it.

In tenant mode the link is built on a verified host that resolves the tenant —
the one the request came in on, the tenant's own domain, or one of its teams'. A tenant with **no** verified domain at all still gets the
platform host, where the tenant-scoped token cannot be found and the link is
dead; a warning is logged when that happens. Give every tenant a verified
domain before offering magic links.

A magic link is a **first factor, not a way around the second**. An account with MFA enrolled stops at the same challenge it would after a password login — the link issues the short-lived MFA JWT instead of a full access token, and `POST /neev/mfa/otp/verify` completes it exactly as after a password.

### Flow

1. User enters email and requests a link (`POST /neev/sendLoginLink`)
2. Receives an email with a secure link
3. Opens the link → the token is validated and consumed
4. If the account has MFA enrolled, returns `auth_state: mfa_required` with a short-lived MFA JWT — complete with `POST /neev/mfa/otp/verify`
5. Otherwise, logged in with a full access token

Following the link also **marks an unverified address verified**: the link was
mailed to that address and came back signed, which proves inbox control just
as the verification mail would. An unverified account is therefore not turned
away from its own magic link.

Where the link points depends on the frontend. Under the Blade kit it goes to `login.link.verify` (`/login-link/verify`), which redeems the token server-side. Headless, the link lands on your `/login-link` page carrying the opaque token as a query parameter, which your page forwards to `POST {prefix}/loginUsingLink` to exchange for the token. Both are controlled by [`EmailLinks`](./email-links.md).

### API Example

**Request Link:**

```bash
curl -X POST https://yourapp.com/neev/sendLoginLink \
  -H "Content-Type: application/json" \
  -d '{"email": "john@example.com", "channel": "web"}'
```

**Use Link:**

```bash
# When require_confirmation is on, GET only validates — never consumes.
curl -X GET "https://yourapp.com/neev/loginUsingLink?token=THE_OPAQUE_TOKEN"
# => {"auth_state": "confirmation_required", "channel": "web", ...}

# The user's explicit confirm consumes the link and logs them in.
curl -X POST "https://yourapp.com/neev/loginUsingLink" \
  -H "Content-Type: application/json" \
  -d '{"token": "THE_OPAQUE_TOKEN"}'
# => {"auth_state": "authenticated", "token": "...", ...}
# or, for an MFA-enrolled account:
# => {"auth_state": "mfa_required", "token": "<mfa_jwt>", "mfa_options": ["email"], ...}
```

Login and the confirmation step share one route: `GET` opens the link, `POST` is
the explicit confirm. `GET|POST /neev/loginUsingLink/validate` checks a token
without consuming it. Redemption is rate-limited (`throttle:10,1`).

A `GET` never consumes a link while `require_confirmation` is on (`true` by
default; turn it off only if you are certain no user sits behind a scanning
mail gateway). Scanning gateways (Outlook SafeLinks, Mimecast) prefetch `GET` links; because
links are single-use, a prefetch would burn the link before the user clicks it.
With confirmation on, treat `confirmation_required` as "render a confirm button
that POSTs the token back".

### Channels

`sendLoginLink` accepts a `channel` (default `web`). Channels are config-driven —
add your own (e.g. `desktop`) under `magic_link.channels` with no code change. A
channel with a `scheme`/`universal_link` builds a deep link; otherwise a web URL.

Channels select the **URL shape, not who may redeem**. A redemption request
that names a `channel` must match the token's, but omitting it accepts whatever
the token stored — so a mobile-channel token redeems at the web endpoint and
vice versa. Both links are mailed to the same inbox, so neither grants access
the other does not; do not rely on channels as an authorization boundary.

A channel that cannot produce a usable URL is rejected at send time with
`MagicLinkChannelException` (HTTP `422`) rather than quietly falling back to a
web link: either the channel is not declared under `magic_link.channels`, or it
declares `scheme`/`universal_link` and both are empty. So `channel=mobile` fails
loudly until `NEEV_MOBILE_SCHEME` (or `NEEV_MOBILE_UNIVERSAL_LINK`) is set.

The channel's URL is resolved **before** anything is spent, so this refusal
costs the account nothing: no previous link is invalidated, no unreachable token
row is stored, no `MagicLinkGenerated` fires, and no issuance is counted against
the cap. Fixing `NEEV_MOBILE_SCHEME` and retrying leaves the user exactly where
they were.

### Configuration & options

Configured under `magic_link` in `config/neev.php`:

```php
'magic_link' => [
    'expires_in' => 10,             // minutes a link stays valid
    'bind_to_browser' => false,        // restrict redemption to the originating browser/device
    'require_confirmation' => true,    // GET only validates; an explicit POST consumes
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
recipient could never use. Every check runs **before** the previous link is
invalidated, so a refused send never costs the user the link already in their
inbox:

| Exception | HTTP | Thrown when | Costs an issuance? |
|---|---|---|---|
| `MagicLinkBindingException` | `422` | `bind_to_browser` is on but the request has no binding source (`X-Device-Id`, `binding`, or session). | No |
| `MagicLinkChannelException` | `422` | The channel is not declared under `magic_link.channels`, or it is a deep-link channel with `scheme` and `universal_link` both empty. | No |
| `MagicLinkThrottledException` | `429` | The account has already been issued `ISSUANCE_LIMIT` links for this channel inside `ISSUANCE_WINDOW`. `retryAfter` carries the seconds. | — |

The two refusals that mint nothing are evaluated first, so a send that was never
going to produce a usable link does not spend the budget either.

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

WebAuthn requires the relying party ID to be the browser origin's host or a registrable suffix of it.
A single app-wide value therefore locks passkeys to the platform domain. It is instead read off the
request's context, by one rule:

> the **verified domain that equals the request's origin** — the row the request resolved through,
> or one the resolved context owns. The match is exact: a row covers the host it names and no other.
> No context, no such domain, or an origin inside the platform's own zone, and `relying_party_id`
> stands.

The origin is the browser's `Origin` header, and only that — the request's host is the host the
request was *addressed to*, which on a shared API is not where the ceremony would run. The context is
whichever one the request resolved — the `X-Tenant` header, a subdomain, a custom domain, all of
them — so the API may be deployed anywhere: a SPA on `acme.com` calling an API on `api.platform.com`
sends `X-Tenant`, as it must for everything else to be scoped correctly, sends
`Origin: https://acme.com` as every browser does cross-origin, and the ceremony runs under
`acme.com`.

> **The ceremony options request has to carry `Origin`, which is why both options endpoints are
> POST.** Browsers set the header themselves on every POST and on every cross-origin request. They
> omit it on a same-origin GET, and `Origin` is a forbidden header name, so client JavaScript cannot
> put it back: `fetch()` and `XMLHttpRequest` drop any attempt to set it. A GET options endpoint would
> therefore name no origin when called from the host it is served on — the ordinary Blade layout, and
> any SPA deployed beside its API — and run under `relying_party_id`, which the browser then refuses
> on the tenant's own host. So `POST /neev/passkeys/register/options` and
> `POST /neev/passkeys/login/options` take their input in the body (the registration one has none) and
> name their origin wherever they are called from, same-origin or not; the kit's own
> `POST /account/passkeys/register/options` and `POST /passkeys/login/options` (unprefixed, registered
> only when `neev.ui` is `blade`) have always been POST for the same reason. A caller that legitimately
> has no host origin — a native app, whose origin is an app facet — keeps the configured relying party,
> the only one it can hold platform assets for.

**The row the request resolved through counts, whoever owns it.** With both tenants and teams on,
a team-owned host routes through that team's tenant, so the resolved context holds no row naming the
host — reading only its rows would drop the ceremony to `relying_party_id`, which the browser on that
host then refuses. The resolving row is taken first, and `rp.name` is its owner's name (the team's,
not the tenant's it routes to).

**A verified row is not by itself a serving host**, which is why the origin decides. `domains` is
also the federation registry: a team reached at `acme.example.com` federates `acme.com` so that
`@acme.com` staff auto-join, with nothing ever served there. Handing that row the relying party would
break the host users do sign in on — `navigator.credentials.create()` rejects `rp.id = "acme.com"` on
`acme.example.com` with a `SecurityError`, and every credential already enrolled drops out of
`allowCredentials` — so a domain the origin cannot use is never taken.

A domain inside the platform's own zone keeps the platform relying party. Subdomain tenants hold
`domains` rows too — the tenant-domains API verifies `type: subdomain` on sight — so without that, a
subdomain tenant would claim its own relying party and retire the passkeys already enrolled under the
platform's.

Only verified rows count, and the row is read on every ceremony, so a domain that loses its
verification stops granting a relying party on the very next request. The credential rows survive and
are simply never selected again; nothing is deleted on a user's behalf.

#### A passkey belongs to one domain

A credential is cryptographically bound to exactly one relying party ID for its lifetime. This is not
a package limitation and no setting changes it:

- a user's passkey on `example.com` will **never** authenticate them on `acme.com` — they enrol a
  separate one per relying party
- the relying party is recorded on each credential in `passkeys.rp_id`, and login offers and accepts
  only the credentials belonging to the current one
- listing a user's passkeys (`GET /neev/passkeys`, the account page) is deliberately *not* scoped
  that way: it returns every credential the user holds, whichever relying party issued it, so one
  enrolled on a tenant's domain stays revocable from the platform. `rp_id` is on each row — label
  them by it, and expect a credential the user cannot sign in with from the domain they are on
- subdomains of the configured domain always run under the platform's relying party, verified or
  not, so a passkey enrolled on `acme.example.com` is the same credential as one enrolled on
  `example.com`
- nothing is matched by suffix, on any relying party — not the relying party a host is given, and
  not the origins a ceremony admits. WebAuthn would let a browser on `app.acme.com` use a credential
  bound to `acme.com`; this package does not offer it one, and would refuse the origin if it did.
  Verify every host users sign in on — see [Origins](#origins) below

Credentials created before this behaviour existed carry no relying party of their own and are read as
belonging to the configured one — they keep working on the platform domain and are never offered on a
tenant's domain.

#### One relying party per tenant

**A tenant gets one relying party per origin it is reached on.** A tenant holding several domains
with different registrable roots — `ssntpl.in` and `otper.com` — runs under `ssntpl.in` on
`ssntpl.in` and under `otper.com` on `otper.com`, because `rp.id = "ssntpl.in"` is neither the host
nor a suffix of the host on the second, and a browser there would reject it with a `SecurityError`
before any request was made.

The consequence is a credential per root, not a credential that spans them: a user who enrols on
`ssntpl.in` has no passkey on `otper.com` and is prompted to enrol again. That much is inherent to
WebAuthn — no single credential can span two registrable roots, whoever owns them. Where a tenant
needs *one* credential across its domains, the application decides how:

- **redirect to the primary domain to sign in**, then return — one credential, works in every
  browser, and the model Auth0, Okta and WorkOS use
- **serve `/.well-known/webauthn` on the primary** listing the other origins (WebAuthn L3 Related
  Origin Requests), which lets a browser on `otper.com` run a ceremony for `ssntpl.in`. The server
  checks the origin too, so list the related origins in `allowed_origins` as well — that list
  applies on every relying party. Chrome/Edge 128+ and Safari 18+ only, so it needs one of the
  other two as a fallback
- **accept one passkey per host**, which is what happens by default — each verified host the tenant
  is served on enrols and offers its own credentials

Subdomains are a separate matter, and they are domains like any other here: a verified
`app.acme.com` is its own relying party, with its own credentials, and an unverified one gets
`relying_party_id` — which its browser then refuses. Verify the host the login page lives on.

#### Gating the UI

The shipped Blade views show the passkey controls on every host, so where a ceremony cannot run the
browser simply refuses it. To hide the control there instead, compare the host against the relying
party:

```php
use Ssntpl\Neev\Services\RelyingPartyResolver;

$resolver = app(RelyingPartyResolver::class);

$passkeysAvailable = $resolver->usableFrom($resolver->rpId(), request()->getHost());
```

A headless frontend cannot work this out itself, since it does not know which domains are verified.
Return `rpId()` and that flag from whichever bootstrap endpoint the SPA already calls, and let it hide
the control where no ceremony can run — offering another factor there instead: magic link, OAuth, or
password with MFA.

#### Origins

`allowed_origins` does **not** need to list a verified tenant domain. A ceremony under a relying party
taken from the `domains` table admits `https://` plus the domain record's own value, added to the
configured list — which is kept whole on every relying party.

That origin is built from the record, never from the request, so a call arriving over `http` or on a
non-standard port cannot widen what the ceremony accepts. Serve verified tenant domains over HTTPS on
the default port, which WebAuthn requires in any case.

The configured list is kept whole because it is where **native-app origins** belong. An Android app
completes a ceremony with `android:apk-key-hash:<base64url SHA-256 of the signing certificate>` as its
origin, and the platform's app serves every tenant — so list it once in `allowed_origins` and it holds
on a tenant's domain too. (iOS sends `https://<rpId>`, which the tenant's own origin already covers.)
The web origins in that list are harmless on a tenant's relying party: a browser on a platform origin
cannot run a ceremony for `acme.com` in the first place.

The OS side has its own requirements, per relying party, that the package cannot supply:

- **Android** — the tenant's domain must serve `https://acme.com/.well-known/assetlinks.json`
  naming the app's package and signing-certificate fingerprint (Digital Asset Links) with
  `delegate_permission/common.get_login_creds`
- **iOS** — the app's Associated Domains entitlement must carry `webcredentials:acme.com`, and the
  domain must serve `https://acme.com/.well-known/apple-app-site-association` with a
  `webcredentials` block naming the app. The entitlement ships with the app, so a new custom domain
  needs an app update before its users can use passkeys from the iOS app

Because both the relying party and the origins are matched exactly, **verify the host you actually
serve passkeys from**. A row on `acme.com` does nothing for a browser on `app.acme.com`: it is handed
`relying_party_id`, which is not its host either, and the ceremony never starts. Verify
`app.acme.com` as well if that is where users sign in.

**Subdomain matching is off on every relying party**, and there is no config key to turn it on. A
passkey is bound to the relying party rather than to an origin, so the origin list is the only thing
standing between a compromised sibling host — a dangling CNAME, an XSS on a staging or marketing
host, a tenant able to serve its own script from its subdomain — and an assertion accepted as the
victim. On a multi-tenant installation a host under your platform domain is a *tenant's*, so "every
host beneath it is mine" does not hold. That makes it a
[security invariant](./design-principles.md): enforced, not offered.

Every admitted origin is therefore named, and comes from one of two places:

1. **`allowed_origins`** — your own operational hosts. Each one that serves passkeys appears
   verbatim:

   ```php
   'allowed_origins' => [
       'https://example.com',
       'https://app.example.com',
       'https://login.example.com',
   ],
   ```

2. **The verified `domains` row the request itself names.** A tenant on `acme.example.com` is
   admitted because its verified row says so — not because the host ends in your platform domain.
   Tenant subdomains therefore need no entry in `allowed_origins`. Inside the platform's zone only
   the single host the request's `Origin` names is admitted, never every platform-zone row the
   context happens to hold: those hosts all share `relying_party_id`, so admitting a sibling would
   let a ceremony run there complete against a credential enrolled here. A tenant's verified
   **custom** domain (`acme.com`) works the same way, and becomes the relying party as well.

   What this leaves is the platform's own boundary. Every host in your zone shares one relying party,
   which is WebAuthn's rule and not something an origin list can undo — so script execution on any
   host you serve there can run a ceremony for it. Because the platform serves every host in its own
   zone, that is the same boundary an XSS on the apex would cross. It holds only as long as tenants
   cannot serve their own script from a host under your platform domain; if yours can, give those
   hosts their own relying party rather than relying on the origin list.

An installation where every host under the platform domain really is its own may widen the check by
overriding `allowSubdomains()` in a subclass and binding it in a service provider — a deliberate code
change, which is the right friction for loosening a boundary. Restrict the widening to **your**
relying party; a tenant's `acme.com` is not yours, and admitting `app.acme.com` there would trust a
host you do not control:

```php
// app/Services/RelyingPartyResolver.php
class RelyingPartyResolver extends \Ssntpl\Neev\Services\RelyingPartyResolver
{
    public function allowSubdomains(): bool
    {
        // Only under the configured relying party — never a tenant's own domain.
        return $this->rpId() === $this->configured();
    }
}

// AppServiceProvider::register()
$this->app->bind(
    \Ssntpl\Neev\Services\RelyingPartyResolver::class,
    \App\Services\RelyingPartyResolver::class,
);
```

Origin matching is on scheme and host; a suffix alone does not qualify: `evil-example.com` is not a
subdomain of `example.com`.

#### Deployment

A tenant domain serving passkeys needs the app served over HTTPS on that host, and the session to
reach it — Laravel's default host-only cookie is fine, but pinning `session.domain` or
`neev.spa.cookie_domain` to the platform domain means a user on `acme.com` never sends the auth
cookie and cannot reach the authenticated registration endpoint.

Passkeys also need a **shared cache store** (redis, memcached, database) across web nodes, as they
always did: the ceremony's challenge is held in the cache between the options and verification
requests, so a per-node `array` or `file` driver breaks passkeys on more than one node.

Both steps of a ceremony must resolve the **same context**. The relying party is derived per request
and not carried across, so a verification that resolves a different context than the options fails
rather than completing under the wrong relying party.

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
curl -X POST https://yourapp.com/neev/passkeys/register/options \
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
    method: 'POST',
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

### OAuth and the MFA Gate

OAuth is a first factor, not a way around the second. When the provider returns
a verified email that matches an account with active MFA, the callback withholds
the session/token exactly as password login does — the API returns the
`mfa_required` state with a temporary JWT, and the web callback redirects to the
OTP challenge page. Whether the provider asked for MFA of its own is the
provider's business and invisible here, so it earns no credit.

The attempt is recorded as `oauth:{provider}` — `oauth:google` — so a provider
named after a built-in method cannot pass for it.

A **passkey** is the one login method that does satisfy the gate on its own: its
ceremony runs with `userVerification: 'required'`, which proves possession of the
authenticator plus a local user check in a single step. See
[Security → MFA and the Login Method](./security.md#mfa-and-the-login-method).

> **Warning — OAuth still skips the password policies.**
>
> OAuth-created accounts are created **without a password**, so complexity rules,
> password history, and password expiry never apply to them, and their email is
> marked verified automatically (it was verified by the provider).

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
- **Note that tenant/team SSO is itself outside the MFA gate**, on both the API and the web side — it issues a full token (API) or an unchallenged session (web) directly, on the assumption that the IdP owns the authentication policy for that organization. Enforce a second factor there if you need one.

### Flow

1. User clicks "Login with Google"
2. Redirected to Google's consent page
3. User authorizes the application
4. Redirected back with auth code
5. System exchanges code for user info
6. User is created or matched
7. If the matched account's address was not yet verified, it is marked verified — the provider authenticated it
8. If the account has an active MFA method, the login stops at the challenge — the web callback redirects to the OTP page, the API returns `auth_state: mfa_required` with the step-up JWT
9. Otherwise, logged in and redirected

For the `email` method the callback also **mails the code itself**, rather than
leaving that to the page it redirects to: a headless install has no page of ours,
so the account would otherwise have nothing to answer with. It shares
`AuthService::sendMfaEmailCode()` with API login and the Blade challenge page,
and that helper leaves a still-valid code alone — so under the kit, where the
challenge page also calls it, the user gets one mail rather than two. See
[MFA → Sending the OTP](./mfa.md#sending-the-otp).

Step 8's redirect target is `EmailLinks::mfaChallengeUrl()` — the Blade kit's
`otp.mfa.create` page when the kit is installed, otherwise
`{base}/mfa-challenge/{method}` on your own frontend. These OAuth routes are
registered whether or not the kit is, so a headless install reaches this hand-off
too; override the method alongside `loginUrl()` if your page lives elsewhere. On a
stateful host the callback also puts the step-up JWT in the auth cookie, so that
page can complete the challenge against `POST {prefix}/mfa/otp/verify`, or ask for a
fresh code with `POST {prefix}/mfa/otp/send` — see
[SPA Authentication](./spa-authentication.md#52-app-wide-oauth-social-login-on-a-stateful-host) and
[Email Links](./email-links.md).

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
| Registering through a team invitation | The link carried the invitation's secret, so it reached that inbox |

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
  the token they issue is a full login token, not a restricted one — except
  that a magic link or OAuth callback for an MFA-enrolled account issues the
  MFA step-up JWT instead, exactly as a password login would (see
  [Magic Link Authentication](#magic-link-authentication) and
  [OAuth and the MFA Gate](#oauth-and-the-mfa-gate)).
- **Web** — the Blade kit's password page (`auth/login-password.blade.php`)
  shows the OAuth buttons, "Login Via Link" and the passkey button whatever
  the account's verification state, so an unverified user is not left with
  only the password they may not have. The `login.link.verify` and
  `oauth.callback` routes sign the user straight in and land on
  `neev.home`, not on `verification.notice` — or, for `login.link.verify` on an
  MFA-enrolled account, on the MFA challenge.

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

Deletes all of the user's login tokens except the current one. Confirmed with
the account's password, or — for an account that has none — with a code from
`POST /neev/confirmation/otp`:

```http
POST /neev/logoutAll
Authorization: Bearer {token}
Content-Type: application/json

{"password": "CurrentPassword123!"}
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
| `multi_factor_method` | Second factor the login demands, named when the challenge opens (null if none) |
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
8. **Be aware that OAuth bypasses the password policies** (MFA still applies) — see [OAuth and the MFA Gate](#oauth-and-the-mfa-gate) for mitigations

---

## Next Steps

- [Multi-Factor Authentication](./mfa.md)
- [Team Management](./teams.md)
- [API Reference](./api-reference.md)
