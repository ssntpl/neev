# SPA Cookie Mode

> **Status:** Phase 1 implemented (config, `EnsureSpaRequestsAreStateful`, signed CSRF, `/neev/csrf-cookie`); phases 2+ (login/logout cookie issuance, MFA, OAuth/SSO callbacks) pending per §8
> **Date:** 2026-06-12 (spec), 2026-07-02 (phase 1)
> **Target version:** next minor (additive, no breaking changes)
> **Authors:** TAILLOG team (driving), neev maintainers (review)
> **Drivers:** [TAILLOG web app rebuild](../../TAILLOG/Documentation/5-Admin-Portal/web-app-spa.md), otper SPA
>
> **Implementation notes (phase 1):** the origin check lives in `Services\StatefulOriginResolver` (per §13.4); CSRF tokens are `value.hmac` signed via `SpaCsrfToken` (per §5.4); the middleware is in both `neev:api` and `neev:login` groups (per §5.7.1); stateful patterns match exact host, `host:port`, and `*.wildcard` (resolves §13.6); cookies are issued unencrypted and attached directly to responses — apps running `EncryptCookies` must add both cookie names to its `$except` list (resolves §13.5).

## 1. Problem

Neev's API auth today is bearer-token only. `NeevAPIMiddleware`
reads `$request->bearerToken()` (`src/Http/Middleware/NeevAPIMiddleware.php:23`)
and rejects requests without an `Authorization: Bearer …` header.

For mobile clients this is fine. For **same-origin web SPAs**
(React/Vue apps served from the same host as the API) it forces an
unattractive trade-off:

- Store the bearer token in `localStorage` or `sessionStorage` →
  any XSS vulnerability exfiltrates the token.
- Hold the token in JS memory only → token lost on page refresh,
  forcing a re-login on every navigation that bypasses the
  router (full reloads, opening links in new tabs).

The standard same-origin SPA pattern — HttpOnly cookie carrying the
auth token, plus a double-submit CSRF cookie — solves both. Laravel
Sanctum ships this as "Sanctum SPA mode". Neev does not.

Two SSNTPL applications need this immediately:

- **TAILLOG operator portal rebuild** (React 19 + TS, served from
  the same Laravel monolith as `/neev/*`). Drives this RFC.
- **otper** (React SPA, same general topology).

Both teams have decided to standardise on neev for auth (so they
can adopt MFA, OAuth, magic links, passkeys as a single roadmap
item). Without SPA cookie mode in neev, each team would build its
own ~50–100 LOC wrapper to bridge HttpOnly cookies into
`Authorization: Bearer …` — i.e., reinvent Sanctum's pattern
per-app. This document proposes the alternative: build it once in
neev.

## 2. Goals

- Same-origin web SPAs can authenticate against neev via
  HttpOnly + Secure + SameSite cookies, with CSRF protection,
  without storing tokens in JS-accessible storage.
- The cookie path is **opt-in per consuming application** via
  `config/neev.php` and is a **no-op** when the request origin
  isn't on the stateful list.
- **No changes to existing API consumers.** iOS (and any other
  `Authorization: Bearer …` caller) keeps working unchanged.
- Existing `NeevAPIMiddleware` logic is reused, not duplicated.
- Compatible with the standard axios SPA setup
  (`withCredentials: true` + automatic `XSRF-TOKEN` /
  `X-XSRF-TOKEN` interop) so consuming React/Vue apps need
  minimal client wiring.
- Token model unchanged. The cookie carries an `AccessToken` in
  the same `{id}|{plaintext}` format the bearer header uses today.
  All existing token machinery (expiry, `last_used_at`,
  per-token permissions, MFA token type gating) keeps working.

## 3. Non-goals

- **Replace neev's Blade-form web auth.** The `neev:web` route
  group and its session-based auth (login forms, Blade
  templates) is unaffected.
- **Session-backed auth for SPAs.** This proposal carries the
  token via cookie, not a Laravel session ID. Neev API auth
  remains stateless DB-token auth; the cookie is the delivery
  mechanism only. (See §11 for the rejected alternative.)
- **Multiple concurrent cookie-borne tokens per browser.** One
  cookie, one active token per origin. Same as Sanctum SPA mode.
- **Add or change permission semantics.** Token-level
  permissions (`AccessToken::can()`) keep working identically.
- **iOS migration tooling.** Consuming apps that move from
  Sanctum to neev write their own migration; out of scope here.

## 4. Background

### 4.1 How API auth works today

Token validation (`src/Http/Middleware/NeevAPIMiddleware.php:21-69`):

