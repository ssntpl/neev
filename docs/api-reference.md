# API Reference

Complete reference for all Neev API endpoints. All API routes are prefixed with the configurable route prefix — `route_prefix` in `config/neev.php` (env `NEEV_ROUTE_PREFIX`), default `neev`. This documentation uses the default `/neev` prefix throughout.

---

## Authentication

All authenticated endpoints require a Bearer token in the Authorization header:

```http
Authorization: Bearer {token_id}|{token}
```

The header is the only accepted transport — query-string and request-body tokens were removed in v0.4.4 (tokens in URLs leak via logs, referrers, and browser history).

**SPA cookie mode:** same-origin SPAs whose host is listed in `config('neev.spa.stateful')` may instead carry the token in an HttpOnly cookie — the `EnsureSpaRequestsAreStateful` middleware promotes it to the Authorization header. State-changing requests (POST/PUT/PATCH/DELETE) from stateful origins must echo the CSRF cookie in the `X-XSRF-TOKEN` header or they are rejected with **419**. See [SPA Cookie Mode](./spa-cookie-mode.md).

---

## Authentication Endpoints

### CSRF Cookie (SPA cookie mode)

Issues the signed double-submit CSRF cookie. SPAs call this once on app load (and again after a 419).

```http
GET /neev/csrf-cookie
```

**Response:** `204 No Content` with an `XSRF-TOKEN` cookie (not HttpOnly — the SPA reads it and echoes the value in `X-XSRF-TOKEN`). Throttled to 60 requests/minute.

---

### Register

Create a new user account.

```http
POST /neev/register
```

**Request Body:**

```json
{
    "name": "John Doe",
    "email": "john@example.com",
    "password": "SecurePass123!",
    "password_confirmation": "SecurePass123!",
    "username": "johndoe"  // Optional, if support_username enabled
}
```

**Response:**

```json
{
    "auth_state": "authenticated",
    "token": "1|abc123...",
    "expires_in": 1440,
    "mfa_options": null,
    "email_verified": false
}
```

---

### Login

Authenticate with email/username and password.

```http
POST /neev/login
```

**Request Body:**

```json
{
    "email": "john@example.com",
    "password": "SecurePass123!"
}
```

**Response (without MFA):**

```json
{
    "auth_state": "authenticated",
    "token": "1|abc123...",
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

When `auth_state` is `mfa_required`, the token is a short-lived JWT (type `mfa`) that can only be used to verify MFA. Complete MFA verification to get a full access token.
`expires_in` is returned in minutes. For a login token it is an **idle**
window: an authenticated request past the half-way point of the window
slides the deadline forward (capped by
`login_token_max_lifetime_minutes`, default 30 days from issue), so a
client in active use is not signed out when `expires_in` elapses. An
expired token answers `401` with `"code": "token_expired"`. API tokens
do not slide.

---

### Send Login Link

Send a magic link to the user's email for passwordless login.

```http
POST /neev/sendLoginLink
```

**Request Body:**

```json
{
    "email": "john@example.com"
}
```

**Response:**

```json
{
    "message": "Login link has been sent."
}
```

---

### Login Using Link

Authenticate using a magic link.

```http
GET /neev/loginUsingLink?id={email_id}&signature={signature}&expires={timestamp}
```

**Response:**

```json
{
    "auth_state": "authenticated",
    "token": "1|abc123...",
    "expires_in": 1440,
    "email_verified": true
}
```

---

### Logout

Logout the current session.

```http
POST /neev/logout
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Response:**

```json
{
    "message": "Logged out successfully."
}
```

---

### Logout All Sessions

Logout from all other devices — the current session survives.

