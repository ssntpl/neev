# Web Routes Reference

Complete reference for all Neev web routes. These routes render Blade views for browser-based authentication.

> **Blade kit required:** all Blade page routes in this document register only when `'ui' => 'blade'` in `config/neev.php` — i.e. after the Blade starter kit is ejected (`php artisan neev:ui blade` or the installer's kit prompt). On a headless install (`ui` = `null`, the default) none of these page routes exist. The [OAuth / Social Login](#oauth--social-login) and [Tenant SSO](#tenant-sso) routes below, and everything in the [API Reference](./api-reference.md), are always registered regardless.

---

## Public Routes

These routes are accessible without authentication.

### Registration

| Method | Route | Name | Description |
|--------|-------|------|-------------|
| GET | `/register` | `register` | Show registration form |
| POST | `/register` | - | Process registration |

**Query Parameters:**
- `id` - Invitation ID (for team invitations)
- `hash` - Invitation hash
- `signature` - Signed URL signature

---

### Login

| Method | Route | Name | Description |
|--------|-------|------|-------------|
| GET | `/login` | `login` | Show login form |
| PUT | `/login` | `login.password` | Check email and show password form |
| POST | `/login` | - | Process login |

**Query Parameters:**
- `redirect` - Where to send the user after login

`redirect` is a same-site path and nothing else. It is accepted only when it
starts with a single `/`, and is discarded — falling back to
`config('neev.home')` — when it is absolute (`https://evil.example/`),
protocol-relative (`//evil.example`, which a browser reads as a full URL),
starts with `/\` (browsers normalise the backslash into the authority), is
bare `/`, or is not a string at all. That keeps `redirect` from acting as an
open redirect — a link to your own login page that lands the user on someone
else's site with your domain in the referrer. See
[security.md](./security.md#open-redirect-protection).

When the account has a second factor, the destination is parked in the
session as `mfa_redirect` and honoured after the code is verified, then
cleared. A login that carries no `redirect` clears any parked one, so a
destination cannot leak from an earlier attempt.

---

### Magic Link Login

| Method | Route | Name | Description |
|--------|-------|------|-------------|
| POST | `/login/link` | `login.link.send` | Send login link to email |
| GET | `/login/{id}` | `login.link` | Login via magic link |

---

### Password Reset

| Method | Route | Name | Description |
|--------|-------|------|-------------|
| GET | `/forgot-password` | `password.request` | Show forgot password form |
| POST | `/forgot-password` | `password.email` | Send password reset link |
| GET | `/update-password/{id}/{hash}` | `reset.request` | Show reset password form |
| POST | `/update-password` | `user-password.update` | Process password update |

---

### MFA Verification

| Method | Route | Name | Description |
|--------|-------|------|-------------|
| GET | `/otp/mfa/{method}` | `otp.mfa.create` | Show MFA verification form |
| POST | `/otp/mfa` | `otp.mfa.store` | Verify MFA code |
| POST | `/otp/mfa/send` | `otp.mfa.send` | Resend email OTP |

**URL Parameters:**
- `method` - MFA method (`authenticator`, `email`, or `recovery`)

---

### Passkey Authentication

| Method | Route | Name | Description |
|--------|-------|------|-------------|
| POST | `/passkeys/login/options` | `passkeys.login.options` | Get WebAuthn login options |
| POST | `/passkeys/login` | `passkeys.login` | Authenticate with passkey |

---

### OAuth / Social Login

These routes live under the configurable route prefix (`route_prefix` in `config/neev.php`, env `NEEV_ROUTE_PREFIX`, default `neev`). The paths below use the default prefix. Unlike the Blade page routes, they are **always registered** — headless installs use them too.

| Method | Route | Name | Description |
|--------|-------|------|-------------|
| GET | `/neev/oauth/{service}` | `oauth.redirect` | Redirect to OAuth provider |
| GET | `/neev/oauth/{service}/callback` | `oauth.callback` | Handle OAuth callback |

**URL Parameters:**
- `service` - OAuth provider (`google`, `github`, `microsoft`, `apple`)

---

### Tenant SSO

These routes also live under the configurable route prefix and are always registered.

| Method | Route | Name | Description |
|--------|-------|------|-------------|
| GET | `/neev/sso/redirect` | `sso.redirect` | Redirect to tenant's SSO provider |
| GET | `/neev/sso/callback` | `sso.callback` | Handle SSO callback |

---

### Email Verification

| Method | Route | Name | Description |
|--------|-------|------|-------------|
| GET | `/email/verify/{id}/{hash}` | `verification.verify` | Verify email address |
| GET | `/email/change/verify/{id}` | `email.change.verify` | Confirm a new email address |

Both are signed and **unauthenticated by design**. A mail client opens the
link in whichever browser it likes, rarely the one holding the session, so the
signature is what authorises the action — the link acts on the account it was
minted for, not on whoever is signed in. A second click on an
already-verified address is not an error. See
[Email Links](./email-links.md) for where these point and how to change it.

---

## Authenticated Routes

These routes require the `neev:web` middleware.

### Email Management

| Method | Route | Name | Description |
|--------|-------|------|-------------|
| GET | `/email/verify` | `verification.notice` | Show verification pending page |
| GET | `/email/send` | `email.verification.send` | Resend verification email |
| GET | `/email/change` | `email.change` | Show change email form |
| PUT | `/email/change` | `email.update` | Request email change (sends verification) |

---

### Logout

| Method | Route | Name | Description |
|--------|-------|------|-------------|
| POST | `/logout` | `logout` | Logout current session |

---

## Account Routes

All prefixed with `/account`.

### Profile & Settings

| Method | Route | Name | Description |
|--------|-------|------|-------------|
| GET | `/account/profile` | `account.profile` | Show profile page |
| GET | `/account/security` | `account.security` | Show security settings |
| GET | `/account/tokens` | `account.tokens` | Show API tokens |
| GET | `/account/teams` | `account.teams` | Show teams list (teams only) |
| GET | `/account/sessions` | `account.sessions` | Show active sessions |
| GET | `/account/loginAttempts` | `account.loginAttempts` | Show login history |

---

### Profile Updates

| Method | Route | Name | Description |
|--------|-------|------|-------------|
| PUT | `/account/profileUpdate` | `profile.update` | Update profile |
| POST | `/account/change-password` | `password.change` | Change password |
| POST | `/account/password/reset-link` | `password.reset.link` | Email a link to set or reset the password |
| DELETE | `/account/accountDelete` | `account.delete` | Delete account |

---

### MFA Management

| Method | Route | Name | Description |
|--------|-------|------|-------------|
| POST | `/account/multiFactorAuth` | `multi.auth` | Add MFA method |
| PUT | `/account/multiFactorAuth` | `multi.preferred` | Set preferred MFA |
| GET | `/account/recovery/codes` | `recovery.codes` | Show recovery codes |
| POST | `/account/recovery/codes` | `recovery.generate` | Generate new codes |

---

### Passkey Management

| Method | Route | Name | Description |
|--------|-------|------|-------------|
| POST | `/account/passkeys/register/options` | `passkeys.register.options` | Get registration options |
| POST | `/account/passkeys/register` | `passkeys.register` | Register new passkey |
| DELETE | `/account/passkeys` | `passkeys.delete` | Delete passkey |

---

### Session Management

| Method | Route | Name | Description |
|--------|-------|------|-------------|
| POST | `/account/logoutSessions` | `logout.sessions` | Logout other sessions |

**Request Parameters:**
- `password` - Required to logout all sessions
- `session_id` - Optional, to logout specific session

---

### API Token Management

| Method | Route | Name | Description |
|--------|-------|------|-------------|
| POST | `/account/tokens/store` | `tokens.store` | Create new token |
| DELETE | `/account/tokens/delete` | `tokens.delete` | Delete token |
| DELETE | `/account/tokens/deleteAll` | `tokens.deleteAll` | Delete all tokens |
| PUT | `/account/tokens/update` | `tokens.update` | Update token |

---

## Team Routes

All prefixed with `/teams`. These, and `account.teams` above, register only when
`'team' => true` in `config/neev.php`. With teams switched off the routes do not
exist at all, so `route('teams.create')` throws and the paths answer 404 — guard
any link to them with `@if (config('neev.team'))`.

### Team Views

| Method | Route | Name | Description |
|--------|-------|------|-------------|
| GET | `/teams/create` | `teams.create` | Show create team form |
| GET | `/teams/{team}/profile` | `teams.profile` | Show team profile |
| GET | `/teams/{team}/members` | `teams.members` | Show team members |
| GET | `/teams/{team}/domain` | `teams.domain` | Show domain settings |
| GET | `/teams/{team}/settings` | `teams.settings` | Show team settings |

---

### Team Management

| Method | Route | Name | Description |
|--------|-------|------|-------------|
| POST | `/teams/create` | `teams.store` | Create new team |
| PUT | `/teams/update` | `teams.update` | Update team |
| DELETE | `/teams/delete` | `teams.delete` | Delete team |
| PUT | `/teams/switch` | `teams.switch` | Switch current team |

---

### Team Members

| Method | Route | Name | Description |
|--------|-------|------|-------------|
| PUT | `/teams/members/invite` | `teams.invite` | Invite member |
| PUT | `/teams/members/invite/action` | `teams.invite.action` | Accept/reject invitation |
| DELETE | `/teams/members/leave` | `teams.leave` | Leave team |
| POST | `/teams/members/request` | `teams.request` | Request to join team |
| PUT | `/teams/members/request/action` | `teams.request.action` | Accept/reject request (owner only) |
| PUT | `/teams/owner/change` | `teams.owner.change` | Transfer ownership |
| PUT | `/teams/roles/change` | `teams.roles.change` | Change member role |

`teams.request` names the team by `team_id`, by `slug`, or by an `email` (the
owner's) and `team` (the team name) pair. `team_id` is tried first, then
`slug`, then the pair.

`teams.request.action` is the owner's call: accepting a request admits someone
to the team and may hand them a role, which is what inviting does. The API
counterpart (`PUT /neev/teams/request`) allows any member — see
[teams.md](./teams.md#membership-is-what-authorises-a-team-action).

---

### Domain Federation

| Method | Route | Name | Description |
|--------|-------|------|-------------|
| POST | `/teams/{team}/domain` | - | Add domain |
| PUT | `/teams/{domain}/domain` | - | Update domain |
| DELETE | `/teams/{domain}/domain` | - | Delete domain |
| PUT | `/teams/{domain}/domain/rules` | `domain.rules` | Update domain rules |
| PUT | `/teams/domain/primary` | `domain.primary` | Set primary domain |

---

## Route Parameters

### Team Parameter

The `{team}` parameter is automatically bound to the Team model:

```php
Route::bind('team', fn($value) => Team::model()->findOrFail($value));
```

### User Parameter

The `{user}` parameter is automatically bound to the User model:

```php
Route::bind('user', fn($value) => User::model()->findOrFail($value));
```

---

## Middleware Groups

### neev:web

Applied to authenticated web routes. Resolves the tenant and team context, then checks:
1. User is logged in
2. User account is active
3. MFA is completed (if enabled)
4. User is a member of the resolved tenant (when `tenant` is enabled)

Email verification is enforced separately via the `neev-verified-email` middleware alias.

Verification is never a precondition for *reaching* a login method. The
password page offers OAuth, the magic link, and passkeys to verified and
unverified accounts alike — each of those flows proves control of the address
on its own and marks it verified. Password login is the one path that still
sends an unverified account to `verification.notice`, because a password
proves nothing about the inbox.

### neev:tenant

For multi-tenant routes. Requires a tenant to be resolved (from the X-Tenant header, subdomain, or custom domain) — returns 404 when no tenant is found. Also resolves the current team and binds the request context. Does not require authentication.

---

## View Files

The page views are part of the Blade starter kit — they live in your application at `resources/views/vendor/neev/` (app-owned) once the kit is ejected:

```bash
php artisan neev:ui blade
```

| View | Description |
|------|-------------|
| `auth/register.blade.php` | Registration form |
| `auth/login.blade.php` | Login form |
| `auth/login-password.blade.php` | Password entry after email, plus the passwordless options (OAuth, magic link, passkey) |
| `auth/forgot-password.blade.php` | Password reset request |
| `auth/reset-password.blade.php` | New password form |
| `auth/verify-email.blade.php` | Verification pending |
| `auth/change-email.blade.php` | Change email form |
| `auth/otp-mfa.blade.php` | MFA verification |

---

## Customizing Routes

To customize Neev's routes, publish them and modify the published file:

```bash
php artisan vendor:publish --tag=neev-routes
```

This copies the route file to your application's `routes/neev.php`. The service provider will use your published version instead of the package's default routes.

You can then:
- Remove routes you don't need
- Add middleware to specific routes
- Change route prefixes or names
- Add rate limiting

---

## Next Steps

- [Authentication Guide](./authentication.md)
- [API Reference](./api-reference.md)