```php
public function handle(Request $request, Closure $next): Response
{
    $token = $request->bearerToken();

    if (! $token || ! str_contains($token, '|')) {
        return response()->json(['message' => 'Missing token'], 401);
    }

    [$id, $token] = explode('|', $token, 2);
    $accessToken = AccessToken::with('attempt')->find($id);

    if (! $accessToken
        || ! Hash::check($token, $accessToken->token)
        || ($accessToken->token_type == AccessToken::mfa_token
            && ! $request->is(['neev/mfa/otp/verify', 'neev/mfa']))) {
        return response()->json(['message' => 'Invalid or expired token'], 401);
    }
    // … expiry check, user lookup, last_used_at update, Auth::setUser()
}
```

Login response shape today
(`src/Http/Controllers/Auth/UserAuthApiController.php:193-200`):

```json
{
    "auth_state": "authenticated",
    "token": "1|abc123def456...",
    "expires_in": 1440,
    "mfa_options": null,
    "email_verified": true
}
```

Token format: `{id}|{plaintext}`. Server hashes the plaintext via
the `token` cast (`hashed`) and stores both halves in
`access_tokens`.

### 4.2 `neev:api` middleware group

Defined in `src/NeevServiceProvider.php:57-63`:

```php
Route::middlewareGroup('neev:api', [
    TenantMiddleware::class,
    ResolveTeamMiddleware::class,
    NeevAPIMiddleware::class,
    EnsureTenantMembership::class,
    BindContextMiddleware::class,
]);
```

`BindContextMiddleware` must remain last in every group (locks
the request context immutable). This proposal preserves that
constraint.

## 5. Design

### 5.1 Approach

**Add a new middleware,
`Ssntpl\Neev\Http\Middleware\EnsureSpaRequestsAreStateful`,
positioned immediately before `NeevAPIMiddleware` in the
`neev:api` group.**

The new middleware:

1. Determines whether the incoming request is from a
   configured stateful SPA origin (Origin / Referer match
   against `config('neev.spa.stateful')`).
2. If yes, and a SPA auth cookie is present, extracts the
   `{id}|{plaintext}` token from the cookie and rewrites the
   request to carry it as `Authorization: Bearer …`.
3. If yes, and the HTTP method is state-changing
   (POST/PUT/PATCH/DELETE), validates a double-submit CSRF
   token before allowing the request to proceed.
4. If no (request is not from a stateful origin), is a **no-op**.

`NeevAPIMiddleware` itself is **unchanged**. It continues to read
`$request->bearerToken()`. From its perspective the request always
looks bearer-authenticated, regardless of whether the bearer header
came from the client or was synthesised from the cookie upstream.

This satisfies neev's architecture principle of "no type
conditionals" (`docs/architecture.md`): there is no
`if ($isSpa) { … } else { … }` inside `NeevAPIMiddleware`.
Polymorphism via middleware layering instead.

### 5.2 Middleware group change

```php
// src/NeevServiceProvider.php (after change)
Route::middlewareGroup('neev:api', [
    TenantMiddleware::class,
    ResolveTeamMiddleware::class,
    EnsureSpaRequestsAreStateful::class, // NEW
    NeevAPIMiddleware::class,            // unchanged
    EnsureTenantMembership::class,
    BindContextMiddleware::class,
]);
```

Consuming apps don't need to update their route definitions; the
new middleware joins the existing group transparently.

### 5.3 `EnsureSpaRequestsAreStateful` — implementation

```php
namespace Ssntpl\Neev\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSpaRequestsAreStateful
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->fromStatefulOrigin($request)) {
            return $next($request);
        }

        if ($this->isStateChanging($request) && ! $this->csrfValid($request)) {
            return response()->json(['message' => 'CSRF token mismatch'], 419);
        }

        $cookieName = config('neev.spa.cookie_name', 'neev_session');
        $token = $request->cookie($cookieName);

        if ($token && ! $request->bearerToken()) {
            $request->headers->set('Authorization', 'Bearer '.$token);
            $request->attributes->set('neev.spa', true);
        }

        return $next($request);
    }

    public function fromStatefulOrigin(Request $request): bool
    {
        // Public so SpaCookieResponder and any future caller can share
        // this check. Alternative: extract into a Ssntpl\Neev\Services\
        // StatefulOriginResolver and have both classes consume it.
        $stateful = config('neev.spa.stateful', []);
        if (empty($stateful)) {
            return false;
        }

        $source = $request->headers->get('Origin')
            ?? $request->headers->get('Referer');

        if (! $source) {
            return false;
        }

        $host = parse_url($source, PHP_URL_HOST);
        if (! $host) {
            return false;
        }

        foreach ($stateful as $pattern) {
            if ($this->matchesHost($host, $pattern)) {
                return true;
            }
        }

        return false;
    }

    protected function matchesHost(string $host, string $pattern): bool
    {
        // Sanctum-compatible: exact match or *.suffix wildcard.
        if ($host === $pattern) {
            return true;
        }
        if (str_starts_with($pattern, '*.')
            && str_ends_with($host, substr($pattern, 1))) {
            return true;
        }
        return false;
    }

    protected function isStateChanging(Request $request): bool
    {
        return in_array(
            strtoupper($request->method()),
            ['POST', 'PUT', 'PATCH', 'DELETE'],
            true,
        );
    }

    protected function csrfValid(Request $request): bool
    {
        $cookieName = config('neev.spa.csrf_cookie_name', 'XSRF-TOKEN');
        $headerName = config('neev.spa.csrf_header_name', 'X-XSRF-TOKEN');

        $fromCookie = $request->cookie($cookieName);
        $fromHeader = $request->header($headerName);

        if (! $fromCookie || ! $fromHeader) {
            return false;
        }

        return hash_equals($fromCookie, $fromHeader);
    }
}
```