```http
POST /neev/logoutAll
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Response:**

```json
{
    "message": "Logged out from all other devices successfully."
}
```

---

### Forgot Password

Send a signed URL password reset link to the user's email.

```http
POST /neev/forgotPassword
```

**Request Body:**

```json
{
    "email": "john@example.com"
}
```

**Response:**

```json
{
    "message": "Password reset link has been sent to your email."
}
```

An **unverified** address can request and use a reset link. A forgotten
password is exactly the case where the user may never have finished
verifying, so requiring verification first would strand them. `404 User not
registered or wrong email.` is returned only when no account holds the
address.

Where the link lands is controlled by [`EmailLinks`](./email-links.md):
the Blade kit sends it to its own `reset.request` form, while a headless
install sends it to `{app.url}/reset-password` carrying the signed query for
your page to forward here.

---

### Reset Password

Reset the user's password using a signed URL from the forgot password email. The frontend receives the signed URL parameters and forwards them to this endpoint.

```http
POST /neev/resetPassword?id={user_id}&hash={email_hash}&signature={signature}&expires={timestamp}
```

**Request Body:**

```json
{
    "password": "NewSecurePass123!",
    "password_confirmation": "NewSecurePass123!"
}
```

**Response:**

```json
{
    "message": "Password has been updated."
}
```

---

## Email Verification

### Send Verification Email

Resend the verification email to the authenticated user's current email address.

```http
POST /neev/email/send
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Response:**

```json
{
    "message": "Verification link has been sent."
}
```

---

### Verify Email

```http
GET /neev/email/verify?id={user_id}&hash={email_hash}&signature={signature}&expires={timestamp}
```

**No authentication required.** The signature is the credential. A mail client
hands the link to whichever browser it likes — rarely the one holding the
session — so requiring a token here would break most real clicks. The link
verifies the account it was minted for, regardless of who is signed in.
Throttled to 10 requests/minute.

**Response (`200`):**

```json
{
    "message": "Email verification done."
}
```

**Response (`200`, already verified):**

```json
{
    "message": "Email verification already done."
}
```

A second click is not an error — mail scanners routinely fetch links before
the recipient does.

**Response (`403`)** — bad or expired signature, unknown user, or a `hash`
that no longer matches the account's address:

```json
{
    "message": "Invalid or expired verification link."
}
```

If the caller does not send `Accept: application/json`, these answer with a
redirect instead (to `neev.home` on success, with an error bag on failure).
Both the URL and the response are controlled by
[`EmailLinks`](./email-links.md).

---

### Verify Email via Code

The verification email also carries a numeric code, so the session that is waiting (cross-device signup, TVs, environments where security scanners consume links) can complete verification in place. Codes expire after `otp_expiry_time` minutes and are invalidated after 5 wrong attempts or once the link is used. Throttled to 5 requests/minute.

```http
POST /neev/email/verify-otp
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Request Body:**

```json
{
    "otp": "123456"
}
```

**Response:** `200` with `{"message": "Email verification done."}` — or `400` (`Code verification failed.` / `Email already verified.`).

---

## Email Change

### Request Email Change

Request to change the authenticated user's email address. Sends a verification link to the new email. Requires current password for security.

An account created through OAuth has no password, so
there is nothing to check: the request is refused with `403` and *Set a password
on your account before changing your email address.* until one is set. See
[Accounts Without a Password](./authentication.md#accounts-without-a-password).

```http
POST /neev/email/change
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Request Body:**

```json
{
    "email": "newemail@example.com",
    "password": "CurrentPass123!"
}
```

**Response:**

```json
{
    "message": "Verification link has been sent to your new email address."
}
```

---

### Verify Email Change

Verify the email change using the signed URL sent to the new email address.

The route answers **both** verbs. `GET` is what a clicked link issues; `POST`
is retained for SPAs that receive the signed URL parameters on their own page
and forward them here. Like verification, no authentication is required — the
signature is the credential. Throttled to 10 requests/minute.

```http
GET  /neev/email/change/verify?id={user_id}&email={new_email}&signature={signature}&expires={timestamp}
POST /neev/email/change/verify?id={user_id}&email={new_email}&signature={signature}&expires={timestamp}
```

**Response (success):**

```json
{
    "message": "Email address has been updated and verified."
}
```

**Response (email already taken):**

```json
{
    "message": "This email address is already in use."
}
```

---

## Multi-Factor Authentication

### Add MFA Method

