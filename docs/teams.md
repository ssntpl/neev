# Team Management

Complete guide to team/organization management in Neev.

---

## Overview

Neev's team system allows users to:

- Create and manage teams/organizations
- Invite members via email
- Assign roles and permissions
- Configure domain-based auto-joining
- Belong to multiple teams and set a default team

---

## Configuration

### Enable Teams

```php
// config/neev.php
'team' => true,
```

This is what registers the team routes — `/teams/*` and `/account/teams` on the
web, `/neev/teams/*`, `/neev/domains/*` and `/neev/changeTeamOwner` on the API.
With `'team' => false` they are not registered at all, so the paths answer 404
and `route('teams.create')` throws; guard any link with
`@if (config('neev.team'))`.

### Team Slugs

```php
// config/neev.php
'slug' => [
    'min_length' => 2,
    'max_length' => 63,
    'reserved' => ['www', 'api', 'admin', 'app', 'mail', 'ftp', 'cdn', 'assets', 'static'],
],
```

Slugs are auto-generated from the team name on creation (normalized, uniquified, reserved words avoided).

### Domain Federation

Domain federation (domain-based auto-joining) has no config toggle — it is available whenever teams are enabled. See [Domain Federation](#domain-federation) below.

---

## Team Model

The Team model provides core functionality:

```php
use Ssntpl\Neev\Models\Team;

// Create team
$team = Team::create([
    'name' => 'Acme Corporation',
    'user_id' => $user->id,  // Owner
    'is_public' => false,
]);

// Access relationships
$team->owner;          // Team owner (User)
$team->users;          // Team members (joined)
$team->allUsers;       // All users (including invited)
$team->invitedUsers;   // Users with pending invitations
$team->joinRequests;   // Users requesting to join
$team->invitations;    // Email invitations
$team->domains;        // Federated domains

// Check membership
$team->hasUser($user);

// Team status
$team->isActive();
$team->activate();
$team->deactivate('subscription_expired');
```

---

## User's Teams

The `HasTeams` trait provides team methods on the User model:

```php
use Ssntpl\Neev\Traits\HasTeams;

class User extends Authenticatable
{
    use HasTeams;
}
```

### Available Methods

```php
// Teams the user owns
$user->ownedTeams;

// Teams the user belongs to (joined)
$user->teams;

// All team relationships (including pending)
$user->allTeams;

// Pending team invitations
$user->teamRequests;

// Join requests sent by user
$user->sendRequests;

// The user's default team (persisted preference, not request context)
$user->defaultTeam;

// Set default team (persisted preference for next login)
$user->setDefaultTeam($team);

// Check team membership
$user->belongsToTeam($team);
```

---

## Creating Teams

### Via API

```bash
curl -X POST https://yourapp.com/neev/teams \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "New Team",
    "public": false
  }'
```

**Response:**

```json
{
  "data": {
    "id": 1,
    "name": "New Team",
    "slug": "new-team",
    "is_public": false,
    "user_id": 1,
    "owner": {...},
    "users": [...]
  }
}
```

### Via Web

```http
POST /teams/create
Content-Type: application/x-www-form-urlencoded

name=New+Team&public=0
```

### Auto-Created Teams

When users register (with teams enabled), a personal team is created — unless they registered via a team invitation link, or their email domain matches a verified federated domain:

```php
$team = Team::model()->forceCreate([
    'name' => explode(' ', $user->name, 2)[0] . "'s Team",
    'user_id' => $user->id,
    'is_public' => false,
    'activated_at' => now(),
]);

$team->addMember($user);
```

---

## Team Membership

### Membership Model

The `Membership` model represents user-team relationships (pivot table `team_user`):

| Column | Type | Description |
|--------|------|-------------|
| team_id | bigint | Team reference |
| user_id | bigint | User reference |
| joined | boolean | Has accepted invitation |
| action | string | How relationship was created |

Roles are not stored on the pivot — they are managed by `ssntpl/laravel-acl` via polymorphic role assignments scoped to the team.

### Actions

A pending membership is a `team_user` row with `joined = false`. `action`
records which way it faces, and the two values are constants on the model
rather than bare strings:

| Constant | Stored value | Meaning |
|----------|--------------|---------|
| `Membership::REQUEST_TO_USER` | `request_to_user` | The team invited the user, who has yet to accept |
| `Membership::REQUEST_FROM_USER` | `request_from_user` | The user asked to join, and the owner has yet to approve |

Which side a pending row faces is what sorts it into a relation:
`$team->invitedUsers` and `$user->teamRequests` read `REQUEST_TO_USER`;
`$team->joinRequests` and `$user->sendRequests` read `REQUEST_FROM_USER`.

### Attaching a member

`addMember()` writes the pivot row and fires `MemberAdded`:

```php
$team->addMember($user);                   // a joined member
$team->addMember($user, 'admin');          // …and grant a team-scoped role

// A pending row instead — an invitation the user has yet to accept:
$team->addMember($user, joined: false);

// …or a request still awaiting the owner:
$team->addMember($user, joined: false, action: Membership::REQUEST_FROM_USER);
```

The call is a no-op when the user is already attached in any state, so it will
not upgrade a pending row to a joined one — accept the invitation or the
request through its own endpoint for that.

> **Note:** `MemberAdded` fires and any `$role` is granted for pending
> memberships too, so listeners run and team-scoped permissions take effect
> before the user has actually joined. Check `$team->hasMember($user)` in a
> listener that should only act on real members.

### Membership is what authorises a team action

Every team endpoint asks the same question first: **is the caller a joined
member of this team?** Being signed in is not enough, and neither is knowing a
team id.

```php
$team->hasMember($user);   // joined === true in team_user
```

Owning a team and belonging to it are separate records, so every path that
creates a team also attaches the creator as a member — `POST /neev/teams`,
the Blade create form, and the team auto-created at registration all do.

Refusals answer `403 You cannot perform this action on this team.` on the API,
or redirect back with that message in the error bag on the web. **A team that
does not exist is refused the same way as one that is not yours**, so the
endpoints never confirm which team ids are real.

| Endpoint | Who may call it |
|----------|-----------------|
| `PUT /neev/teams` (update) | any member |
| `GET /neev/domains` | any member |
| `PUT /neev/teams/request` (accept/reject a join request) | any member |
| `PUT {prefix}/teams/members/request/action` (the Blade form) | the owner |
| `PUT /neev/teams/leave` (remove a member) | any member; the owner cannot be removed |
| `PUT /neev/teams/leave` (revoke an invitation) | any member (the owner included), or the invitee declining their own |
| `PUT /neev/teams/inviteUser` | the owner |
| `DELETE /neev/teams` | the owner, and only when they own another team |
| `PUT /neev/teams/owner/change` | the owner, and only to an existing member |
| `PUT /neev/role/change` | a member, and only for another member of the same team |
| domain federate / update / delete | the owner |

> **Known asymmetry:** acting on a join request is owner-only on the Blade
> route and open to any member on the API route. Accepting a request admits
> someone to the team and can hand them a role, which is what inviting does,
> and inviting is owner-only — so the Blade rule is the defensible one. Pick
> one before relying on either.

A role scoped to a team is meaningless for somebody outside it, so
`PUT /neev/role/change` checks **both** sides: the caller must belong to the
team, and so must the user whose role is changing.

Invitations are addressed to one inbox, so only the account holding that
address may accept or reject one. Cancelling an invitation is a separate
question: a member of the team may revoke it, and the invitee may decline it,
but knowing an invitation id is not by itself authority to cancel it.

---

## Inviting Members

### Invite Existing User

If the email belongs to an existing user:

```bash
curl -X POST https://yourapp.com/neev/teams/inviteUser \
  -H "Authorization: Bearer {token}" \
  -d '{
    "team_id": 1,
    "email": "existing@example.com",
    "role": "member"
  }'
```

The user receives an email and sees the invitation in their account.

### Invite New User

If the email doesn't exist:

```bash
curl -X POST https://yourapp.com/neev/teams/inviteUser \
  -H "Authorization: Bearer {token}" \
  -d '{
    "team_id": 1,
    "email": "new@example.com",
    "role": "member"
  }'
```

A `TeamInvitation` is created and the user receives a registration link.

---

## Accepting/Rejecting Invitations

### For Existing Users

```bash
# Accept
curl -X PUT https://yourapp.com/neev/teams/inviteUser \
  -H "Authorization: Bearer {token}" \
  -d '{"team_id": 1, "action": "accept"}'

# Reject
curl -X PUT https://yourapp.com/neev/teams/inviteUser \
  -H "Authorization: Bearer {token}" \
  -d '{"team_id": 1, "action": "reject"}'
```

### For New Users (via invitation link)

The registration link includes the invitation:

```
https://yourapp.com/register?id=1&hash=abc123&signature=...
```

When the user registers, they're automatically added to the team.

---

## Join Requests

For public teams, users can request to join:

### Send Request

```bash
curl -X POST https://yourapp.com/neev/teams/request \
  -H "Authorization: Bearer {token}" \
  -d '{"team_id": 1}'
```

The team can be named by `slug` instead, for clients that only ever see the
readable handle:

```bash
curl -X POST https://yourapp.com/neev/teams/request \
  -H "Authorization: Bearer {token}" \
  -d '{"slug": "acme-labs"}'
```

`team_id` wins if both are sent; a body naming neither is refused. The Blade
route (`POST {prefix}/teams/members/request`) accepts the same two, plus an
`email` (the owner's) and `team` (the team name) pair. It tries `team_id`
first, then `slug`, then the pair.

A team whose domain federation is enforced or verified does not take join
requests — membership there follows from the verified domain instead.

The Blade team profile page is the one team page an outsider can open, so it
carries the **Request to join** button, and shows **Request pending** once a
request is in.

### Accept/Reject Request (Owner)

```bash
curl -X PUT https://yourapp.com/neev/teams/request \
  -H "Authorization: Bearer {token}" \
  -d '{
    "team_id": 1,
    "user_id": 5,
    "action": "accept",
    "role": "member"
  }'
```

---

## Leaving Teams

### Member Leaves

```bash
curl -X PUT https://yourapp.com/neev/teams/leave \
  -H "Authorization: Bearer {token}" \
  -d '{"team_id": 1}'
```

### Owner Removes Member

```bash
curl -X PUT https://yourapp.com/neev/teams/leave \
  -H "Authorization: Bearer {token}" \
  -d '{"team_id": 1, "user_id": 5}'
```

### Note: Owners Cannot Leave

Team owners cannot leave their team, and cannot be removed by another member.
They must transfer ownership first. The attempt answers
`403 You cannot perform this action on this team.`

---

## Roles and Permissions

Neev integrates with `ssntpl/laravel-acl` for role management.

### Assign Role

```bash
curl -X PUT https://yourapp.com/neev/role/change \
  -H "Authorization: Bearer {token}" \
  -d '{
    "team_id": 1,
    "user_id": 5,
    "role": "admin"
  }'
```

### Check Permissions

```php
// Check role in specific team
$user->hasRole('admin', $team);

// Check permission in specific team
$user->hasPermission('manage_users', $team);
```

---

## Transferring Ownership

```bash
curl -X POST https://yourapp.com/neev/changeTeamOwner \
  -H "Authorization: Bearer {token}" \
  -d '{
    "team_id": 1,
    "user_id": 5
  }'
```

The new owner must be an existing team member.

---

## Default Team

For users in multiple teams, the **default team** is a persisted preference — the team to land on after login. It is *not* the request-scoped team context (which comes from `TenantResolver`/`ContextManager`).

### Set Default Team (API)

```http
PUT /neev/teams/default
Authorization: Bearer {token}
Content-Type: application/json

{"team_id": 2}
```

### In Code

```php
$user->defaultTeam;            // The user's default team (preference, not request context)
$user->setDefaultTeam($team);  // Persist the preference (returns false if not a member)
```

On the web, `PUT /teams/switch` (route `teams.switch`) simply redirects to the selected team's profile page.

---

## Domain Federation

Automatically associate users with teams based on email domain. Available whenever teams are enabled (`'team' => true`) — there is no separate config toggle.

### Who may claim a domain

A domain belongs to **one team and one tenant** — never to two teams, or two tenants. A tenant and one of its teams may both federate the same company domain; a second team may not take a domain another team holds.

A claim only reserves the domain once it has been **verified**. An unverified row proves nothing and blocks nobody, so several teams may hold pending claims on the same domain and whichever verifies first wins. The same team cannot register the same domain twice — re-submitting it updates the existing row (rotating the verification token) instead of adding another.

### Members across several federated domains

When a team federates more than one domain, the "outside members" warning on the domain page counts a member as outside only if their address matches **none** of the team's verified domains. A member on `@acme.io` is not flagged against `@acme.com` when the team federates both.

### Add Domain to Team

```bash
curl -X POST https://yourapp.com/neev/domains \
  -H "Authorization: Bearer {token}" \
  -d '{
    "team_id": 1,
    "domain": "company.com",
    "enforce": false
  }'
```

**Response:**

```json
{
  "message": "Domain federated successfully.",
  "token": "abc123def456..."
}
```

### Verify Domain

Add a TXT record named `_neev-verification.{domain}` to your DNS with the token as its value:

```
_neev-verification.company.com.  TXT  "abc123def456..."
```

Then verify:

```bash
curl -X PUT https://yourapp.com/neev/domains \
  -H "Authorization: Bearer {token}" \
  -d '{"domain_id": 1, "verify": true}'
```

### Domain Enforcement

When `enforce` is true (and the domain is verified):
- Only users with a matching email domain can be invited
- Join requests are blocked
- Members with non-matching email domains are reported as `outside_members` in the domains listing

```bash
curl -X PUT https://yourapp.com/neev/domains \
  -H "Authorization: Bearer {token}" \
  -d '{"domain_id": 1, "enforce": true}'
```

---

## Domain Rules

Configure security policies for federated domains:

### Available Rules

| Rule | Description |
|------|-------------|
| `mfa` | Require MFA for domain users |

### Get Rules

```bash
curl -X GET "https://yourapp.com/neev/domains/rules?domain_id=1" \
  -H "Authorization: Bearer {token}"
```

A `domain_id` matching no domain answers `400 Domain not found.`; a domain
owned by a team you are not in answers `400 You do not have the required
permissions to get domain rules.` The two are told apart because a caller can
act on the first (fix the id) but not the second.

### Update Rules

```bash
curl -X PUT https://yourapp.com/neev/domains/rules \
  -H "Authorization: Bearer {token}" \
  -d '{"domain_id": 1, "mfa": true}'
```

---

## Team Activation

Teams can be activated or deactivated (waitlisted):

### Activate Team

```php
$team->activate();
```

### Deactivate Team

```php
$team->deactivate('subscription_expired');
```

### Check Status

```php
if ($team->isActive()) {
    // Team is active
}

$reason = $team->inactive_reason;  // Why it's inactive
```

Teams auto-created at registration are activated immediately. Enforcement is opt-in: apply the `neev-active-team` middleware alias (`EnsureTeamIsActive`) to routes that should reject inactive teams. Teams can also be activated from the CLI:

```bash
php artisan neev:team:activate {team}
```

---

## API Reference

### Team Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/neev/teams` | List user's teams |
| GET | `/neev/teams/invitations` | Get user's invitations and join requests |
| PUT | `/neev/teams/default` | Set the user's default team |
| GET | `/neev/teams/{id}` | Get team details |
| GET | `/neev/teams/slug/{slug}` | Get team details by slug |
| POST | `/neev/teams` | Create team |
| PUT | `/neev/teams` | Update team |
| DELETE | `/neev/teams` | Delete team |
| POST | `/neev/changeTeamOwner` | Transfer ownership |

### Member Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/neev/teams/inviteUser` | Invite member |
| PUT | `/neev/teams/inviteUser` | Accept/reject invitation |
| PUT | `/neev/teams/leave` | Leave team or remove member |
| POST | `/neev/teams/request` | Request to join |
| PUT | `/neev/teams/request` | Accept/reject request |
| PUT | `/neev/role/change` | Change member role |

### Domain Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/neev/domains` | List team domains |
| POST | `/neev/domains` | Add domain |
| PUT | `/neev/domains` | Update/verify domain |
| DELETE | `/neev/domains` | Delete domain |
| GET | `/neev/domains/rules` | Get domain rules |
| PUT | `/neev/domains/rules` | Update domain rules |
| PUT | `/neev/domains/primary` | Set primary domain |

---

## Database Schema

### teams Table

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| tenant_id | bigint (nullable) | Tenant reference (tenant mode) |
| user_id | bigint | Owner's user ID |
| name | string | Team name |
| slug | string | URL-friendly identifier |
| is_public | boolean | Can users request to join |
| activated_at | timestamp | When team was activated |
| inactive_reason | string | Why team is inactive |
| created_at | timestamp | Creation time |
| updated_at | timestamp | Last update time |

**Uniqueness:**

- `slug` is unique across the whole installation. That is what lets
  `Team::resolveBySlug()` and `GET /neev/teams/slug/{slug}` find a team
  without being told which tenant to look in.
- `(tenant_id, name, user_id)` is unique, so one owner cannot hold two teams
  of the same name inside a tenant. Scoping it to the tenant means the same
  owner may reuse a team name in a different tenant — names only have to be
  distinct within the tenant that sees them.

> **Caveat:** SQL treats `NULL`s as distinct in a unique index, so when
> `tenant_id` is `NULL` — every install running without tenants — the
> `(tenant_id, name, user_id)` index does not fire, and an owner *can* hold
> two teams with the same name. If uniqueness matters to you outside tenant
> mode, enforce it in validation or add a partial index for
> `tenant_id IS NULL`.

### team_user Table (Memberships)

| Column | Type | Description |
|--------|------|-------------|
| team_id | bigint | Team reference |
| user_id | bigint | User reference |
| joined | boolean | Has accepted |
| action | string | How relationship was created |
| created_at | timestamp | Creation time |
| updated_at | timestamp | Last update time |

Roles are stored in laravel-acl's polymorphic role assignment table, not on this pivot.

### team_invitations Table

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| team_id | bigint | Team reference |
| email | string | Invited email |
| role | string | Role to assign |
| expires_at | timestamp | Invitation expiry |
| created_at | timestamp | Creation time |
| updated_at | timestamp | Last update time |

### domains Table

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| owner_type | string | Polymorphic owner type (`team` or `tenant`) |
| owner_id | bigint | Polymorphic owner ID |
| domain | string | Email domain or web domain |
| is_primary | boolean | Primary domain |
| enforce | boolean | Enforce domain matching |
| verification_token | string | DNS verification token |
| verified_at | timestamp | When verified |
| verification_failed_at | timestamp | When re-verification last failed |
| created_at | timestamp | Creation time |
| updated_at | timestamp | Last update time |

---

## Next Steps

- [Multi-Tenancy](./multi-tenancy.md)
- [Security Features](./security.md)
- [API Reference](./api-reference.md)