The `neev.spa` request attribute is set when the middleware
synthesises the Authorization header. Downstream code (e.g. the
logout controller) reads this flag to know whether to clear the
cookie on response.

### 5.4 CSRF — double-submit cookie pattern

A new endpoint:

```
GET /neev/csrf-cookie
```

Returns `204 No Content` and sets:

- `XSRF-TOKEN` cookie — an **HMAC-signed** token (random value plus a
  signature keyed to `APP_KEY`), **not** HttpOnly (JS needs to read
  it to echo into the header), Secure (in production), SameSite=Lax,
  expires in 2 hours by default.
- The cookie name is configurable
  (`config('neev.spa.csrf_cookie_name')`).

> **Do not use a bare `Str::random(40)` compared with
> `hash_equals()`.** An unsigned token is the naive double-submit
> variant and is defeated by subdomain cookie injection (see §10). Sign
> the token to `APP_KEY` (as Laravel/Sanctum do) so a value an attacker
> writes into the cookie from a sibling subdomain fails verification.
> `csrfValid()` in §5.3 must verify the signature, not just compare
> cookie-value against header-value. The illustrative code in §5.3
> shows the plain-compare form for brevity; the signed form is
> normative.

The SPA hits this once on app load (and on 419 retry). On
state-changing requests it reads the `XSRF-TOKEN` cookie value
and sends it in the `X-XSRF-TOKEN` header.

`EnsureSpaRequestsAreStateful::csrfValid()` compares the two with
`hash_equals()`. No server-side state required (no Laravel
session, no DB row).

This is the textbook double-submit cookie defence. It works
because:

- An attacker on `evil.com` can submit cross-origin requests to
  `app.taillog.aero` (CSRF) but **cannot read** the `XSRF-TOKEN`
  cookie (Same-Origin Policy), and therefore cannot set a
  matching `X-XSRF-TOKEN` header.
- The cookie is intentionally not HttpOnly because JS must read
  it; this is safe because knowing the token alone is useless
  without same-origin script access.
- Combined with SameSite=Lax on the auth cookie, cross-site form
  submissions can't piggyback on the session at all.

axios picks up `XSRF-TOKEN` / `X-XSRF-TOKEN` automatically when
`withCredentials: true`. Consuming SPAs need no custom interceptor.

### 5.5 Login — cookie-setting

`POST /neev/login`, `POST /neev/login/link`,
`GET /neev/loginUsingLink`, `POST /neev/mfa/otp/verify`, and the
OAuth callback all need to set the auth cookie when the request
is from a stateful SPA origin.

Approach: extract a helper on the controller (or a new service
method) that wraps the existing JSON response and, when SPA mode
applies, queues the auth cookie via Laravel's `Cookie::queue()`.

```php
// src/Support/SpaCookieResponder.php (new)
namespace Ssntpl\Neev\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

class SpaCookieResponder
{
    public function withAuthCookie(
        Request $request,
        JsonResponse $response,
        string $plainTextToken,
        int $expiryMinutes,
    ): JsonResponse {
        if (! $this->isSpaRequest($request)) {
            return $response;
        }

        Cookie::queue(
            config('neev.spa.cookie_name', 'neev_session'),
            $plainTextToken,
            $expiryMinutes,
            path: '/',
            domain: config('neev.spa.cookie_domain'),
            secure: config('neev.spa.cookie_secure', true),
            httpOnly: true,
            sameSite: config('neev.spa.cookie_same_site', 'lax'),
        );

        // Strip the token from the body when delivering via cookie.
        $payload = $response->getData(true);
        unset($payload['token']);
        return $response->setData($payload);
    }

    protected function isSpaRequest(Request $request): bool
    {
        // Same origin check as EnsureSpaRequestsAreStateful::fromStatefulOrigin.
        // Extract to a shared trait or service in implementation.
        return app(EnsureSpaRequestsAreStateful::class)
            ->fromStatefulOrigin($request);
    }
}
```

