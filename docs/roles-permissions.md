# Roles & Permissions

How Neev stores roles, who assigns them, and what actually enforces them.

---

## Overview

Authorisation in a Neev app is three separate layers, and confusing them is the
usual source of surprise:

| Layer | Question it answers | Who enforces it |
|-------|---------------------|-----------------|
| **Membership** | Is the caller a joined member (or the owner) of this team/tenant? | Neev, on every team endpoint |
| **Roles & permissions** | Within that team, what is this member allowed to do? | **Your application** |
| **Token abilities** | Is the API token the request arrived on scoped for this call? | Neev, via `neev-token-can` |

Neev ships **no role names and no permission names**. It gives you the storage,
the assignment paths and the read helpers; naming `admin`, `editor` or
`billing.manage` and deciding what each one may do is application logic
([Architecture Internals](./architecture-internals.md) — *Role interpretation
belongs to application logic*). A fresh install has an empty `acl_roles` table,
which is why `addMember($user, 'admin')` throws until you create that role.

---

## The storage model

Roles come from [`ssntpl/laravel-acl`](https://github.com/ssntpl/laravel-acl),
a required dependency installed with Neev. Its migration creates five tables:

| Table | Holds |
|-------|-------|
| `acl_roles` | `name` + `resource_type`, unique together |
| `acl_permissions` | `name` (globally unique), optional `resource_type` |
| `acl_role_permissions` | role → permission, with an `effect` of `ALLOW` or `DENY` |
| `acl_role_assignments` | subject (morph) → role → resource (morph, nullable), with `expires_at` |
| `acl_permissions_implications` | parent permission → child permission |

Four properties of that shape matter in day-to-day use:

- **Roles are scoped by resource type.** `('admin', App\Models\Team)` and a
  global `('admin', null)` are two different rows, and a role name only
  resolves against the resource type it was created for. Neev's team roles are
  created with `resource_type = Ssntpl\Neev\Models\Team` (or your extending
  model — see [Extending Models](./README.md#extending-models)).
- **Assignments are polymorphic and per resource.** The same user can be
  `admin` on team A, `viewer` on team B, and hold a global role with
  `resource_id = null`.
- **One role per subject per resource.** `assignRole()` updates the existing
  assignment rather than adding a second one, so role changes are replacements,
  not accumulations. There is no "user has two roles on one team".
- **Assignments can expire.** `expires_at` in the past makes the assignment
  invisible to every read path; nothing deletes the row for you.

Permissions imply other permissions (`acl_permissions_implications`), so
granting `team.manage` can carry `team.read` with it, and a `DENY` on a role
wins over an `ALLOW` — including one that arrived through an implication.

### Caching

A role's effective permission list is cached for `acl.cache_ttl` seconds
(default 86400, `ACL_CACHE_TTL`). The package invalidates it on role,
permission and pivot writes; `php artisan acl:cache-reset` clears everything by
hand after a direct SQL change.

---

## Defining roles and permissions

Roles are data, so they belong in a seeder or a deploy step, not in config.
The ACL package ships the Artisan commands:

```bash
# A team-scoped role with permissions (created if they do not exist)
php artisan acl:create-role admin "Ssntpl\Neev\Models\Team" "team.manage|team.invite"

# A permission, optionally implying others
php artisan acl:create-permission team.manage "Ssntpl\Neev\Models\Team" --implied="team.read"

# Assign directly — global, or on one resource
php artisan acl:assign-role admin "Ssntpl\Neev\Models\User:1"
php artisan acl:assign-role admin "Ssntpl\Neev\Models\User:1" "Ssntpl\Neev\Models\Team:3" --expires-at="2026-12-31 23:59:59"

php artisan acl:cache-reset
```

The equivalent in a seeder:

```php
use Ssntpl\LaravelAcl\Models\Permission;
use Ssntpl\LaravelAcl\Models\Role;
use Ssntpl\Neev\Models\Team;

$manage = Permission::firstOrCreate(['name' => 'team.manage'], ['resource_type' => Team::class]);
$read   = Permission::firstOrCreate(['name' => 'team.read'], ['resource_type' => Team::class]);
$manage->children()->syncWithoutDetaching([$read->id]);

Role::firstOrCreate(['name' => 'admin', 'resource_type' => Team::class])
    ->syncPermissions(collect([$manage]));
```

> **Seed before you invite.** Invitations, `addMember()`, the member CLI and the
> role-change endpoints all resolve a role *by name*, and an unknown name is an
> error, not a silent skip. See [Failure modes](#failure-modes).

---

## Where Neev assigns roles

`User` uses the package's `HasRoles` trait, so `assignRole()`, `getRole()`,
`hasRole()` and `removeRole()` are available on every user. Neev calls them
from exactly these places:

| Path | What it does |
|------|--------------|
| `$team->addMember($user, 'admin')` | Attaches the membership and grants the team-scoped role in one transaction |
| Accepting an invitation | Grants `invitation->role`, the role chosen when the invite was sent |
| Registration via an invitation | Same, applied as the account is created ([`RegistrationService`](../src/Services/RegistrationService.php)) |
| Tenant SSO auto-provisioning | Grants the tenant's `auto_provision_role` to users created by an SSO login ([Multi-Tenancy](./multi-tenancy.md)) |
| `PUT /neev/role/change` and the Blade form | Changes a member's role, or the role attached to a pending invitation |
| `php artisan neev:member:add --role=` | Grants as it attaches ([CLI Commands](./cli-commands.md#member-management)) |
| `$team->removeUser($user)` | **Deletes** the team-scoped assignment with the membership |

Two of these are worth spelling out.

**A role granted to a pending member takes effect immediately.** `addMember()`
grants the role even when `joined: false`, so team-scoped permissions are live
before the user has accepted. Check `$team->hasMember($user)` in any listener
or policy that should only treat real members as members.

**Removing a member removes the role.** Left behind, a team-scoped assignment
would silently come back the day that user rejoins the team, so `removeUser()`
detaches the pivot and drops the assignment in one transaction.

### Changing a role

```http
PUT /neev/role/change
```

```json
{
    "resource_type": "Ssntpl\\Neev\\Models\\Team",
    "resource_id": 1,
    "user_id": 42,
    "role": "admin"
}
```

Send `invitation_id` instead of `user_id` to re-aim a pending invitation at a
different role. A role scoped to a team means nothing to somebody outside it,
so the endpoint checks **both** sides: the caller must belong to the resource,
and so must the user whose role is changing. A resource the caller does not
belong to is refused exactly like one that does not exist.

"Belongs" here means *attached in any state*. A user who was invited, or who
asked to join and is still waiting, may have their role changed — consistent
with `addMember()`, which grants a role to a pending row, and with the
invitation branch, which has always been able to change a role nobody holds
yet. A user attached in no state at all is refused.

Full request/response detail lives in the
[API Reference](./api-reference.md#change-member-role); the Blade route is
`PUT /teams/roles/change` ([Web Routes](./web-routes.md)).

---

## What Neev enforces — and what it does not

**Neev's own endpoints authorise on membership and ownership, never on a
role.** Any joined member may update the team or list domains; the owner alone
may invite, delete the team, hand over ownership or federate a domain. The full
table is in [Teams — Membership is what authorises a team action](./teams.md#membership-is-what-authorises-a-team-action).

Assigning someone the `viewer` role therefore does **not** stop them calling
`PUT /neev/teams`. Roles describe your application's permissions, and your
application checks them:

```php
$role = $user->getRole($team);          // ?Role — null when nothing is assigned

if ($role?->can('project.delete')) {
    // allowed
}

$user->hasRole(['admin', 'owner'], $team);   // name or id, team-scoped
$user->hasRole('super-admin');               // global assignment
```

`can()` is the check to reach for: it honours `DENY` and implied permissions,
where `hasRole()` only compares names.

A Laravel gate keeps the check in one place:

```php
Gate::define('project.delete', fn ($user, $project) =>
    $user->getRole($project->team)?->can('project.delete') ?? false
);
```

The ACL package also ships `CheckGlobalRole` and `CheckGlobalPermission`
middleware for **global** (resource-less) assignments — useful for a platform
admin area. Neither is aliased for you; register them in `bootstrap/app.php`:

```php
$middleware->alias([
    'role' => \Ssntpl\LaravelAcl\Http\Middleware\CheckGlobalRole::class,
    'permission' => \Ssntpl\LaravelAcl\Http\Middleware\CheckGlobalPermission::class,
]);

Route::middleware(['neev:web', 'role:super-admin'])->group(/* … */);
Route::middleware(['neev:api', 'permission:tenant.suspend|tenant.delete'])->group(/* … */);
```

Both take `|`-separated names and pass when **any** one matches, and both
answer JSON — `401` with no user, `403` with no matching role or permission.

---

## Reading roles for a list

`getRole()` answers for one subject and one resource, builds a fresh query
every call and caches nothing — so a member list that names the role on every
row pays a query per row. `Ssntpl\Neev\Support\TeamRoles` asks the same
question for a whole list in a single query:

```php
use Ssntpl\Neev\Support\TeamRoles;

// One resource, many subjects — a team's member list
$memberRoles = TeamRoles::forSubjects($team, $team->users->concat($team->invitedUsers));
$memberRoles[$user->id] ?? null;      // role name, or absent

// One subject, many resources — a user's team list
$teamRoleNames = TeamRoles::forResources($user, $user->teams);
$teamRoleNames[$team->id] ?? null;
```

Both return `[id => role name]` and both skip expired assignments. A subject or
resource with **no** live assignment is simply absent from the map, which is the
caller's cue to fall back the way it always did. The subjects (or resources) are
assumed to be of one class, since they come from a single relation.

This is what the member page, the account teams page and
`php artisan neev:member:list` use.

---

## API token abilities

Token abilities are a separate axis from roles: they scope *what a given
credential may do*, not *who the user is in a team*. A token holding `write`
still only reaches teams its user belongs to.

```php
$token = $user->createApiToken('ci', ['read', 'write']);
```

```php
Route::middleware(['neev:api', 'neev-token-can:read,write'])->post('/sync', …);
```

`neev-token-can` requires **every** listed ability and is fail-closed — a
request with no token is refused. Login tokens carry full authority (`can()` is
always true), and an API token created with no abilities can do nothing guarded.
See [Security — Token Permissions](./security.md#token-permissions).

---

## Failure modes

| Symptom | Cause |
|---------|-------|
| `Role not found.` / `400` from an invite, member add or role change | The role name does not resolve for that `resource_type`. Seed it first — names are per resource type. |
| `InvalidArgumentException: Role not found` from `assignRole()` | Same cause, uncaught. Neev's controllers and `neev:member:add` translate it into a refusal and roll the whole operation back, so a bad role name never leaves a member attached with no role. |
| A member keeps a role after being removed from the team | Something detached the pivot directly instead of calling `$team->removeUser($user)`. |
| A permission change does not take effect | The role's effective permissions are cached. `php artisan acl:cache-reset`. |
| `hasRole('admin')` is false although the user is a team admin | `hasRole()` without a resource asks about the **global** assignment. Pass the team: `hasRole('admin', $team)`. |
| Two roles wanted on one team | Not supported — one assignment per subject per resource. Model it as a role whose permissions are the union. |

---

## Related

- [Teams](./teams.md) — membership, invitations, and the endpoint-by-endpoint authorisation table
- [Security](./security.md) — token permissions, sessions, brute-force protection
- [Multi-Tenancy](./multi-tenancy.md) — `auto_provision_role` for SSO-provisioned users
- [CLI Commands](./cli-commands.md) — `neev:member:add --role=`, `neev:member:list`
- [API Reference](./api-reference.md#change-member-role) — `PUT /neev/role/change`