```http
POST /neev/mfa/add
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Request Body:**

```json
{
    "auth_method": "authenticator"  // or "email"
}
```

**Response (authenticator):**

```json
{
    "qr_code": "<svg>...</svg>",
    "secret": "JBSWY3DPEHPK3PXP",
    "method": "authenticator"
}
```

The authenticator method is created in a **pending** state and is not enforced at login until activated via [Verify MFA Setup](#verify-mfa-setup). The email method is created active immediately.

**Response (`422`)** — the request could not be satisfied. The body carries the
reason:

```json
{
    "message": "Email is not verified."
}
```

An email factor is only as trustworthy as the inbox it is sent to, so an
unverified address cannot be enrolled. `Email already Configured.` comes back
the same way when the factor already exists. A `400` still means the method
name itself is not one neev supports.

**Response (email):**

```json
{
    "message": "Email Configured."
}
```

---

### Verify MFA Setup

Activate a pending authenticator setup by verifying a TOTP code. On success the method becomes active (and preferred, if no other active method is preferred).

```http
POST /neev/mfa/setup/verify
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Request Body:**

```json
{
    "auth_method": "authenticator",
    "otp": "123456"
}
```

**Response:**

```json
{
    "message": "Method has been verified and enabled.",
    "method": "authenticator"
}
```

**Response (wrong code or no pending setup, 400):**

```json
{
    "message": "Code verification failed."
}
```

---

### Verify MFA OTP

Complete MFA verification after login.

```http
POST /neev/mfa/otp/verify
```

**Headers:**
```http
Authorization: Bearer {mfa_jwt_token}
```

**Request Body:**

```json
{
    "auth_method": "authenticator",
    "otp": "123456"
}
```

**Response:**

```json
{
    "auth_state": "authenticated",
    "token": "1|abc123...",
    "expires_in": 1440,
    "email_verified": true
}
```

---

### Delete MFA Method

```http
DELETE /neev/mfa/delete
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Request Body:**

```json
{
    "auth_method": "authenticator"
}
```

**Response:**

```json
{
    "message": "Auth has been deleted."
}
```

---

### Generate Recovery Codes

```http
POST /neev/recoveryCodes
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Response:**

```json
{
    "message": "New recovery codes are generated.",
    "data": [
        "abc123defg",
        "hij456klmn",
        "opq789rstu"
    ]
}
```

---

## Passkeys (WebAuthn)

### Generate Registration Options

```http
GET /neev/passkeys/register/options
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Response:**

```json
{
    "rp": {
        "name": "Your App",
        "id": "yourapp.com"
    },
    "user": {
        "id": "base64_user_id",
        "name": "john@example.com",
        "displayName": "John Doe"
    },
    "challenge": "base64_challenge",
    "pubKeyCredParams": [...],
    "authenticatorSelection": {
        "residentKey": "required",
        "userVerification": "required"
    },
    "timeout": 60000,
    "attestation": "none"
}
```

---

### Register Passkey

```http
POST /neev/passkeys/register
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Request Body:**

```json
{
    "attestation": "{...attestation_response_json...}",
    "name": "My MacBook"
}
```

**Response:**

```json
{
    "message": "Passkey has been registered.",
    "data": {
        "id": 1,
        "name": "My MacBook",
        "created_at": "2024-01-15T10:00:00Z"
    }
}
```

---

### Generate Login Options

```http
GET /neev/passkeys/login/options?email=john@example.com
```

**Response:**

```json
{
    "challenge": "base64_challenge",
    "timeout": 120000,
    "rpId": "yourapp.com",
    "allowCredentials": [...],
    "userVerification": "required"
}
```

---

### Login with Passkey

```http
POST /neev/passkeys/login
```

**Request Body:**

```json
{
    "email": "john@example.com",
    "assertion": "{...assertion_response_json...}"
}
```

**Response:**

```json
{
    "auth_state": "authenticated",
    "token": "1|abc123...",
    "expires_in": 1440,
    "email_verified": true
}
```

---

### Update Passkey Name

