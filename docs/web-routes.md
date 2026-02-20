# Web Routes Reference

Complete reference for all Neev web routes. These routes render Blade views for browser-based authentication.

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
- `redirect` - URL to redirect after login

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

| Method | Route | Name | Description |
|--------|-------|------|-------------|
| GET | `/oauth/{service}` | `oauth.redirect` | Redirect to OAuth provider |
| GET | `/oauth/{service}/callback` | `oauth.callback` | Handle OAuth callback |

**URL Parameters:**
- `service` - OAuth provider (`google`, `github`, `microsoft`, `apple`)

---

### Tenant SSO

| Method | Route | Name | Description |
|--------|-------|------|-------------|
| GET | `/sso/redirect` | `sso.redirect` | Redirect to tenant's SSO provider |
| GET | `/sso/callback` | `sso.callback` | Handle SSO callback |

---

### Email Verification

| Method | Route | Name | Description |
|--------|-------|------|-------------|
| GET | `/email/verify/{id}/{hash}` | `verification.verify` | Verify email address |

---

## Authenticated Routes

These routes require the `neev:web` middleware.

### Email Management

| Method | Route | Name | Description |
|--------|-------|------|-------------|
| GET | `/email/verify` | `verification.notice` | Show verification pending page |
| GET | `/email/send` | `email.verification.send` | Resend verification email |
| GET | `/email/change` | `email.change` | Show change email form |
| PUT | `/email/change` | `email.update` | Process email change |

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
| GET | `/account/emails` | `account.emails` | Show email management |
| GET | `/account/security` | `account.security` | Show security settings |
| GET | `/account/tokens` | `account.tokens` | Show API tokens |
| GET | `/account/teams` | `account.teams` | Show teams list |
| GET | `/account/sessions` | `account.sessions` | Show active sessions |
| GET | `/account/loginAttempts` | `account.loginAttempts` | Show login history |

---

### Profile Updates

| Method | Route | Name | Description |
|--------|-------|------|-------------|
| PUT | `/account/profileUpdate` | `profile.update` | Update profile |
| POST | `/account/change-password` | `password.change` | Change password |
| DELETE | `/account/accountDelete` | `account.delete` | Delete account |

---

### Email Management

| Method | Route | Name | Description |
|--------|-------|------|-------------|
| POST | `/account/emails` | `emails.add` | Add new email |
| DELETE | `/account/emails` | `emails.delete` | Delete email |
| PUT | `/account/emails` | `emails.primary` | Set primary email |

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

All prefixed with `/teams`.

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
| PUT | `/teams/members/request/action` | `teams.request.action` | Accept/reject request |
| PUT | `/teams/owner/change` | `teams.owner.change` | Transfer ownership |
| PUT | `/teams/roles/change` | `teams.roles.change` | Change member role |

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

Applied to authenticated web routes. Checks:
1. User is logged in
2. User account is active
3. MFA is completed (if enabled)
4. Email is verified (if required)

### neev:tenant

For multi-tenant routes. Additionally resolves:
1. Current tenant from domain
2. User's membership in tenant

---

## View Files

Views are stored in `resources/views/` and can be published:

```bash
php artisan vendor:publish --tag=neev-views
```

| View | Description |
|------|-------------|
| `auth/register.blade.php` | Registration form |
| `auth/login.blade.php` | Login form |
| `auth/login-password.blade.php` | Password entry after email |
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
