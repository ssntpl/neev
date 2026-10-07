# CLI Commands

Complete reference for all Neev Artisan commands. Commands adapt to your [identity mode](./architecture.md) — in **shared** mode (`'tenant' => false`) they operate on teams, in **isolated** mode (`'tenant' => true`) they operate on tenants.

> Run `php artisan list neev` to see all available commands.

---

## Setup & Maintenance

These commands handle initial setup and scheduled maintenance.

### `neev:install`

Interactive setup wizard for new installations.

```bash
php artisan neev:install

# Non-interactive: {tenant} {teams} {kit}
php artisan neev:install yes no blade
```

Prompts for: multi-tenant isolation (`yes`/`no`), team support (`yes`/`no`), and the frontend starter kit (`blade`/`none`, default `blade`). Publishes the config file, sets the `tenant` and `team` options accordingly, and calls `neev:ui` to eject the chosen kit (and, always, the email templates). Only runs on a fresh installation: it fails if the `users` table has records. If
the database cannot be reached it warns that the check was skipped and carries
on — nothing the command does touches the database, and it is documented to run
before `php artisan migrate`, which repeats the check and refuses where it
matters.

**Arguments are validated up front.** All three are checked before anything is published or edited, and every bad value is reported at once, so a typo cannot leave a half-configured application behind:

```
$ php artisan neev:install y yes vue
Installation aborted — nothing was changed:
  tenant: [y] is not valid. Expected yes or no.
  kit: [vue] is not valid. Expected blade or none.
```

Only `yes`/`no` and `blade`/`none` are accepted — the values the interactive prompts produce. Anything else fails rather than being silently read as "no". The command also propagates `neev:ui`'s exit code, so a failed kit ejection no longer reports success.

### `neev:ui`

Eject a frontend starter kit into the application. Also the way to switch an existing install between Blade and headless.

```bash
php artisan neev:ui blade            # eject the Blade kit
php artisan neev:ui blade --force    # overwrite files that already exist in the app
php artisan neev:ui none             # headless — no page views, page routes disabled
```

| Argument / Option | Description |
|-------------------|-------------|
| `kit` | Starter kit to eject: `blade` or `none` |
| `--force` | Overwrite files that already exist in the app (without it, existing files are kept) |

What it does:

