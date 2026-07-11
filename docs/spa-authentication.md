# SPA Authentication (Cookie Mode)

How to wire a same-origin React/Vue SPA to neev using HttpOnly cookies instead of bearer tokens. This is the practical companion to the [SPA Cookie Mode spec](./spa-cookie-mode.md); endpoint shapes not covered here are in the [API Reference](./api-reference.md).

All URLs below use the default `/neev` route prefix. If you changed `route_prefix` in `config/neev.php` (env `NEEV_ROUTE_PREFIX`), substitute accordingly.

---

## 1. What SPA cookie mode gives you

When your SPA's host is on the stateful list, neev delivers the login token in an **HttpOnly, Secure, SameSite cookie** instead of the JSON response body. Your JavaScript never sees or stores the token — there is nothing in `localStorage` for an XSS payload to exfiltrate, and the session survives full page reloads because the browser manages the cookie. On every API request, the `EnsureSpaRequestsAreStateful` middleware promotes the cookie back to an `Authorization: Bearer …` header internally, so the entire existing token machinery (expiry, per-token permissions, session listing/revocation) works unchanged. State-changing requests are protected by a signed double-submit CSRF token.

**Use bearer mode instead when:**

- Your SPA runs on a host you cannot (or should not) put on the stateful list — e.g. a third-party or cross-origin frontend where cookie scoping doesn't reach.
- You are building a **mobile or desktop app** — no shared cookie jar with the API; keep reading `token` from the JSON body and sending `Authorization: Bearer {token}`.
- The caller is server-to-server.

