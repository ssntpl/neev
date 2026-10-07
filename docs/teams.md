# Team Management

Complete guide to team/organization management in Neev.

---

## Overview

Neev's team system allows users to:

- Create and manage teams/organizations
- Invite members via email
- Assign roles and permissions
- Claim email domains whose users belong to the team
- Serve the team at custom hosts
- Belong to multiple teams and set a default team

---

## Configuration

### Enable Teams

```php
// config/neev.php
'team' => true,
```

This is what registers the team routes — `/teams/*` and `/account/teams` on the
web, `/neev/teams/*`, `/neev/email-domains/*`, `/neev/hostnames/{hostname}/*`
and `/neev/changeTeamOwner` on the API. `GET /neev/hostnames/current` is the
exception: it answers the resolved context, team or not, so it is always registered.
With `'team' => false` they are not registered at all, so the paths answer 404
and `route('teams.create')` throws; guard any link with
`@if (config('neev.team'))`.

### Team Slugs

```php
// config/neev.php
'slug' => [
    'min_length' => 2,
    'max_length' => 63,
    'retired_host_days' => 90,
    'reserved' => ['www', 'api', 'admin', 'app', 'mail', /* ... */],
],
```

Slugs are auto-generated from the team name on creation (normalized, uniquified, reserved words avoided). See [Slugs](#slugs) below for uniqueness and renaming.

### Email Domains and Hostnames

Email domains and custom hosts have no config toggle — they are available whenever teams are enabled. See [Email Domains](#email-domains) and [Custom Hosts](#custom-hosts) below.

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
$team->emailDomains;   // Email domains whose users belong to the team
$team->hostnames;      // Custom hosts the team is served at
$team->primaryHostname; // The host marked primary, if any

// Check membership
$team->hasUser($user);

// Team status
$team->isActive();
$team->activate();
$team->deactivate('subscription_expired');
```

`domains()`, `domain()`, `primaryDomain()` and `customDomains()` still read the
old `domains` table, through the deprecated `Ssntpl\Neev\Models\Domain`. They
are read-only and are removed with the table in the next release; use
`emailDomains()`, `hostnames()` and `primaryHostname()`.

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

When users register (with teams enabled), a personal team is created — unless they registered via a team invitation link, or their email domain is a verified [email domain](#email-domains) (`EmailDomain::isVerifiedForEmail()`; under tenant isolation only a claim inside the current tenant counts):

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

Roles are not stored on the pivot — they are managed by `ssntpl/laravel-acl` via polymorphic role assignments scoped to the team. How those roles are defined, assigned and checked — and why Neev's own endpoints authorise on membership rather than on a role — is covered in [Roles & Permissions](./roles-permissions.md).

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
| `GET /neev/teams/{team}/email-domains`, `/hostnames` | any member |
| `PUT /neev/teams/request` (accept/reject a join request) | any member |
| `PUT {prefix}/teams/members/request/action` (the Blade form) | the owner |
| `PUT /neev/teams/leave` (remove a member) | any member, and only for another member of the same team; the owner cannot be removed |
| `PUT /neev/teams/leave` (withdraw a pending membership) | any member, or the user it names withdrawing their own invitation or join request |
| `PUT /neev/teams/leave` (revoke an invitation) | any member (the owner included), or the invitee declining their own |
| `PUT /neev/teams/inviteUser` | the owner |
| `DELETE /neev/teams` | the owner, and only when they own another team |
| `POST /neev/changeTeamOwner` | the owner, and only to an existing member |
| `PUT /neev/role/change` | a member, and only for another user attached to the same team — joined or still pending |
| email domain add / enforce / verify / token / delete (API and Blade) | any member, or the owner; narrow it with your own middleware |
| hostname add / verify / token / primary / delete (API and Blade) | any member, or the owner; narrow it with your own middleware |

The email-domain and hostname endpoints take the team in the path, so a team id
that does not exist answers `404` there, and a refusal answers
`403 You do not have permission to do this.` On the API and the Blade pages
alike, Neev checks only that the caller belongs to the team; which members may
change its hosts and email domains is the application's to decide, with its
own middleware on those routes.

> **Known asymmetry:** acting on a join request is owner-only on the Blade
> route and open to any member on the API route. Accepting a request admits
> someone to the team and can hand them a role, which is what inviting does,
> and inviting is owner-only — so the Blade rule is the defensible one. Pick
> one before relying on either.

A role scoped to a team is meaningless for somebody outside it, so
`PUT /neev/role/change` checks **both** sides: the caller must belong to the
team, and so must the user whose role is changing — though an invited user or
a pending applicant counts, since `addMember()` grants them roles too.

**An invitation link carries a secret.** The mail to an address with no
account yet links to registration with the invitation id and 32 random bytes;
only the hash of those bytes is stored, so holding the link is the proof that
the invitation reached that inbox — which is what lets redemption mark the
address verified and assign the invited role. A wrong or missing secret, an
invitation past its seven-day deadline, or a different address being
registered are each refused. Inviting the same address again issues a fresh
secret and invalidates the previous link.

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

A team does not take join requests once any of its email domains is verified —
membership there follows from the verified domain instead. `Team::acceptsJoinRequests()` answers the same
question, and the Blade profile page shows **Request to join** only when it is
true.

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

### Members on an Enforced Email Domain

Removing a member whose email is on one of the team's email domains that is both verified and **enforced** does not take them out of the team: the domain governs their membership, so their account is **deactivated** instead (`User Deactivated Successfully`). Removing them again reactivates it (`User Activated Successfully`). `Team::managesAccountOf($email)` answers whether the team manages an address this way.

Deactivation reaches the whole account, so only enforcing grants it. Verifying a domain is not exclusive: several teams may verify `acme.com`, but only one may enforce it, so only that team can deactivate an `acme.com` account.

A domain that is not enforced, or not verified, manages nobody. Removing a member on it detaches them like any other member (`Removed Successfully`), and they may leave on their own. That includes a domain whose enforcement was turned off, or one the re-check has unverified. A deactivated member whose email is on a domain the team still holds is reactivated as they are removed, so a member deactivated through that domain is not left locked out of the whole application. Neev does not record which team deactivated an account, so when another team the member belongs to also holds a claim on that domain, the account is left deactivated; removing them from a team that is the only one of theirs with a claim on it does reactivate them. A deactivated member on no domain of the team's keeps that state. `Team::reactivatesOnRemoval($user)` answers whether removing a member gives their account back. Members on other addresses are removed as usual.

Only members of the team can be removed, deactivated or reactivated this way. A `user_id` with no membership in the team answers `403 You cannot perform this action on this team.`, even when that user's email is on one of the team's enforced email domains, since deactivation reaches their whole account.

A pending membership (an invitation not yet accepted, or a join request not yet answered) is simply withdrawn (`Removed Successfully`), never deactivated, whatever the user's domain. Any member can withdraw it, and so can the user it names, by sending only `team_id`. The **Remove** button under pending invitations on the members page and **Revoke** on a sent request on the account teams page both do this.

Rejecting (`PUT /neev/teams/inviteUser` with `team_id` and `"action": "reject"`, or `PUT /neev/teams/request` with `"action": "reject"`) also acts only on a membership not yet joined. A joined member is never removed that way; it answers `400 Invitation not found` / `400 Request not found`, and removing a member goes through `leave` and the rules above.

A member on an enforced domain cannot remove themselves: deactivation is account-wide, so leaving would lock them out of the whole application. The attempt answers `403 You cannot leave a team your email domain manages.`, and the Blade pages do not offer **Leave** to them. Members on other addresses, including a domain the team verifies but does not enforce, can leave, and the Blade pages offer them **Leave**. `Team::managesAccountOf($email)` tells whether an address is on one of the team's enforced, verified email domains; `Team::hasVerifiedDomainFor($email)` whether it is on one of its verified email domains, enforced or not; and `Team::holdsDomainFor($email)` whether it is on any email domain the team holds, verified or not.

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

## Email Domains

An email domain says "users at this domain belong to this team". Claims live in
`email_domains` (model `Ssntpl\Neev\Models\EmailDomain`). Available whenever
teams are enabled (`'team' => true`) — there is no separate config toggle.

An email domain is about membership, not serving: it does not make the team
reachable at that host. That is a [custom host](#custom-hosts).

### Who may claim a domain

Claiming is **not exclusive**. Several teams (and tenants) may each claim and
verify the same domain — two subsidiaries may both verify `acme.com`, each with
its own TXT record. One team holds a domain once: re-submitting it updates the
existing row instead of adding another. The domain is compared in its canonical
form (lowercase, no trailing dot), so `ACME.com.` is the same domain as `acme.com`.

**Enforcing is exclusive.** Only one owner's verified row may enforce a domain.
Asking to enforce a domain another owner already enforces throws
`Ssntpl\Neev\Exceptions\EmailDomainEnforcedException`
(`Another owner already enforces this email domain.`); the API answers `422`
with that message on `enforce`. A pending row may ask to enforce, so the first
owner to verify and enforce keeps it: a row that becomes verified while another
owner already enforces is still verified, with `enforce` turned off. Neev warns
rather than hiding it: the API answers `enforce_dropped: true` with a message
saying so, the Blade page flashes it, `neev:email-domain:verify` prints a
warning, and `EmailDomainEnforceDropped` fires, including when the daily
re-check restores the row.

A claim does nothing until it is **verified**. Re-submitting a domain issues a
new verification token, and so does asking for one (`POST .../token`, or
**Get Token** on the page). `enforce` on the add request applies to a new claim
only, so re-submitting leaves it as it was; turn it on or off with `PATCH`.
Only the token changes: a verified domain stays
verified, and the daily re-check holds it to the new token's record. Publish the
new record before that runs, or the domain fails and, after
`neev.dns_verification.unverify_after_failed_days`, is unverified like any
domain whose record went missing.

A row has a `status`: `pending`, `verified`, `failed` (proven, but the record
has been missing since `verification_failed_at`) or `disabled` (disabled by the
app; a disabled domain gets no new token and is not checked). A verified domain
whose record goes missing keeps counting for
`neev.dns_verification.unverify_after_failed_days`, is then unverified, and is
deleted at twice that.

### Members across several email domains

When a team claims more than one domain, a member counts as outside the team's
boundary only if their address matches **none** of its verified email domains.
A member on `@acme.io` is not flagged against `@acme.com` when the team holds
both. `Team::membersOutsideEmailDomains()` returns that count while any verified
domain is enforced (`Team::enforcesDomain()`), and `0` otherwise.

### Add an Email Domain

```bash
curl -X POST https://yourapp.com/neev/teams/1/email-domains \
  -H "Authorization: Bearer {token}" \
  -d '{
    "domain": "company.com",
    "enforce": false
  }'
```

**Response** (`201` for a new claim, `200` when the team already held it and
a new token was issued):

```json
{
  "message": "Email domain added.",
  "data": {"id": 1, "domain": "company.com", "status": "pending", "enforce": false, "...": "..."},
  "dns_record": {
    "type": "TXT",
    "name": "_neev-email.company.com",
    "value": "abc123def456..."
  }
}
```

`dns_record` says exactly what to publish: a `TXT` record at `name` whose value
is the token. The token is hidden from `data`. The Blade page's token dialog
shows the same record name and value.

A `domain` that is not a host name — missing, not a string, nothing once
canonicalised (`...`), a URL, a path, a port, a space, a single label such as
`localhost`, or an IP address — is refused with a `422` validation error.
Internationalised names are accepted in their punycode form (`xn--mnchen-3ya.de`).
A disabled domain is refused with `422` too.

In code, `$team->federateDomain($domain, $enforce)` does the same: it claims the
domain, or re-issues the token of one the team holds, and returns the
`EmailDomain` with its token.

### Verify an Email Domain

Add a TXT record named `_neev-email.{domain}` with the token as its value:

```
_neev-email.company.com.  TXT  "abc123def456..."
```

Then verify:

```bash
curl -X POST https://yourapp.com/neev/email-domains/1/verify \
  -H "Authorization: Bearer {token}"
```

A record that is not there answers `400 DNS verification failed. Please check your DNS record.`; a disabled domain answers `400 This domain is disabled.`

To get a new token (for example when the old one was lost), call
`POST /neev/email-domains/{id}/token`; the response carries `dns_record` as
above. The domain keeps its status; publish the new record before the next
re-check.

A domain copied from the old `domains` table also passes on the record
published for it there, `_neev-verification.{domain}`, while
`neev.dns_verification.legacy_record` is on. That fallback is for this release
only; publish `_neev-email` records and turn it off.

#### Verifying from your own code

`$emailDomain->verify()` checks DNS and records the result, firing
`DomainVerified` when a pending row is proven. `$emailDomain->generateVerificationToken()`
issues a new token and changes nothing else; it returns `null` for a disabled
row.

To mark a domain verified without DNS, use the CLI:
`php artisan neev:email-domain:add {domain} --skip-verification` or
`php artisan neev:email-domain:verify {domain} --force`.

### Enforcement

When `enforce` is true on any of the team's verified email domains:
- Only users whose email is on one of the team's **verified** email domains can be invited — not only the domain that is enforced
- Members whose email matches none of the team's verified email domains are reported as `outside_members` on each enforced, verified domain in the listing, the same count the Blade page shows
- Removing a member whose email is on the enforced domain deactivates their account instead, and they cannot leave on their own (see [Members on an Enforced Email Domain](#members-on-an-enforced-email-domain)). Without `enforce`, a verified domain only federates sign-ups and closes the team to join requests; its members are removed and may leave like any other

```bash
curl -X PATCH https://yourapp.com/neev/email-domains/1 \
  -H "Authorization: Bearer {token}" \
  -d '{"enforce": true}'
```

Join requests are refused by any verified email domain, enforced or not (see
[Join Requests](#join-requests)).

### Deleting an Email Domain

Deleting a domain reactivates the team's deactivated members whose email is on
it, including after it has been unverified: once the domain is gone
nothing manages them, and the package would offer no way to reactivate them. A
member another team they belong to also holds that domain for is left
deactivated, since Neev does not record which team deactivated an account and
that team may be the one that did; once the last claim on the domain is
deleted, they are reactivated.

From your own code, `$emailDomain->deleteAndReactivate()` does both in one
transaction, and `$team->reactivateMembersOn($domain)` runs the reactivation on
its own. Deleting a team deletes its email domains the same way.

### The Blade page

`GET /teams/{team}/email-domains` (`teams.email-domains`) lists the domains,
adds one, toggles **Enforce**, and offers **Get Token**, **Verify** and
**Delete** for each. Every member sees the page, its left-section **Email
Domains** link and its controls; Neev checks only that the caller belongs to
the team, so narrow it with your own middleware if only some should. See
[Web Routes](./web-routes.md#email-domains).

---

## Custom Hosts

A hostname says "this team is served at this host". Rows live in `hostnames`
(model `Ssntpl\Neev\Models\Hostname`). Only custom hosts are stored: a team's
platform subdomain is its slug under `neev.platform_domain` and is derived, not
written (shared mode only; in isolated mode the tenant has the subdomain).

### Who may claim a host

A host is **unique across every owner**. A claim by another owner, verified or
not, holds it: claiming it throws `Ssntpl\Neev\Exceptions\HostnameTakenException`
(`This host cannot be added.`). The message does not say who holds the host,
since the holder may be in another tenant. A host under `neev.platform_domain`
cannot be claimed at all; it follows a slug.

A host serves only once it is **verified** by a TXT record at
`_neev-host.{host}`. Like email domains, it re-checks daily, keeps serving for
`neev.dns_verification.unverify_after_failed_days` after its record goes missing,
then stops serving, and is deleted at twice that, freeing it for another owner.

### Add and verify a host

```bash
curl -X POST https://yourapp.com/neev/teams/1/hostnames \
  -H "Authorization: Bearer {token}" \
  -d '{"host": "app.company.com"}'
```

The `201` response carries `data` and `dns_record`
(`{"type": "TXT", "name": "_neev-host.app.company.com", "value": "..."}`).
Publish it, then `POST /neev/hostnames/{id}/verify`. `POST /neev/hostnames/{id}/token`
issues a new token; a verified host keeps serving, and the daily re-check holds
it to the new record.

In code: `$team->claimHost($host)` returns the team's row (pending, with its
token), `$hostname->verify()` checks DNS, and `$team->releaseHost($host)` or
`$hostname->release()` deletes it.

### Primary hostname

`teams.primary_hostname_id` points at the host marked primary. Only a verified
host of the team can be primary (`POST /neev/hostnames/{id}/primary`, or
`$team->makePrimaryHostname($hostname)`); anything else answers
`400 Only a verified host can be primary.` Deleting the primary host unpoints it.

`$team->canonicalHost()` answers where the team is served: its verified primary
host, else its oldest verified host, else its platform subdomain.
`$team->webDomain` is the verified primary host only, or `null`.

### The Blade page

`GET /teams/{team}/hostnames` (`teams.hostnames`) lists the platform subdomain
and the custom hosts, and offers **Make Primary**, **Get Token**, **Verify** and
**Delete**. Every member sees the page, its left-section **Hostnames** link and
its controls; Neev checks only that the caller belongs to the team, so narrow
it with your own middleware if only some should. See
[Web Routes](./web-routes.md#hostnames).

---

## Slugs

A team slug is unique **per tenant** in isolated mode (the `(tenant_id, slug)`
index) and **installation-wide** in shared mode, where `tenant_id` is null and
the index cannot stop two nulls, so the save checks it instead.

Slugs can change but are **never reissued**. Renaming a team retires its old
slug in `retired_slugs` for good: in shared mode the platform subdomain is the
slug, and a slug handed to someone else would hand them a host that SSO redirect
URIs, emailed links and password managers still trust. In isolated mode a team
slug retires within its tenant only. The team may take its own old slug back.

Saving a slug another team holds, or one another team has retired, throws
`Ssntpl\Neev\Exceptions\SlugUnavailableException`
(`The slug "acme" is not available.`). Generated slugs skip retired ones.

A rename fires `Ssntpl\Neev\Events\SlugChanged` (`$owner`, `$oldSlug`,
`$newSlug`) after the transaction commits — the place to tell the owner to
update what still points at the old host. The old platform host keeps serving,
with a `302` for page navigations, for `neev.slug.retired_host_days` (90 by
default); the slug itself stays retired after that.

Only model saves are guarded. A query-builder update bypasses all of this.

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

### Email Domain Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/neev/teams/{team}/email-domains` | List the team's email domains |
| POST | `/neev/teams/{team}/email-domains` | Add a domain, or re-issue its token |
| GET | `/neev/email-domains/{id}` | Get one email domain |
| PATCH | `/neev/email-domains/{id}` | Set `enforce` |
| DELETE | `/neev/email-domains/{id}` | Delete, reactivating the members it deactivated |
| POST | `/neev/email-domains/{id}/verify` | Check the `_neev-email` record |
| POST | `/neev/email-domains/{id}/token` | Issue a new token |

### Hostname Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/neev/teams/{team}/hostnames` | List custom hosts, with `platform_host` and `primary_hostname_id` |
| POST | `/neev/teams/{team}/hostnames` | Claim a host |
| GET | `/neev/hostnames/{id}` | Get one host |
| DELETE | `/neev/hostnames/{id}` | Release a host |
| POST | `/neev/hostnames/{id}/verify` | Check the `_neev-host` record |
| POST | `/neev/hostnames/{id}/token` | Issue a new token |
| POST | `/neev/hostnames/{id}/primary` | Make a verified host primary |
| GET | `/neev/hostnames/current` | The context this request resolved to (always registered) |

Adding, verifying and re-issuing a token share one limit, 10 a minute per user (`neev-dns`).

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
| primary_hostname_id | bigint (nullable) | The primary host (`hostnames.id`), nulled when that host is deleted |
| created_at | timestamp | Creation time |
| updated_at | timestamp | Last update time |

**Uniqueness:**

- `(tenant_id, slug)` is unique, so in isolated mode a slug is unique within
  its tenant. In shared mode `tenant_id` is `NULL`, which a unique index does
  not compare, so the save itself refuses a slug another team holds and the
  slug is unique across the installation. See [Slugs](#slugs).
- Team names may repeat, even for one owner.

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

### email_domains Table

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| owner_type | string | Polymorphic owner type (`team` or `tenant`) |
| owner_id | bigint | Polymorphic owner ID |
| domain | string | Email domain, canonical form |
| status | string | `pending`, `verified`, `failed` or `disabled` |
| verification_strategy | string | `dns`, or `manual` when verified from the CLI without DNS |
| verification_token | string | DNS verification token |
| verified_at | timestamp | When verified; the domain counts while set |
| verification_failed_at | timestamp | When the record was first found missing |
| enforce | boolean | Only users on the team's verified email domains may be invited |
| created_at | timestamp | Creation time |
| updated_at | timestamp | Last update time |

`(owner_type, owner_id, domain)` is unique: one claim per owner, many owners per domain.

### hostnames Table

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| owner_type | string | Polymorphic owner type (`team` or `tenant`) |
| owner_id | bigint | Polymorphic owner ID |
| host | string | Custom host, canonical form; unique across every owner |
| status | string | `pending`, `verified`, `failed` or `disabled` |
| verification_token | string | DNS verification token |
| verified_at | timestamp | When verified; the host serves while set |
| verification_failed_at | timestamp | When the record was first found missing |
| created_at | timestamp | Creation time |
| updated_at | timestamp | Last update time |

### retired_slugs Table

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| owner_type | string | Polymorphic owner type (`team` or `tenant`) |
| owner_id | bigint | The owner that gave the slug up |
| slug | string | The retired slug |
| created_at | timestamp | When it was retired |
| updated_at | timestamp | Last update time |

Rows are kept forever. The old `domains` table is still created and read by the
deprecated `Domain` model for this release only; nothing writes to it.

---

## Next Steps

- [Multi-Tenancy](./multi-tenancy.md)
- [Security Features](./security.md)
- [API Reference](./api-reference.md)