- **`blade`**: copies the package's Blade page views (auth, account, team pages, components, layouts) from `stubs/blade/views/` to `resources/views/vendor/neev/` — app-owned from then on — and sets `'ui' => 'blade'` in `config/neev.php`, which registers the Blade page routes.
- **`none`**: sets `'ui' => env('NEEV_UI')` (headless — no Blade page routes); no page views are copied.
- **Always** (both kits): ejects the email templates to `resources/views/vendor/neev/emails/` so they're the app's to edit. The package keeps fallback copies, so headless installs send mail with zero setup. The variables available in each template are documented in [RFC 002 §5.5](./rfcs/002-starter-kits.md#55-email-templates--app-owned-with-a-variable-contract) and treated as API.

### `neev:download-geoip`

Download the MaxMind GeoLite2 database for IP geolocation.

```bash
php artisan neev:download-geoip
```

Requires `MAXMIND_LICENSE_KEY` in your `.env`.

### `neev:clean-login-attempts`

Remove login attempt records older than the configured retention period.

```bash
php artisan neev:clean-login-attempts
```

Retention is controlled by `config('neev.login_history_retention_days')`. Setting it to `0` (or any value below 1) **disables** the cleanup — the command deletes nothing and reports that it is disabled.

### `neev:clean-pending-mfa-setups`

Delete MFA setups that were started but never verified (still in the `pending` state).

```bash
php artisan neev:clean-pending-mfa-setups
```

Retention is controlled by `config('neev.mfa_pending_setup_retention_days')` (default: 2 days). Setting it to `0` (or any value below 1) **disables** the cleanup rather than deleting everything — a retention of zero would otherwise mean "older than right now" and wipe setups a user was still in the middle of. Schedule it alongside the other maintenance commands:

```php
$schedule->command('neev:clean-pending-mfa-setups')->daily();
```

### `neev:clean-magic-links`

Delete expired magic-link tokens. (Consumed and superseded tokens are already
deleted on use, so only expired rows can linger.)

```bash
php artisan neev:clean-magic-links
```

Expired is expired: this purges every expired token regardless of tenancy
config, since it is a maintenance sweep rather than a request. Expired tokens
cannot be redeemed in any case — the point is to stop the table growing without
bound and to honour retention, as each row holds the requesting IP and user
agent.

Schedule it alongside the other maintenance commands:

```php
$schedule->command('neev:clean-magic-links')->daily();
```

### `neev:clean-access-tokens`

Delete expired access tokens, login and API tokens alike.

```bash
php artisan neev:clean-access-tokens
```

An expired token is refused and deleted when it is presented, so the only rows
that linger are ones their holder never sends again — an abandoned SPA session,
a closed CLI. They cannot authenticate, but without this sweep the table grows
without bound. A token with no `expires_at` never expires and is kept. Like
`neev:clean-magic-links`, it ignores tenancy config and purges every tenant's
rows.

Schedule it alongside the other maintenance commands:

```php
$schedule->command('neev:clean-access-tokens')->daily();
```

---

## Tenant / Team Provisioning

These commands create and inspect tenants (isolated mode) or teams (shared mode).

### `neev:tenant:create`

Create a new tenant or team.

```bash
# Shared mode — creates a team
php artisan neev:tenant:create "Acme Corp" --owner=admin@acme.com --activate

# Isolated mode — creates a tenant; --owner creates its owner inside it, with a default team
php artisan neev:tenant:create "Acme Corp" --owner=admin@acme.com --owner-name="Ada Admin" --domain=app.acme.com
```

| Argument / Option | Description |
|-------------------|-------------|
| `name` | Name of the tenant or team (prompted if omitted) |
| `--slug=` | Custom slug (auto-generated from name if omitted) |
| `--owner=` | Shared mode: an existing user, by ID or email. Isolated mode: the email of an owner to create in the new tenant |
| `--owner-name=` | Isolated mode, with `--owner`: the new owner's name. Optional — defaults to the part of the email before the `@` |
| `--domain=` | Claim a custom host for it (shows the DNS TXT record to publish) |
| `--activate` | Activate the team immediately |

**Shared mode**: Creates a `Team` with the given owner. If `--activate` is passed, sets `activated_at`.

**Isolated mode**: Creates a `Tenant`. If `--owner` is provided, also creates the owner as a user **of the new tenant** (its `tenant_id` set) and a default team they own and have joined, all in one transaction. A user belongs to one tenant and this one is new, so `--owner` takes an email, not an existing user: an existing user could only be a platform user, who cannot sign in to the tenant. A platform user with the same email is a separate account and is left alone. The owner has no password; they sign in with a login link. When `platform_domain` is set, the command prints the sign-in URL, `https://{slug}.{platform_domain}`. If `--domain` is provided, claims it as a host of the tenant.

**`--domain`** claims the host in either mode. It is pending until its TXT record is checked and is not made primary: run `neev:hostname:verify`, then `neev:hostname:primary`, as the command prints.

**Disabled features are refused.** The command will not create rows the installation has no code path to reach:

- Shared mode with `neev.team` off — refuses outright.
- Isolated mode with `neev.team` off and `--owner` given — refuses, because the owner is held by a team.

**Options that would be ignored are refused.** `--owner-name` names the owner the command creates, so it is refused without `--owner`, and in shared mode, where `--owner` picks an existing user who keeps their name. Nothing is created; leave it out to name the owner from their email.

**Every option is checked before the first row is written**, so a bad value leaves nothing behind: an unknown `--owner` (shared mode), an `--owner` that is not an email (isolated mode), an invalid or already-taken `--slug`, or a `--domain` that is under `platform_domain` or already claimed by any owner. All problems are reported together:

```
Nothing was created. Fix the following and run the command again:
  Invalid slug [Bad Slug!]: use lowercase letters, digits and hyphens, starting and ending with a letter or digit.
  Owner not found: nobody@acme.com
```

### `neev:tenant:list`

List all tenants or teams.

```bash
# Default table output
php artisan neev:tenant:list

# Filter and format
php artisan neev:tenant:list --search=acme --limit=10
php artisan neev:tenant:list --inactive --json
```

| Option | Description |
|--------|-------------|
| `--search=` | Filter by name or slug |
| `--inactive` | Show only inactive entries (shared mode) |
| `--json` | Output as JSON |
| `--limit=25` | Maximum number of results |

**Shared mode columns**: ID, Name, Slug, Members, Status, Created.
**Isolated mode columns**: ID, Name, Slug, Teams, Created.

### `neev:tenant:show`

Show details for a tenant or team. Resolves by ID, slug, or custom host.

```bash
php artisan neev:tenant:show acme-corp
php artisan neev:tenant:show 42
php artisan neev:tenant:show app.acme.com
php artisan neev:tenant:show acme-corp --json
```

| Argument / Option | Description |
|-------------------|-------------|
| `identifier` | ID, slug, or custom host (prompted if omitted) |
| `--json` | Output as JSON |

Displays: name, ID, slug, status, owner, member/team count, hostnames (primary marked), email domains (enforced marked), auth config summary. `--json` loads `hostnames` and `emailDomains`.

---

## Hostname Management

Manage the custom hosts a tenant or team is served at. A host is unique across every owner, and is only ever proven by its DNS record. A platform subdomain (`{slug}.{platform_domain}`) is derived from the slug and never stored, so these commands do not list or accept it.

### `neev:hostname:add`

Claim a custom host for a tenant or team.

```bash
php artisan neev:hostname:add app.acme.com --owner-type=tenant --owner-id=42
php artisan neev:hostname:add login.acme.com --owner-type=team --owner-id=acme
```

| Argument / Option | Description |
|-------------------|-------------|
| `host` | The host to add (prompted if omitted) |
| `--owner-type=` | Owner type: `team` or `tenant` (prompted if omitted) |
| `--owner-id=` | Owner ID **or slug** (prompted if omitted) |

Run interactively without the owner options and the command asks for them, the way it already asks for the host:

```
 What owns it?  › A tenant / A team                   # defaults by identity mode
 Which tenant? (ID or slug)  › acme
```

Non-interactive runs (`--no-interaction`, CI) still require `--owner-type` and `--owner-id`.

The command refuses a host the owner already holds, a host another owner has claimed (verified or not), any host under `platform_domain`, and, under tenant isolation, any host for a team — only a tenant's host routes there, so add it to the tenant. On success it prints the TXT record to publish, `_neev-host.{host}`, and its token. The host does not serve until it is verified.

### `neev:hostname:verify`

Verify a custom host via its DNS TXT record.

```bash
# Check DNS and verify
php artisan neev:hostname:verify app.acme.com

# Re-check every verified or failing host (dispatches queued jobs)
php artisan neev:hostname:verify --all
```

| Argument / Option | Description |
|-------------------|-------------|
| `host` | The host to verify (optional when using `--all`) |
| `--all` | Re-check every verified or failing host |

Looks up `_neev-host.{host}` and matches the TXT value against the stored token. A disabled host is refused (`Host is disabled: {host}`) without a lookup. A host has no `--force`: it serves real traffic, so only its record proves it. A match fires `DomainVerified` for a pending host, or `DomainReverified` for one whose record had gone missing.

`--all` queues a `VerifyDomainJob` per verified host and per host already unverified for a missing record. The job skips a pending host; verify that one without `--all`.

### `neev:hostname:primary`

Make a verified host its owner's primary host.

```bash
php artisan neev:hostname:primary app.acme.com
```

| Argument | Description |
|----------|-------------|
| `host` | The verified host to make primary |

Sets `primary_hostname_id` on the owning team or tenant. An unverified host is refused. With no primary set, the owner's canonical host is its oldest verified host, else its platform subdomain.

### `neev:hostname:list`

List custom hosts with optional filters.

```bash
php artisan neev:hostname:list
php artisan neev:hostname:list --owner-type=tenant --unverified
php artisan neev:hostname:list --owner-type=team --owner-id=acme --json
```

| Option | Description |
|--------|-------------|
| `--owner-type=` | Filter by owner type (`team` or `tenant`) |
| `--owner-id=` | Filter by owner ID, or by slug (a slug needs `--owner-type`) |
| `--unverified` | Show only unverified hosts |
| `--json` | Output as JSON |

Columns: ID, Host, Owner Type, Owner ID, Primary, Status.

---

## Email Domain Management

Manage the email domains whose users join a tenant or team. An email domain is not exclusive: several owners may each verify `acme.com` with their own record. Enforcing it is exclusive to one owner.

### `neev:email-domain:add`

Add an email domain to a tenant or team.

```bash
# Add a domain (requires DNS verification)
php artisan neev:email-domain:add acme.com --owner-type=team --owner-id=acme --enforce

# Skip verification (local dev)
php artisan neev:email-domain:add acme.test --owner-type=team --owner-id=1 --skip-verification
```

| Argument / Option | Description |
|-------------------|-------------|
| `domain` | The email domain to add (prompted if omitted) |
| `--owner-type=` | Owner type: `team` or `tenant` (prompted if omitted) |
| `--owner-id=` | Owner ID **or slug** (prompted if omitted) |
| `--enforce` | Only invite users at this domain |
| `--skip-verification` | Mark as verified without DNS |

The owner prompts work as in `neev:hostname:add`. The command refuses a domain the same owner already holds, and `--enforce` on a domain another owner's verified row already enforces.

Unless `--skip-verification` is passed, the command prints the TXT record to publish, `_neev-email.{domain}`, and its token.

### `neev:email-domain:verify`

Verify an email domain via its DNS TXT record.

```bash
# Check DNS and verify
php artisan neev:email-domain:verify acme.com

# Force-verify without DNS check (local dev)
php artisan neev:email-domain:verify acme.com --force

# Pick one claim when several owners have claimed the domain
php artisan neev:email-domain:verify acme.com --owner-type=team --owner-id=acme

# Re-check every verified or failing email domain (dispatches queued jobs)
php artisan neev:email-domain:verify --all
```

| Argument / Option | Description |
|-------------------|-------------|
| `domain` | The domain to verify (optional when using `--all`) |
| `--owner-type=` | `team` or `tenant`; narrows to that owner's claim |
| `--owner-id=` | Owner ID, or slug with `--owner-type` |
| `--force` | Mark verified without DNS check |
| `--all` | Re-check every verified or failing email domain |

Looks up `_neev-email.{domain}` and matches the TXT value against the stored token.

When more than one owner has claimed the domain, the command lists the claims and exits without verifying any; pass `--owner-type` and `--owner-id` to choose one.

`--force` here and `--skip-verification` on `neev:email-domain:add` exist only for email domains. They record the row with `verification_strategy = manual`, clear any earlier failure, and fire `DomainVerified` (or `DomainReverified` for a row that had failed). The daily re-check skips a `manual` row, since it has no record to check. Issuing a new token makes it `dns` again. A disabled domain is refused, with or without `--force` (`Domain is disabled: {domain}`): neither DNS nor an operator revives it.

`--all` queues a `VerifyDomainJob` per verified email domain and per one already unverified for a missing record. The job skips a pending claim and a `manual` row.

### `neev:email-domain:list`

List email domains with optional filters.

```bash
php artisan neev:email-domain:list
php artisan neev:email-domain:list --owner-type=tenant --unverified
php artisan neev:email-domain:list --owner-type=team --owner-id=acme --json
```

| Option | Description |
|--------|-------------|
| `--owner-type=` | Filter by owner type (`team` or `tenant`) |
| `--owner-id=` | Filter by owner ID, or by slug (a slug needs `--owner-type`) |
| `--unverified` | Show only unverified domains |
| `--json` | Output as JSON |

Columns: ID, Domain, Owner Type, Owner ID, Enforce, Status.

---

## Member Management

Add, remove, and list team members. These commands operate on the team membership pivot table directly, bypassing the invitation flow.

### `neev:member:add`

Add a user to a team directly.

```bash
# Add by team
php artisan neev:member:add user@example.com --team=acme-corp --role=editor
```

| Argument / Option | Description |
|-------------------|-------------|
| `email` | Email of the user to add (prompted if omitted) |
| `--team=` | Team ID or slug (required) |
| `--role=` | Role to assign |

Looks up the user by email and attaches them with `joined=true`.

Under tenant isolation the user and the team must belong to the same tenant; a cross-tenant add is refused.

The membership and the role are written together: if `--role` names a role that does not exist, the attach is rolled back and the command fails, rather than leaving the user in the team without the role.

### `neev:member:remove`

Remove a user from a team.

```bash
php artisan neev:member:remove user@example.com --team=acme-corp
php artisan neev:member:remove user@example.com --team=acme-corp --force
```

| Argument / Option | Description |
|-------------------|-------------|
| `email` | Email of the user to remove (prompted if omitted) |
| `--team=` | Team ID or slug (required) |
| `--force` | Skip confirmation prompt |

Refuses to remove the team owner. The team-scoped role is deleted along with the membership, so it cannot come back if the user is added again later.

### `neev:member:list`

List members of a team.

```bash
php artisan neev:member:list --team=acme-corp
php artisan neev:member:list --team=acme-corp --json
```

| Option | Description |
|--------|-------------|
| `--team=` | Team ID or slug (required) |
| `--json` | Output as JSON |

Columns: ID, Name, Email, Role, Joined, Since.

---

## Auth / SSO Configuration

Configure per-tenant or per-team authentication methods.

### `neev:auth:configure`

Configure the authentication method for a tenant or team. Interactive when options are omitted.

```bash
# Set password auth
php artisan neev:auth:configure --team=acme-corp --method=password

# Configure SSO interactively
php artisan neev:auth:configure --tenant=acme-corp --method=sso

# Full non-interactive SSO setup
php artisan neev:auth:configure --tenant=acme-corp \
  --method=sso \
  --sso-provider=entra \
  --sso-client-id=your-client-id \
  --sso-client-secret=your-secret \
  --sso-tenant-id=your-azure-tenant-id \
  --auto-provision \
  --auto-provision-role=member
```

| Option | Description |
|--------|-------------|
| `--tenant=` | Tenant ID or slug |
| `--team=` | Team ID or slug |
| `--method=` | Auth method: `password` or `sso` |
| `--sso-provider=` | SSO provider: `entra`, `google`, or `okta` |
| `--sso-client-id=` | SSO client ID |
| `--sso-client-secret=` | SSO client secret |
| `--sso-tenant-id=` | SSO tenant/directory ID (required for Entra) |
| `--auto-provision` | Enable auto-provisioning of SSO users |
| `--auto-provision-role=` | Role for auto-provisioned users |

Creates or updates `TenantAuthSettings` (isolated) or `TeamAuthSettings` (shared). Supported SSO providers: `entra`, `google`, `okta`.

### `neev:auth:show`

Display the authentication configuration.

```bash
php artisan neev:auth:show --tenant=acme-corp
php artisan neev:auth:show --team=1 --reveal
php artisan neev:auth:show --team=1 --json
```

| Option | Description |
|--------|-------------|
| `--tenant=` | Tenant ID or slug |
| `--team=` | Team ID or slug |
| `--reveal` | Show client ID (client secret is **never** shown) |
| `--json` | Output as JSON |

---

## Team Lifecycle

### `neev:team:activate`

Activate or deactivate a team.

```bash
# Activate
php artisan neev:team:activate acme-corp

# Deactivate with reason
php artisan neev:team:activate acme-corp --deactivate --reason="Non-payment"
```

| Argument / Option | Description |
|-------------------|-------------|
| `team` | Team ID or slug (prompted if omitted) |
| `--deactivate` | Deactivate instead of activate |
| `--reason=` | Reason for deactivation |

Calls the existing `$team->activate()` / `$team->deactivate($reason)` methods.

---

## Scripting & Automation

All list and show commands support `--json` for machine-readable output:

```bash
# Pipe to jq
php artisan neev:tenant:list --json | jq '.[].slug'

# Use in shell scripts
TENANT_ID=$(php artisan neev:tenant:show acme-corp --json | jq -r '.id')
```

All commands that accept required arguments implement `PromptsForMissingInput` — they work interactively when arguments are omitted and non-interactively when all arguments are supplied.
