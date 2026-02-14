# Team Management

Complete guide to team/organization management in Neev.

---

## Overview

Neev's team system allows users to:

- Create and manage teams/organizations
- Invite members via email
- Assign roles and permissions
- Configure domain-based auto-joining
- Switch between multiple teams

---

## Configuration

### Enable Teams

```php
// config/neev.php
'team' => true,
```

### Team Slugs

```php
// config/neev.php
'slug' => [
    'min_length' => 2,
    'max_length' => 63,
    'reserved' => ['www', 'api', 'admin', 'app', 'mail', 'ftp'],
],
```

### Domain Federation

```php
// config/neev.php
'domain_federation' => true,  // Enable domain-based auto-joining
```

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

// Switch current team
$user->switchTeam($team);

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
  "status": "Success",
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

When users register (with teams enabled), a personal team is created:

```php
$team = Team::forceCreate([
    'name' => explode(' ', $user->name, 2)[0] . "'s Team",
    'user_id' => $user->id,
    'is_public' => false,
]);

$team->users()->attach($user, ['joined' => true]);
```

---

## Team Membership

### Membership Model

The `Membership` model represents user-team relationships:

| Column | Type | Description |
|--------|------|-------------|
| team_id | bigint | Team reference |
| user_id | bigint | User reference |
| role | string | User's role in team |
| joined | boolean | Has accepted invitation |
| action | string | How relationship was created |

### Actions

- `request_to_user` - Team invited the user
- `request_from_user` - User requested to join

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

Team owners cannot leave their team. They must transfer ownership first.

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

## Team Switching

For users in multiple teams:

### Switch Team

```http
PUT /teams/switch
Content-Type: application/json

{"team_id": 2}
```

### Current Team

```php
$user->currentTeam;  // The user's active team
```

---

## Domain Federation

Automatically associate users with teams based on email domain.

### Enable Domain Federation

```php
// config/neev.php
'team' => true,
'domain_federation' => true,
```

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
  "status": "Success",
  "message": "Domain federated successfully.",
  "token": "neev-verification=abc123def456..."
}
```

### Verify Domain

Add a TXT record to your DNS:

```
TXT neev-verification=abc123def456...
```

Then verify:

```bash
curl -X PUT https://yourapp.com/neev/domains \
  -H "Authorization: Bearer {token}" \
  -d '{"domain_id": 1, "verify": true}'
```

### Domain Enforcement

When `enforce` is true:
- Only users with matching email domain can join
- Existing non-domain users are deactivated

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

### Company Email Requirement

When `require_company_email` is enabled:

- Users with free email providers (Gmail, Yahoo) get waitlisted teams
- Teams are created but `activated_at` is null
- Admin must manually activate

---

## API Reference

### Team Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/neev/teams` | List user's teams |
| GET | `/neev/teams/{id}` | Get team details |
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
| user_id | bigint | Owner's user ID |
| name | string | Team name |
| slug | string | URL-friendly identifier |
| is_public | boolean | Can users request to join |
| activated_at | timestamp | When team was activated |
| inactive_reason | string | Why team is inactive |
| created_at | timestamp | Creation time |
| updated_at | timestamp | Last update time |

### team_user Table (Memberships)

| Column | Type | Description |
|--------|------|-------------|
| team_id | bigint | Team reference |
| user_id | bigint | User reference |
| role | string | User's role |
| joined | boolean | Has accepted |
| action | string | How relationship was created |
| created_at | timestamp | Creation time |
| updated_at | timestamp | Last update time |

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
| team_id | bigint | Team reference |
| domain | string | Email domain |
| is_primary | boolean | Primary domain |
| enforce | boolean | Enforce domain matching |
| verification_token | string | DNS verification token |
| verified_at | timestamp | When verified |
| created_at | timestamp | Creation time |
| updated_at | timestamp | Last update time |

---

## Next Steps

- [Multi-Tenancy](./multi-tenancy.md)
- [Security Features](./security.md)
- [API Reference](./api-reference.md)