```http
PUT /neev/passkeys
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Request Body:**

```json
{
    "passkey_id": 1,
    "name": "Work Laptop"
}
```

---

### Delete Passkey

```http
DELETE /neev/passkeys
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Request Body:**

```json
{
    "passkey_id": 1
}
```

---

## User Management

### Get Current User

```http
GET /neev/users
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Response:**

```json
{
    "data": {
        "id": 1,
        "name": "John Doe",
        "username": "johndoe",
        "active": true,
        "emails": [...],
        "teams": [...]
    }
}
```

---

### Update User

```http
PUT /neev/users
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Request Body:**

```json
{
    "name": "John Smith",
    "username": "johnsmith"
}
```

**Response:**

```json
{
    "message": "Account has been updated.",
    "data": {...}
}
```

---

### Delete User

```http
DELETE /neev/users
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Request Body:**

```json
{
    "password": "CurrentPassword123!"
}
```

`password` is required — and checked — only when the account has one. An account
created through OAuth has no password, so the bearer
token is the confirmation and the body may be empty.

**Response:**

```json
{
    "message": "Account has been deleted."
}
```

**Errors:**

| Status | Message |
|--------|---------|
| 422 | Validation error — `password` missing on an account that has one |
| 403 | `Password is Wrong.` |

---

### Change Password

```http
PUT /neev/changePassword
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Request Body:**

```json
{
    "current_password": "OldPassword123!",
    "password": "NewPassword456!",
    "password_confirmation": "NewPassword456!"
}
```

**Response:**

```json
{
    "message": "Password has been successfully updated."
}
```

**Errors:**

| Status | Message |
|--------|---------|
| 403 | `Current Password is Wrong.` |
| 403 | `Your account has no password yet. Use the emailed link to set one.` |
| 404 | `User not found.` |

