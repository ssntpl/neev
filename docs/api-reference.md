# API Reference

Complete reference for all Neev API endpoints. All API routes are prefixed with `/neev`.

---

## Authentication

All authenticated endpoints require a Bearer token in the Authorization header:

```http
Authorization: Bearer {token_id}|{token}
```

The header is the only accepted transport — query-string and request-body tokens were removed in v0.4.4 (tokens in URLs leak via logs, referrers, and browser history).

---

## Authentication Endpoints

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
`expires_in` is returned in minutes.

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

Logout from all devices.

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
    "message": "Logged out successfully."
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

**Headers:**
```http
Authorization: Bearer {token}
```

**Response:**

```json
{
    "message": "Email verification done."
}
```

---

## Email Change

### Request Email Change

Request to change the authenticated user's email address. Sends a verification link to the new email. Requires current password for security.

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

Verify the email change using the signed URL sent to the new email address. The frontend receives the signed URL parameters and forwards them to this endpoint.

```http
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

**Response (email):**

```json
{
    "message": "Email Configured."
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

**Response:**

```json
{
    "message": "Account has been deleted."
}
```

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

**Request Body:**

```json
{
    "team_id": 1
}
```

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