Bearer clients need no changes and coexist with cookie-mode SPAs on the same backend (see [§6](#6-coexistence-with-bearer-clients)).

---

## 2. Backend setup

### 2.1 Stateful domains

SPA cookie mode is **disabled by default**. Enable it by listing the SPA hosts that may authenticate via cookie:

```env
NEEV_SPA_STATEFUL_DOMAINS=app.example.com,staging.example.com,localhost:5173
```

This populates `config('neev.spa.stateful')`. Each comma-separated entry can be:

| Pattern | Matches |
|---|---|
| `app.example.com` | exactly that host |
| `localhost:5173` | that host **and port** (dev servers) |
| `*.example.com` | any subdomain of `example.com` |

An **empty list disables SPA cookie mode entirely** — every SPA-related code path is a no-op and neev behaves exactly as a bearer-only backend.

The middleware matches the request's `Origin` header (falling back to `Referer`) against this list. Requests from anything else — including all mobile/CLI clients, which send neither header — fall through to the plain bearer path.

### 2.2 Cookie configuration

All keys live under `neev.spa` in `config/neev.php`:

| Key | Env | Default | Notes |
|---|---|---|---|
| `cookie_name` | `NEEV_SPA_COOKIE_NAME` | `neev_session` | HttpOnly auth cookie holding the `{id}\|{plaintext}` token |
| `csrf_cookie_name` | `NEEV_SPA_CSRF_COOKIE_NAME` | `XSRF-TOKEN` | Readable by JS; echoed back in the CSRF header |
| `csrf_header_name` | `NEEV_SPA_CSRF_HEADER_NAME` | `X-XSRF-TOKEN` | Header the server compares against the cookie |
| `cookie_secure` | `NEEV_SPA_COOKIE_SECURE` | `true` | Set `false` for local plain-HTTP dev |
| `cookie_same_site` | `NEEV_SPA_COOKIE_SAME_SITE` | `lax` | `strict` breaks OAuth/SSO callback flows |
| `cookie_domain` | `NEEV_SPA_COOKIE_DOMAIN` | `null` (current host) | Set e.g. `.example.com` when the SPA lives on a sibling subdomain of the API |

### 2.3 CORS (only for non-same-host setups)

If the SPA is served from the **same host** as the API (SPA bundled into the Laravel monolith), skip this — there are no cross-origin requests.

If the SPA runs on a different origin that you've put on the stateful list (a dev server like `localhost:5173`, or a subdomain), configure Laravel's `config/cors.php` so the browser sends and accepts credentials:

```php
'paths' => ['neev/*'],                              // your route_prefix + /*
'allowed_origins' => ['https://app.example.com', 'http://localhost:5173'],
'allowed_headers' => ['*'],
'supports_credentials' => true,                     // required for cookies
```

Notes:

- `supports_credentials => true` is mandatory — without it the browser silently drops the `Set-Cookie` header on cross-origin XHR responses.
- With credentials enabled, `allowed_origins` **cannot be `['*']`**; list exact origins (scheme + host + port).
- Subdomain setups additionally need `cookie_domain` (§2.2) so the cookie is scoped to the shared parent domain.

### 2.4 Cookie encryption — what's handled for you, and the one caveat

Laravel's `EncryptCookies` middleware normally encrypts every cookie. Neev's service provider registers the **auth cookie** in `EncryptCookies::except()` at boot, so it is never encrypted — it must read identically whether it was set on a plain API route or inside a `web`-group redirect (OAuth/SSO callbacks), and it carries an already-opaque token, so encryption adds nothing. **You do not need to do anything for the auth cookie.**

The **CSRF cookie is deliberately not auto-excepted**: its default name (`XSRF-TOKEN`) is shared with Laravel's own web-session CSRF cookie, which must stay encrypted. This is a non-issue in the default setup, because neev's API routes don't run `EncryptCookies` at all and neev attaches its cookies directly to responses (not via the queue). It only matters if you route neev's endpoints through a web-style middleware stack yourself:

- You must then add the CSRF cookie name to `EncryptCookies::except()` so the value JS reads matches the value the server verifies.
- And you should **rename it** first (`NEEV_SPA_CSRF_COOKIE_NAME=NEEV-XSRF-TOKEN`, mirrored in the frontend's `xsrfCookieName`) — excepting the shared `XSRF-TOKEN` name would break Laravel's session CSRF on any Blade pages the same app serves.

---

## 3. Frontend setup

### 3.1 axios

```ts
import axios from 'axios';

axios.defaults.withCredentials = true;        // send + accept cookies
axios.defaults.xsrfCookieName = 'XSRF-TOKEN'; // match neev.spa.csrf_cookie_name
axios.defaults.xsrfHeaderName = 'X-XSRF-TOKEN';

// Once, on app load — prime the CSRF cookie:
await axios.get('/neev/csrf-cookie');   // 204 No Content

// After that, everything just works:
await axios.post('/neev/login', { email, password });
const me = await axios.get('/neev/users');
```

axios automatically reads the `XSRF-TOKEN` cookie and echoes it in the `X-XSRF-TOKEN` header on every request — no interceptor needed. The auth cookie is HttpOnly; your code never touches it.

Add a 419 retry so an expired CSRF token (it lives 2 hours) heals transparently:

```ts
axios.interceptors.response.use(undefined, async (error) => {
    if (error.response?.status === 419 && !error.config._retried) {
        error.config._retried = true;
        await axios.get('/neev/csrf-cookie');
        return axios(error.config);
    }
    throw error;
});
```

### 3.2 fetch

`fetch` does none of this automatically:

```ts
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

// On app load:
await fetch('/neev/csrf-cookie', { credentials: 'include' });

// State-changing requests:
await fetch('/neev/logout', {
    method: 'POST',
    credentials: 'include',                       // send cookies
    headers: { 'X-XSRF-TOKEN': xsrfToken() },     // echo CSRF cookie
});
```

Use `credentials: 'include'` (or `'same-origin'` when SPA and API share the origin) on **every** request, not just state-changing ones — GETs need the auth cookie too.

### 3.3 When the CSRF check applies

`GET /neev/csrf-cookie` returns `204 No Content` and sets the CSRF cookie (not HttpOnly, 2-hour lifetime, throttled to 60/min). The token is HMAC-signed server-side; treat it as opaque.

The CSRF check runs on **POST/PUT/PATCH/DELETE requests from stateful origins** to routes in the `neev:api` and `neev:login` middleware groups (everything authenticated, plus MFA verify). A missing or invalid token yields:

```json
{ "message": "CSRF token mismatch." }
```

with status **419**. GET requests are authenticated by the cookie but skip the CSRF check.

---

## 4. Auth flows

The defining difference from bearer mode: **`token` is omitted from every response to a stateful-origin request** — it travels in the `Set-Cookie` header instead. The rest of each body is unchanged.

### 4.1 Login

```http
POST /neev/login
Content-Type: application/json

{ "email": "john@example.com", "password": "SecurePass123!" }
```

**Response (no MFA)** — `Set-Cookie: neev_session=…; HttpOnly` accompanies it:

```json
{
    "auth_state": "authenticated",
    "expires_in": 1440,
    "mfa_options": null,
    "email_verified": true
}
```

`expires_in` is in minutes and matches the cookie lifetime.

**Response (MFA enabled)** — the cookie now holds a short-lived MFA JWT, not a login token:

```json
{
    "auth_state": "mfa_required",
    "expires_in": 30,
    "mfa_options": ["authenticator", "email"],
    "email_verified": true
}
```

Branch on `auth_state`. In the `mfa_required` state the only useful call is the verify endpoint below (and `POST /neev/mfa/otp/send` variants where applicable); the JWT cookie is rejected everywhere else.

### 4.2 MFA verify

The MFA JWT rides in the cookie automatically — no `Authorization` header needed:

```http
POST /neev/mfa/otp/verify
Content-Type: application/json

{ "auth_method": "authenticator", "otp": "123456" }
```

**Response** — the cookie is **replaced** with the real login token:

```json
{
    "auth_state": "authenticated",
    "expires_in": 1440,
    "mfa_options": null,
    "email_verified": true
}
```

### 4.3 Register

```http
POST /neev/register
Content-Type: application/json

{
    "name": "John Doe",
    "email": "john@example.com",
    "password": "SecurePass123!",
    "password_confirmation": "SecurePass123!"
}
```

**Response** — cookie set, user immediately authenticated:

```json
{
    "auth_state": "authenticated",
    "expires_in": 1440,
    "mfa_options": null,
    "email_verified": false
}
```

#### The "verify your email" waiting screen

`email_verified: false` means your app should show a verification
screen. The verification email carries **both a link and a numeric
code**, and there are two patterns for completing it without leaving
the user stranded:

- **Code entry (recommended for cross-device):** the user reads the
  code from their mail app (possibly on another device) and types it
  into your waiting screen:

  ```http
  POST /neev/email/verify-otp
  { "otp": "123456" }
  ```

  The verification completes *in the waiting session* — no polling, no
  stale tab. Codes expire (`otp_expiry_time`) and die after 5 wrong
  attempts; `POST /neev/email/send` re-issues both proofs.

- **Link + re-check:** if the user clicks the emailed link instead
  (which may open in a different browser), your waiting screen won't
  know. Poll `GET /neev/users` every few seconds — or show an
  "I've verified" button that re-checks — and proceed when
  `email_verified` flips to `true`. Either proof invalidates the
  other, so supporting both is safe.

Your app decides which proofs to surface: the email template is
app-owned (edit `resources/views/vendor/neev/emails/email-verify.blade.php`
to show the link, the code, or both), and your UI decides whether to
render a code input.

### 4.4 Magic link

Request the link:

```http
POST /neev/sendLoginLink

{ "email": "john@example.com" }
```

```json
{ "message": "Login link has been sent." }
```

The emailed link points at your frontend (`{APP_URL}/login-link?id=…&signature=…&expires=…`). Your SPA route at `/login-link` forwards the query string to the API **via XHR** (the XHR carries your stateful `Origin`, which is what triggers the cookie):

```http
GET /neev/loginUsingLink?id={id}&expires={timestamp}&signature={signature}
```

**Response** — cookie set:

```json
{
    "auth_state": "authenticated",
    "expires_in": 1440,
    "mfa_options": null,
    "email_verified": true
}
```

Passkey login (`POST /neev/passkeys/login`) follows the same pattern: identical `authenticated` body, `token` moved into the cookie.

### 4.5 Logout vs logout-all

**`POST /neev/logout`** deletes the current token and **expires the cookie**:

```json
{ "message": "Logged out successfully." }
```

**`POST /neev/logoutAll`** deletes every *other* session's token and **keeps the cookie** — the current session stays signed in:

```json
{ "message": "Logged out from all other devices successfully." }
```

### 4.6 Sessions

Each cookie-mode login is an ordinary login token, so it appears in session management like any other device:

```http
GET /neev/sessions
```

```json
{
    "data": [
        {
            "id": 1,
            "name": "login",
            "last_used_at": "2024-01-15T10:00:00Z",
            "attempt": {
                "ip_address": "192.168.1.1",
                "browser": "Chrome",
                "platform": "macOS",
                "location": "San Francisco, CA, US"
            }
        }
    ]
}
```

Revoke another session (a DELETE, so the CSRF header applies):

```http
DELETE /neev/sessions/{id}
```

```json
{ "message": "Session has been revoked." }
```

Revoking the *current* session this way returns **400** (`You cannot revoke your current session. Use logout instead.`). Revoking a cookie-mode session from another device invalidates that browser's cookie immediately — the token behind it is gone.

### 4.7 Restoring state on page load

There is no client-readable token to check. On boot, call `GET /neev/users`: **200** means the cookie is valid (render the app), **401** means signed out (render login). This replaces any `localStorage.getItem('token')` check from bearer-mode codebases.

---

## 5. SSO and OAuth flows

### 5.1 Tenant SSO → SPA

For tenant-federated identity providers (Microsoft Entra ID, Google Workspace, …):

**Step 1 — discover the tenant's auth method.** Public endpoint, no auth:

```http
GET /neev/tenant/auth
```

```json
{
    "auth_method": "sso",
    "sso_enabled": true,
    "sso_provider": "microsoft",
    "sso_redirect_url": "https://api.example.com/neev/sso/redirect"
}
```

(Without tenant context, or for password tenants: `{ "auth_method": "password", "sso_enabled": false }` — show the password form instead.)

**Step 2 — full-page redirect to the IdP.** This is a browser navigation, not XHR:

```ts
window.location.href =
    '/neev/sso/redirect?redirect_uri=' +
    encodeURIComponent('https://app.example.com/auth/callback');
```

`redirect_uri` is validated against the tenant's registered domains (and the current request host); anything else is ignored. An optional `email` query parameter is passed to the IdP as a login hint.

**Step 3 — callback.** After the IdP round-trip, `GET /neev/sso/callback` creates a login token and redirects the browser to your `redirect_uri`. What it puts where depends on whether the `redirect_uri` host is on the stateful list:

- **Stateful target (SPA cookie mode):** the token is delivered in the HttpOnly cookie on the redirect response and **never appears in the URL**. The fragment carries only metadata:

  ```text
  https://app.example.com/auth/callback#auth_state=authenticated&email_verified=true&expires_in=1440
  ```

  Your `/auth/callback` route just confirms `auth_state=authenticated` (or calls `GET /neev/users`) and routes into the app — the cookie is already set.

- **Non-stateful target (bearer mode):** the token is included in the fragment (fragments are not sent to servers, so they don't leak via logs or referrers):

  ```text
  https://spa.other.com/auth/callback#token=1%7Cabc123...&auth_state=authenticated&email_verified=true&expires_in=1440
  ```

  The SPA extracts `token` from `window.location.hash`, stores it, and sends it as a bearer header thereafter.

Note `email_verified` is the string `"true"`/`"false"` here (URL parameter), not a JSON boolean.

**No `redirect_uri`** means the classic web flow: session login and redirect to `config('neev.home')`.

### 5.2 App-wide OAuth (social login) on a stateful host

For providers listed in `config('neev.oauth')` there are two paths:

- **API flow (recommended for SPAs):** `GET /neev/oauth/{service}/redirect` returns `{ "url": "…" }`; send the browser there; the provider redirects back to your frontend with a `code`, which you POST to `/neev/oauth/{service}/callback`. Because that POST is an XHR from your stateful origin, the response sets the cookie and omits `token` — the same `authenticated` body as login.

- **Web flow:** a full-page navigation to `GET /neev/oauth/{service}` ends in a server-side callback that logs the user into the web session and redirects to `config('neev.home')`. When the request host is itself on the stateful list (SPA served from the Laravel monolith), the callback **also issues a login token in the auth cookie**, so the SPA that loads after the redirect is already authenticated for API calls.

---

## 6. Coexistence with bearer clients

One backend serves both modes simultaneously; nothing is configured per-client.

- Requests without a stateful `Origin`/`Referer` (mobile apps, curl, server-to-server) never touch the SPA path: no CSRF check, `token` stays in the JSON body, no cookies set.
- If a request carries **both** an `Authorization: Bearer …` header and the auth cookie, **the header wins** — the middleware only synthesizes the header when none is present. Precedence is deterministic.
- Token semantics (expiry, permissions, `last_used_at`, revocation) are identical in both modes; a cookie-mode session and a bearer session are the same `access_tokens` row shape.

---

## 7. Troubleshooting

**419 "CSRF token mismatch." on POST/PUT/PATCH/DELETE**

- The SPA never called `GET /neev/csrf-cookie`, or the token expired (2-hour lifetime). Refetch and retry — the interceptor in §3.1 automates this.
- The `X-XSRF-TOKEN` header isn't being sent: check `xsrfCookieName`/`xsrfHeaderName` match your configured names, and that the request goes through the configured axios instance.
- The cookie value is garbled because your app's `web` middleware also sets an (encrypted) `XSRF-TOKEN` cookie on the same host — the shared-name collision from §2.4. Rename neev's CSRF cookie via `NEEV_SPA_CSRF_COOKIE_NAME` and update the frontend to match.
- `APP_KEY` (or `NEEV_JWT_SECRET`) was rotated — old CSRF tokens fail signature verification. Refetching heals it.

**401 even though the auth cookie is set in the browser**

- The request origin isn't on `NEEV_SPA_STATEFUL_DOMAINS`, so the middleware never promoted the cookie to a bearer header. Remember the match is against the **SPA's origin** (scheme-less host, with port if the origin has one): `localhost:5173` and `localhost` are different entries; a subdomain needs an exact entry or a `*.example.com` wildcard.
- The cookie value was encrypted somewhere along the way (e.g. you renamed the auth cookie in config after sessions were issued, or a custom middleware stack encrypts it), so the synthesized bearer token is garbage. Neev auto-excludes its configured `cookie_name` from `EncryptCookies` — verify no other layer re-encrypts it, and log out/in after changing the cookie name.
- The token behind the cookie was revoked or expired (`expires_in` elapsed, session revoked from another device, `logout` elsewhere). This is a normal signed-out state: route to login.

**Cookie never arrives / is never sent back**

- `withCredentials: true` (axios) or `credentials: 'include'` (fetch) missing — the browser then ignores `Set-Cookie` on cross-origin responses and omits cookies on requests.
- CORS misconfigured: `supports_credentials` false, or `allowed_origins` doesn't list the exact SPA origin (§2.3).
- Local dev over plain HTTP with `cookie_secure` left `true` — the browser refuses to store a `Secure` cookie. Set `NEEV_SPA_COOKIE_SECURE=false` locally.
- SPA on `app.example.com`, API on `api.example.com`: a host-scoped cookie set by the API is fine for API calls, but if it isn't sticking, set `NEEV_SPA_COOKIE_DOMAIN=.example.com` so the cookie is valid across the shared parent domain — and keep the SPA host on the stateful list.
- Cross-**site** setups (different registrable domains) won't work with `SameSite=Lax` cookies by design. That topology is what bearer mode is for.

**SSO callback lands with `#token=…` when you expected cookie delivery**

The `redirect_uri` host isn't on the stateful list — the callback fell back to the bearer fragment flow. Add the SPA host to `NEEV_SPA_STATEFUL_DOMAINS`.