An account with no password cannot use this endpoint — there is no current
password to check. It sets its first one through the emailed link:
`POST /account/password/reset-link` under the Blade kit, or your own page
calling `EmailLinks::passwordResetUrl()`. See
[Accounts Without a Password](./authentication.md#accounts-without-a-password).

---

## Sessions & Login History

### Get Active Sessions

```http
GET /neev/sessions
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Response:**

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

---

### Revoke a Session

```http
DELETE /neev/sessions/{id}
```

Deletes one of the user's login sessions (the underlying login token), immediately invalidating it. The current session cannot be revoked this way — use `POST /neev/logout` instead.

**Headers:**
```http
Authorization: Bearer {token}
```

**Response:**

```json
{
    "message": "Session has been revoked."
}
```

**Errors:**

| Status | Condition |
|--------|-----------|
| 400 | `{id}` is the current session |
| 404 | Session does not exist or belongs to another user |

---

### Get Login Attempts

```http
GET /neev/loginAttempts
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Response:**

```json
{
    "data": [
        {
            "id": 1,
            "method": "password",
            "multi_factor_method": "authenticator",
            "ip_address": "192.168.1.1",
            "browser": "Chrome",
            "platform": "macOS",
            "device": "Desktop",
            "location": "San Francisco, CA, US",
            "is_success": true,
            "is_suspicious": false,
            "created_at": "2024-01-15T10:00:00Z"
        }
    ]
}
```

---

## API Tokens

### Get API Tokens

```http
GET /neev/apiTokens
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Response:**

```json
{
    "data": [
        {
            "id": 1,
            "name": "Mobile App",
            "permissions": ["read", "write"],
            "last_used_at": "2024-01-15T10:00:00Z",
            "expires_at": null,
            "created_at": "2024-01-10T10:00:00Z"
        }
    ]
}
```

---

### Create API Token

```http
POST /neev/apiTokens
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Request Body:**

```json
{
    "name": "Mobile App",
    "permissions": ["read", "write"],
    "expiry": 43200  // minutes (30 days), null for no expiry
}
```

**Response:**

```json
{
    "message": "Token has been added.",
    "data": {
        "accessToken": {...},
        "plainTextToken": "1|abc123..."
    }
}
```

---

### Update API Token

```http
PUT /neev/apiTokens
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Request Body:**

```json
{
    "token_id": 1,
    "name": "Updated Name",
    "permissions": ["read"],
    "expiry": 10080  // new expiry in minutes
}
```

---

### Delete API Token

```http
DELETE /neev/apiTokens
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Request Body:**

```json
{
    "token_id": 1
}
```

---

### Delete All API Tokens

```http
DELETE /neev/apiTokens/deleteAll
```

**Headers:**
```http
Authorization: Bearer {token}
```

---

## Team Management

> These endpoints, and the [Domain Federation](#domain-federation) ones, are
> registered only when `'team' => true` in `config/neev.php`. With teams off
> they answer 404.

### Get User's Teams

```http
GET /neev/teams
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Response:**

```json
{
    "data": [
        {
            "id": 1,
            "name": "My Team",
            "slug": "my-team",
            "is_public": false,
            "owner": {...},
            "membership": {
                "role": "admin",
                "joined": true
            }
        }
    ]
}
```

Under tenant isolation the list holds only teams in the tenant the request resolved to. A user who belongs to teams in several tenants sees each tenant's teams on that tenant's domain, never a merged list.

---

### Set Default Team

Set the user's default team (the team to land on after login).

```http
PUT /neev/teams/default
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Request Body:**

```json
{
    "team_id": 1
}
```

**Response:**

```json
{
    "message": "Default team updated successfully.",
    "data": {...}
}
```

---

### Get Team Details

```http
GET /neev/teams/{id}
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Response:**

```json
{
    "data": {
        "id": 1,
        "name": "My Team",
        "slug": "my-team",
        "is_public": false,
        "owner": {...},
        "users": [...],
        "joinRequests": [...],
        "invitedUsers": [...],
        "invitations": [...]
    }
}
```

**Errors:**

| Status | Message | When |
| --- | --- | --- |
| `400` | `Team not found` | The team does not exist, **or** the caller is not one of its members |

A team the caller does not belong to is reported as missing rather than
forbidden, so the endpoint cannot be used to probe which team ids exist.

---

### Get Team Details by Slug

```http
GET /neev/teams/slug/{slug}
```

Looks a team up by its slug instead of its id, for clients that route on a
readable team handle (`/t/acme-labs`) and never see the id. Slugs are unique
across the whole installation, so no tenant needs naming.

**Headers:**
```http
Authorization: Bearer {token}
```

**Response:** identical to `GET /neev/teams/{id}`.

**Errors:** identical to `GET /neev/teams/{id}` — membership is required, and
an unknown slug and someone else's team give the same `400 Team not found`.

---

### Create Team

```http
POST /neev/teams
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Request Body:**

```json
{
    "name": "New Team",
    "public": false
}
```

---

### Update Team

```http
PUT /neev/teams
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Request Body:**

```json
{
    "team_id": 1,
    "name": "Updated Team Name",
    "public": true
}
```

---

### Delete Team

```http
DELETE /neev/teams
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Request Body:**

```json
{
    "team_id": 1
}
```

---

### Invite Team Member

```http
POST /neev/teams/inviteUser
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Request Body:**

```json
{
    "team_id": 1,
    "email": "newmember@example.com",
    "role": "member"
}
```

**Errors:**

| Status | Message | When |
| --- | --- | --- |
| `400` | `Role not found.` | `role` names a role that does not resolve for this team |
| `400` | `User already added.` | The address already belongs to a joined member |

Attaching the member and granting the role are one transaction: if the role
cannot be resolved the membership is rolled back and no invitation mail is
sent, so a bad role name cannot leave a member behind with no permissions.
The same applies to accepting an invitation via `PUT /neev/teams/inviteUser`.

---

### Accept/Reject Invitation

```http
PUT /neev/teams/inviteUser
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Request Body:**

```json
{
    "team_id": 1,
    "action": "accept"  // or "reject"
}
```

Or for email invitations:

```json
{
    "invitation_id": 1,
    "action": "accept"
}
```

---

### Leave Team

```http
PUT /neev/teams/leave
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Request Body:**

```json
{
    "team_id": 1
}
```

---

### Request to Join Team

```http
POST /neev/teams/request
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Request Body:** name the team by id **or** by slug.

```json
{
    "team_id": 1
}
```

```json
{
    "slug": "acme-labs"
}
```

`team_id` wins if both are sent. A body naming neither is refused with `400`,
as is a slug that matches no team.

The request is recorded as a pending membership with
`action = request_from_user`, and the team owner is emailed. A team whose
domain federation is enforced or verified does not accept join requests —
membership there follows from the verified domain.

---

### Accept/Reject Join Request

```http
PUT /neev/teams/request
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Request Body:**

```json
{
    "team_id": 1,
    "user_id": 5,
    "action": "accept",
    "role": "member"
}
```

---

### Change Team Owner

```http
POST /neev/changeTeamOwner
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Request Body:**

```json
{
    "team_id": 1,
    "user_id": 5
}
```

---

### Change Member Role

```http
PUT /neev/role/change
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Request Body:**

```json
{
    "team_id": 1,
    "user_id": 5,
    "role": "admin"
}
```

---

## Domain Federation

### Get Team Domains

```http
GET /neev/domains?team_id=1
```

**Headers:**
```http
Authorization: Bearer {token}
```

---

### Add Domain

```http
POST /neev/domains
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Request Body:**

```json
{
    "team_id": 1,
    "domain": "company.com",
    "enforce": false
}
```

**Response:**

```json
{
    "message": "Domain federated successfully.",
    "token": "abc123verification..."
}
```

---

### Verify Domain

```http
PUT /neev/domains
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Request Body:**

```json
{
    "domain_id": 1,
    "verify": true
}
```

---

### Delete Domain

```http
DELETE /neev/domains
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Request Body:**

```json
{
    "domain_id": 1
}
```

---

## Tenant Domains (Multi-Tenancy)

### Get Tenant Domains

```http
GET /neev/tenant-domains
```

**Headers:**
```http
Authorization: Bearer {token}
```

---

### Add Tenant Domain

```http
POST /neev/tenant-domains
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Request Body:**

```json
{
    "domain": "custom.example.com"
}
```

---

### Verify Tenant Domain

```http
POST /neev/tenant-domains/{id}/verify
```

---

### Set Primary Tenant Domain

```http
POST /neev/tenant-domains/{id}/primary
```

---

### Get Current Tenant

Reports the context the resolver settled on for this request, and the domain it
was resolved from. Requires `tenant => true`: with tenant isolation off the
resolver never runs and this always answers 400.

```http
GET /neev/tenant-domains/current
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Response:**

```json
{
    "data": {
        "type": "tenant",
        "context": { "id": 1, "name": "Acme", "slug": "acme" },
        "domain": { "id": 4, "domain": "acme.example.com", "is_primary": true },
        "team": null
    }
}
```

`context` is that record and `type` says what it is. Resolution from the
`X-Tenant` header or the request host always yields a `Tenant` (a team-owned
domain resolves up to that team's tenant), so `type` is normally `tenant`. It is
`team` only when the application has made a Team the context itself via
`TenantResolver::setCurrentTenant()`.

`team` repeats `context` when the type is `team`, and is `null` otherwise — kept
for callers written before tenant isolation, when the context could only ever be
a Team. New code should read `context` and branch on `type`.

**Errors:**

| Status | Message |
|--------|---------|
| 400 | `No tenant context.` |

---

## Error Responses

All endpoints return consistent error responses:

```json
{
    "message": "Error description here."
}
```

**Common HTTP Status Codes:**

| Code | Description |
|------|-------------|
| 200 | Success |
| 400 | Bad Request (validation error) |
| 401 | Unauthorized (invalid/missing token) |
| 403 | Forbidden (insufficient permissions) |
| 404 | Not Found |
| 500 | Server Error |

---

## Next Steps

- [Web Routes Reference](./web-routes.md)
- [Authentication Guide](./authentication.md)