Updated login response shape for SPA callers:

```json
{
    "auth_state": "authenticated",
    "expires_in": 1440,
    "mfa_options": null,
    "email_verified": true
}
```

The `token` field is **omitted** for SPA responses (the token
lives in the cookie). Non-SPA callers (iOS, server-to-server) get
the existing body shape including `token` and no cookie is set.

### 5.6 Logout — cookie-clearing

`POST /neev/logout`
(`src/Http/Controllers/Auth/UserAuthApiController.php:269-292`)
already deletes the `AccessToken` row. Extension:

```php
// pseudocode addition at the end of logout()
$response = response()->json(['message' => 'Logged out successfully.']);

if ($request->attributes->get('neev.spa') === true) {
    $response->withCookie(
        Cookie::forget(config('neev.spa.cookie_name', 'neev_session')),
    );
}

return $response;
```

`POST /neev/logoutAll` clears the cookie the same way (in addition
to deleting all the user's tokens).

### 5.7 MFA flow

> **Important:** the MFA step-up token is **not** an `AccessToken`.
> `UserAuthApiController::login()` issues a short-lived **JWT**
> (`getJwtToken($user->id, "mfa", …)`,
> `src/Http/Controllers/Auth/UserAuthApiController.php`), and
> `POST /neev/mfa/otp/verify` is protected by the **`neev:login`**
> group (`JwtLoginMiddleware`), **not** `neev:api`
> (`routes/neev.php`). The `AccessToken::mfa_token` type referenced
> in `NeevAPIMiddleware.php:34` is a separate, legacy concept and is
> **not** what the password-login MFA path produces. Any spec that
> assumes MFA runs through `NeevAPIMiddleware` is wrong for this
> flow. This section is corrected accordingly.

The MFA flow has two response shapes to cover:

```text
POST /neev/login           → 200, auth_state: "mfa_required", carries mfa JWT
POST /neev/mfa/otp/verify  → 200, auth_state: "authenticated", carries api_token
```

For SPA mode:

1. On the `mfa_required` response, `SpaCookieResponder` sets
   `neev_session` = the **MFA JWT** (short-lived) and, as with the
   authenticated response, **strips `token` from the body**. Note
   the login response carries `token` in *both* the `mfa_required`
   and `authenticated` shapes today
   (`UserAuthApiController.php:181-200`) — the responder must handle
   both, not just the authenticated one.
2. `POST /neev/mfa/otp/verify` must read that cookie and present the
   JWT to `JwtLoginMiddleware`. **This route is in `neev:login`, so
   the `neev:api`-only placement of
   `EnsureSpaRequestsAreStateful` (§5.2) does not cover it.** The
   cookie→credential bridging for the verify step must therefore be
   added to the `neev:login` group as well (or the middleware placed
   in a shared position both groups include). See §5.7.1.
3. On successful verify, `SpaCookieResponder` replaces `neev_session`
   with the real `api_token` and strips `token` from the body.

### 5.7.1 Middleware coverage of auth-issuing routes

`EnsureSpaRequestsAreStateful` in §5.2 is added to `neev:api` only.
That group covers routes that *consume* a token, but the routes that
*issue* or *step up* a token live in other groups and are **not**
covered:

| Route | Group today | Needs SPA handling for |
|---|---|---|
| `POST /neev/login` | `throttle` + `TenantMiddleware` | set cookie (via responder) |
| `POST /neev/mfa/otp/verify` | `neev:login` | **read** cookie → JWT, then set cookie |
| `GET /neev/loginUsingLink` | `TenantMiddleware` | set cookie |
| OAuth / SSO callback | (web) | set cookie |

The cookie-*setting* side is handled independently by
`SpaCookieResponder` (§5.5), which calls `fromStatefulOrigin()`
directly and does not depend on middleware placement. The
cookie-*reading* side (synthesising a credential from the cookie)
currently only runs for `neev:api`. The MFA verify step is the one
issuing route that must *read* a cookie, so §8's PR4 must extend the
reading path to `neev:login`. This is called out here so the
"`NeevAPIMiddleware` unchanged, one middleware in one group" framing
of §5.1 is not mistaken for full coverage.

### 5.8 OAuth / SSO callbacks

OAuth and tenant-SSO callbacks redirect a full-page browser
navigation back to the app. When the consuming app is in SPA
mode, the callback should:

1. Set the auth cookie via `SpaCookieResponder` (same as login).
2. Redirect to `config('neev.home')` or the original `intended`
   URL.

The SPA then loads, sees the cookie present (or hits `/neev/user`
to confirm), and renders the authenticated state.

This closes the open `TODO.md` item:
> "SSO SPA flow documentation — No docs on how a SPA initiates
> SSO login, receives the token after callback…"

### 5.9 Route additions

In `routes/neev.php`, inside the `/neev` prefix group, add:

```php
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/csrf-cookie', [CsrfCookieController::class, 'show'])
        ->name('neev.csrf-cookie');
});
```

A trivial controller:

```php
namespace Ssntpl\Neev\Http\Controllers\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

class CsrfCookieController
{
    public function show(Request $request): Response
    {
        Cookie::queue(
            config('neev.spa.csrf_cookie_name', 'XSRF-TOKEN'),
            Str::random(40),
            120, // 2 hours
            path: '/',
            domain: config('neev.spa.cookie_domain'),
            secure: config('neev.spa.cookie_secure', true),
            httpOnly: false, // JS must read this
            sameSite: 'lax',
        );

        return response()->noContent();
    }
}
```

## 6. Configuration

Additions to `config/neev.php`:

```php
'spa' => [
    /*
    |----------------------------------------------------------------------
    | Stateful SPA Origins
    |----------------------------------------------------------------------
    |
    | Hosts that may authenticate against neev via HttpOnly cookies. The
    | EnsureSpaRequestsAreStateful middleware matches Origin / Referer
    | against this list; non-matching requests fall through to the
    | bearer-token path unchanged.
    |
    | Supports exact match ("app.example.com") and prefix wildcards
    | ("*.example.com"). Empty list disables SPA cookie mode entirely.
    |
    */
    'stateful' => array_filter(explode(',', (string) env(
        'NEEV_SPA_STATEFUL_DOMAINS',
        '',
    ))),

    /*
    |----------------------------------------------------------------------
    | Cookie names
    |----------------------------------------------------------------------
    |
    | Auth cookie holds the {id}|{plaintext} access token, HttpOnly.
    | CSRF cookie holds a random token the SPA echoes in the X-XSRF-TOKEN
    | header; not HttpOnly, since JS must read it.
    |
    */
    'cookie_name' => env('NEEV_SPA_COOKIE_NAME', 'neev_session'),
    'csrf_cookie_name' => env('NEEV_SPA_CSRF_COOKIE_NAME', 'XSRF-TOKEN'),
    'csrf_header_name' => env('NEEV_SPA_CSRF_HEADER_NAME', 'X-XSRF-TOKEN'),

    /*
    |----------------------------------------------------------------------
    | Cookie attributes
    |----------------------------------------------------------------------
    */
    'cookie_secure' => (bool) env('NEEV_SPA_COOKIE_SECURE', true),
    'cookie_same_site' => env('NEEV_SPA_COOKIE_SAME_SITE', 'lax'),
    'cookie_domain' => env('NEEV_SPA_COOKIE_DOMAIN'),
],
```

Naming: snake_case, under a `spa` sub-array, env-overridable.
Consistent with the existing `oauth`, `maxmind`, `login_throttle`
config groups.

Defaults are safe-for-production: `secure = true`,
`same_site = lax`, `stateful = []` (disabled).

## 7. Backward compatibility

Purely additive.

- Existing API consumers send `Authorization: Bearer …` →
  `EnsureSpaRequestsAreStateful` short-circuits on no-stateful-origin
  → `NeevAPIMiddleware` runs unchanged. **No behaviour change.**
- Consuming apps that don't configure `NEEV_SPA_STATEFUL_DOMAINS`
  see no behaviour change at all.
- Existing login response keeps returning `token` in the body for
  non-SPA callers. SPA callers receive the same body shape minus
  the `token` field (cookie carries it instead).
- Token model, expiry semantics, MFA flow, permission checking —
  all unchanged.
- iOS does not need to update. It continues hitting `/neev/login`,
  receiving a `token` in the body, and sending it in
  `Authorization: Bearer …`.

Semver: **MINOR bump** (additive feature, no breaking change).
Target release: **v0.4.5**.

## 8. Implementation phases

Suggested PR breakdown (rough — implementer's discretion):

| PR | Scope | Notes |
|---|---|---|
| 1 | Config additions + `EnsureSpaRequestsAreStateful` middleware + `CsrfCookieController` + `/neev/csrf-cookie` route | Pure plumbing. No login/logout changes yet. Tests for stateful-origin detection, CSRF double-submit, no-op behaviour. |
| 2 | `SpaCookieResponder` support class + login flow (`POST /neev/login`, password step, magic link consume) | Auth cookie set on successful login. Existing JSON shape preserved for non-SPA. |
| 3 | Logout cookie clearing (`/neev/logout`, `/neev/logoutAll`) | Reads `neev.spa` request attribute. |
| 4 | MFA cookie flow (`POST /neev/mfa/otp/verify`) | MFA JWT in cookie, swapped for `api_token` on verify. |
| 5 | OAuth + SSO callbacks set the auth cookie + redirect | Closes the `TODO.md` SSO SPA documentation gap. |
| 6 | Docs (this file finalised, `README.md`, `authentication.md`, `api-reference.md`, `security.md` updates) + the two `TODO.md` items closed | Last; merge after the spec is stable. |

Estimated implementation effort: **1.5–2 weeks for one engineer**,
plus review + release cycle.

## 9. Testing strategy

Pattern follows the existing
`tests/Unit/NeevServiceProviderTest.php` style (PHPUnit, snake_case
method names, `assertContains` etc.).

Required coverage:

- **`EnsureSpaRequestsAreStateful` unit tests**
  - No stateful config → middleware is no-op.
  - Origin matches exact pattern → cookie extracted, Authorization
    synthesised.
  - Origin matches wildcard pattern (`*.example.com`).
  - Origin doesn't match → no-op.
  - Origin absent, Referer matches → still recognised.
  - Both Origin and Referer absent → no-op (no leak of
    cookie-borne auth to non-browser callers).
  - POST without CSRF token → 419.
  - POST with mismatched CSRF → 419.
  - POST with matching CSRF → proceed.
  - GET without CSRF → proceed (CSRF check is method-scoped).
  - Bearer header already present → don't overwrite with cookie.
- **`CsrfCookieController` unit tests**
  - Returns 204.
  - Sets `XSRF-TOKEN` cookie with the configured name.
  - Cookie is NOT HttpOnly.
  - Cookie respects `cookie_secure`, `cookie_same_site` config.
- **Login flow integration tests**
  - SPA login response omits `token` and sets `neev_session`
    cookie.
  - Non-SPA login response includes `token` and does not set
    cookie.
  - Cookie attributes match config (`HttpOnly`, `Secure`,
    `SameSite`).
- **Logout integration test**
  - SPA logout clears `neev_session` cookie via
    `Cookie::forget()`.
  - Non-SPA logout does not touch cookies.
- **MFA flow integration test**
  - Password step sets cookie containing MFA JWT.
  - Verify step replaces cookie with `api_token`.
- **End-to-end coexistence test**
  - One request with bearer header + matching stateful origin
    → bearer wins (cookie ignored, no overwrite). Documents the
    precedence rule.
  - Iterate: SPA cookie request and bearer header request can
    hit the same backend in succession without state pollution.

## 10. Security considerations

- **HttpOnly auth cookie + double-submit CSRF cookie** is the
  canonical OWASP-recommended pattern for same-origin SPAs. No
  novel cryptography or trust assumptions.
- **SameSite=Lax** (default) prevents cross-site form/navigation
  CSRF; SameSite=Strict is overkill and breaks OAuth-callback
  flows. Lax is the right default; consumers can override.
- **Secure=true** in production: cookie only sent over HTTPS.
  Default. Override per environment (e.g. `false` in local dev
  over plain HTTP).
- **CSRF check is method-scoped.** Only POST/PUT/PATCH/DELETE
  validate the double-submit. GET requests are still
  authenticated by the cookie but skip CSRF, which is correct —
  safe-method browser requests can't change server state.
- **`hash_equals`** for the CSRF comparison prevents timing
  attacks.
- **Token revocation** is unchanged: `AccessToken::delete()`
  from any source (logout, admin action, future token-management
  UI) immediately invalidates both bearer-header and
  cookie-borne uses of that token, because both code paths
  resolve to the same `access_tokens.id` row.
- **Cookie scope.** The cookie is set with `path=/` and the
  configured domain. Sub-path scoping is not exposed; consumers
  that need it can override via config in a follow-up.
- **No mixed-source token confusion.** If a request arrives with
  both an `Authorization: Bearer …` header AND the
  `neev_session` cookie, the middleware leaves the bearer header
  alone (`if (! $request->bearerToken())`). The header always
  wins. This makes coexistence deterministic.
- **Stateful-origin allowlist.** Only origins explicitly listed
  in `config('neev.spa.stateful')` can authenticate via cookie.
  An attacker who steals an HttpOnly cookie via subdomain
  takeover or unrelated XSS still can't cross into a non-listed
  origin.

Known limitations:

- **CSRF token does not bind to user / session.** The
  double-submit is origin-only — it proves the request came from
  same-origin script, not from a specific authenticated user.
  This is the standard double-submit limitation; binding would
  require server-side state and defeats the stateless goal. The
  HttpOnly auth cookie + SameSite=Lax compensates.
- **Subdomain cookie injection.** Plain double-submit is defeated
  if any host under the cookie domain is attacker-influenced: a
  sibling subdomain (or one vulnerable to response/header
  injection) can set an `XSRF-TOKEN` cookie on the parent domain
  that the victim's browser then echoes into `X-XSRF-TOKEN`,
  producing a matching pair the server accepts. This is the more
  practically exploitable weakness of the two and is **why §5.4
  signs the token to `APP_KEY`** — an injected value that the
  attacker cannot sign fails verification. Without signing, this
  design is not safe on any deployment that shares a cookie domain
  with untrusted subdomains.
- **Cookie encryption must be consistent between set and read.**
  Laravel's `EncryptCookies` middleware transparently encrypts
  cookies. If it runs for the auth cookie on the way out but not on
  the way in (or vice versa), the synthesised bearer will be
  garbage; if it encrypts `XSRF-TOKEN`, JS reads the encrypted
  value but the server compares the decrypted one and CSRF breaks.
  Sanctum resolves this by adding `XSRF-TOKEN` to
  `EncryptCookies::$except`. See open question §13.5.
- **No automatic CSRF rotation.** The token lives 2 hours then
  expires; SPA hits `/neev/csrf-cookie` again. Sufficient for
  most threat models. Per-request rotation is possible but adds
  complexity and is not proposed for v1.

## 11. Rejected alternatives

### 11.1 Carry an opaque session ID in the cookie, map to token server-side

The cookie would hold `sess_<random>`, neev would look up the
session row in a new `neev_sessions` table, the row would
reference `access_tokens.id`. Two indirections instead of one.

**Why rejected for v1:**

- Adds a new table + lifecycle.
- The current token model already supports revocation via
  `DELETE FROM access_tokens` — no need to layer a session table
  on top.
- The cookie-token pattern is exactly equivalent in security
  surface area (HttpOnly cookies are opaque to JS either way).
- Sanctum SPA mode itself doesn't use this indirection; it
  stores the API token in the Laravel session, which is the
  same idea with extra plumbing.

Potential future work if needed: a `sessions` table that lets
admins list / revoke per-device sessions from a UI. Independent
of this proposal.

### 11.2 Use Laravel sessions (web guard) for SPA auth

The Sanctum SPA mode literal: hand the SPA off to Laravel's
session driver and `web` guard, log the user in via
`Auth::login()`, ignore neev's `AccessToken` model.

**Why rejected:**

- Bypasses every piece of neev's API auth machinery: per-token
  permissions (`AccessToken::can()`), `last_used_at` tracking,
  MFA-token type gating, the `attempt_id` link to login history.
- Forks neev's auth into two parallel paths that have to be
  kept in sync forever.
- Forces consuming apps to use Laravel sessions for the SPA but
  neev tokens for mobile, doubling test surface.

### 11.3 Per-app middleware wrapper (Option B from the TAILLOG plan)

Each consuming app builds its own ~50–100 LOC middleware that
reads a cookie and synthesises a bearer header. neev unchanged.

**Why rejected:**

- TAILLOG and otper would each maintain their own copy.
- CSRF, cookie attributes, stateful-origin handling would drift
  per-app.
- Closing the `TODO.md` "SSO SPA flow" / "CORS/SPA guidance"
  gaps would still require neev-side work to document.
- One implementation in the package, two consumers, zero drift
  is strictly better.

## 12. Migration guide for consuming apps

Once v0.4.5 ships, a consuming Laravel + SPA app adopts SPA
cookie mode as follows.

### 12.1 Backend (Laravel)

1. `composer require ssntpl/neev:^0.4.5`.
2. Publish updated config or re-merge config diff for the new
   `spa` block.
3. Set environment:

   ```env
   NEEV_SPA_STATEFUL_DOMAINS=app.example.com,staging.example.com,localhost:3000
   NEEV_SPA_COOKIE_SECURE=true    # false for local HTTP dev
   ```

4. Ensure CORS allows the SPA origin and credentials
   (Laravel's `config/cors.php`):

   ```php
   'paths' => ['neev/*', 'api/*'],
   'allowed_origins' => ['https://app.example.com'],
   'supports_credentials' => true,
   ```

5. Nothing in route definitions changes. The `neev:api` group
   already includes the new middleware.

### 12.2 Frontend (React/Vue SPA via axios)

```ts
import axios from 'axios';

axios.defaults.withCredentials = true;
axios.defaults.xsrfCookieName = 'XSRF-TOKEN';
axios.defaults.xsrfHeaderName = 'X-XSRF-TOKEN';

// On app load:
await axios.get('/neev/csrf-cookie');

// Then everything else just works:
await axios.post('/neev/login', { email, password });
const me = await axios.get('/neev/users');
```

The SPA does not handle the auth cookie at all — it's HttpOnly
and the browser manages it. axios reads/echoes the CSRF cookie
automatically.

### 12.3 Coexisting iOS app

No changes needed. iOS keeps calling `POST /neev/login` and
reading `token` from the JSON body. Because iOS doesn't send an
`Origin` header (and its Referer doesn't match the stateful
list), `EnsureSpaRequestsAreStateful` short-circuits and the
existing bearer flow runs unchanged.

## 13. Open questions

These remain for resolution before / during implementation:

1. **`Origin` vs `Referer` precedence.** §5.3 falls back to
   Referer when Origin is absent. Is that the right policy, or
   should Referer-only requests be rejected to avoid Referer
   spoofing? Sanctum trusts the Referer fallback; following
   suit unless there's a known threat.
2. **Cookie domain default.** §6 leaves `cookie_domain` null
   (current host). For deployments serving the SPA on a
   subdomain of the API, consumers would set this explicitly.
   Is documenting this in the migration guide sufficient, or
   should we add detection?
3. **Should `/neev/csrf-cookie` rate-limit beyond
   `throttle:60,1`?** Each call rotates the CSRF token, which
   could be abused to fill a victim's cookie jar. 60/min seems
   defensible; lower if abuse becomes a pattern.
4. **`SpaCookieResponder` invocation point.** §5.5 sketches a
   shared support class. Alternative: a trait or controller
   middleware that intercepts the JSON response on its way
   out. The trait keeps the auth controllers thin; the
   middleware is more out-of-the-way. Implementer's call;
   default to whichever fits neev's existing service patterns
   best. Related: §5.5's `isSpaRequest()` instantiates the
   middleware as a service to reuse `fromStatefulOrigin()`. Prefer
   extracting a `Ssntpl\Neev\Services\StatefulOriginResolver`
   that both the middleware and the responder consume, rather than
   `app(EnsureSpaRequestsAreStateful::class)`.

5. **Cookie encryption and the required middleware stack.** The
   `neev:api` group (`NeevServiceProvider.php`) does not include
   `EncryptCookies` or `AddQueuedCookiesToResponse`, so whether
   cookies are decrypted on read / attached on write depends on the
   consuming app's outer group (`web` vs `api`). This must be
   pinned down before implementation:
   - Does `EnsureSpaRequestsAreStateful` read a **decrypted** or
     **raw** `neev_session`? (i.e. is `EncryptCookies` guaranteed
     upstream?)
   - Does `Cookie::queue()` from `SpaCookieResponder` /
     `CsrfCookieController` reliably reach the response, and is the
     auth cookie encrypted symmetrically on read?
   - `XSRF-TOKEN` almost certainly needs to go in
     `EncryptCookies::$except` (Sanctum does this) so JS-read and
     server-compared values match.
   - What middleware group should `/neev/csrf-cookie` (§5.9) use?
     As written it sits under `TenantMiddleware` only, which won't
     run the cookie/queue middleware needed to emit the cookie.
   Recommendation: document the required cookie-middleware stack
   explicitly and, if neev cannot guarantee it via its own groups,
   add it to `neev:api` (before `NeevAPIMiddleware`) so behaviour
   is deterministic regardless of the consumer's outer group.

6. **`localhost:3000`-style origins with ports.** §12.1's example
   stateful list includes `localhost:3000`, but
   `fromStatefulOrigin()` matches against `parse_url(…, PHP_URL_HOST)`,
   which strips the port — so `localhost:3000` would be compared
   against host `localhost` and never match. Sanctum stores and
   matches host+port. Decide whether to strip ports from both sides
   or match host:port explicitly, and document it.

## 14. References

- **Driver doc:**
  [`TAILLOG/Documentation/5-Admin-Portal/web-app-spa.md`](../../TAILLOG/Documentation/5-Admin-Portal/web-app-spa.md)
  — auth migration section, Option C decision rationale.
- **Driver doc:**
  [`TAILLOG/Documentation/5-Admin-Portal/web-app-spa-rebuild-plan.md`](../../TAILLOG/Documentation/5-Admin-Portal/web-app-spa-rebuild-plan.md)
  — Section 11 "Upstream neev work".
- **Neev internals:**
  [`docs/authentication.md`](./authentication.md),
  [`docs/api-reference.md`](./api-reference.md),
  [`docs/security.md`](./security.md),
  [`docs/architecture.md`](./architecture.md).
- **Existing TODO items closed by this work:**
  > "SSO SPA flow documentation — No docs on how a SPA initiates
  > SSO login, receives the token after callback…"
  > "CORS/SPA guidance — Add a section to API docs covering CORS
  > configuration for SPA consumers."
- **Sanctum SPA mode** (reference design):
  <https://laravel.com/docs/sanctum#spa-authentication> — the
  closest equivalent in the Laravel ecosystem; neev's pattern is
  modelled after it but uses neev's `AccessToken` model instead
  of Laravel sessions.
- **OWASP CSRF Prevention Cheat Sheet** (double-submit cookie):
  <https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html>
