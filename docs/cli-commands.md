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

Retention is controlled by `config('neev.login_history_retention_days')`.

### `neev:clean-pending-mfa-setups`

Delete MFA setups that were started but never verified (still in the `pending` state).

```bash
php artisan neev:clean-pending-mfa-setups
```

Retention is controlled by `config('neev.mfa_pending_setup_retention_days')` (default: 2 days). Setting it to `0` (or any value below 1) **disables** the cleanup rather than deleting everything — a retention of zero would otherwise mean "older than right now" and wipe setups a user was still in the middle of. Schedule it alongside the other maintenance commands:

```php
$schedule->command('neev:clean-pending-mfa-setups')->daily();
```

---

## Tenant / Team Provisioning

These commands create and inspect tenants (isolated mode) or teams (shared mode).

### `neev:tenant:create`

Create a new tenant or team.

```bash
# Shared mode — creates a team
php artisan neev:tenant:create "Acme Corp" --owner=admin@acme.com --activate

# Isolated mode — creates a tenant (and a default team if --owner is provided)
php artisan neev:tenant:create "Acme Corp" --owner=admin@acme.com --domain=acme.yourapp.com
```

| Argument / Option | Description |
|-------------------|-------------|
| `name` | Name of the tenant or team (prompted if omitted) |
| `--slug=` | Custom slug (auto-generated from name if omitted) |
| `--owner=` | Owner by user ID or email address |
| `--domain=` | Attach a domain as primary (shows DNS TXT verification instructions) |
| `--activate` | Activate the team immediately |

**Shared mode**: Creates a `Team` with the given owner. If `--activate` is passed, sets `activated_at`.

**Isolated mode**: Creates a `Tenant`. If `--owner` is provided, also creates a default team with the owner attached. If `--domain` is provided, attaches it to the tenant.

**Disabled features are refused.** The command will not create rows the installation has no code path to reach:

- Shared mode with `neev.team` off — refuses outright.
- Isolated mode with `neev.team` off and `--owner` given — refuses, because the owner is held by a team.

**Every option is checked before the first row is written**, so a bad value leaves nothing behind: an unknown `--owner`, an invalid or already-taken `--slug`, or a `--domain` another owner of the same kind has already verified. All problems are reported together:

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

Show details for a tenant or team. Resolves by ID, slug, or domain.

```bash
php artisan neev:tenant:show acme-corp
php artisan neev:tenant:show 42
php artisan neev:tenant:show app.acme.com
php artisan neev:tenant:show acme-corp --json
```

| Argument / Option | Description |
|-------------------|-------------|
| `identifier` | ID, slug, or domain (prompted if omitted) |
| `--json` | Output as JSON |

Displays: name, ID, slug, status, owner, member/team count, domains, auth config summary.

---

## Domain Management

Manage domains attached to tenants or teams.

### `neev:domain:add`

Add a domain to a tenant or team.

```bash
# Add a domain to a team
php artisan neev:domain:add acme.yourapp.com --owner-type=team --owner-id=1 --primary

# Add a domain to a tenant (requires DNS verification)
php artisan neev:domain:add app.acme.com --owner-type=tenant --owner-id=42

# Skip verification (local dev)
php artisan neev:domain:add custom.local --owner-type=team --owner-id=1 --skip-verification
```

| Argument / Option | Description |
|-------------------|-------------|
| `domain` | The domain to add (prompted if omitted) |
| `--owner-type=` | Owner type: `team` or `tenant` (prompted if omitted) |
| `--owner-id=` | Owner ID **or slug** (prompted if omitted) |
| `--primary` | Set as primary domain |
| `--enforce` | Enforce domain-based federation |
| `--skip-verification` | Mark as verified immediately |

Run interactively without the owner options and the command asks for them, the way it already asks for the domain:

```
 What owns this domain?  › A tenant / A team          # defaults by identity mode
 Which tenant owns it? (ID or slug)  › acme
```

Non-interactive runs (`--no-interaction`, CI) still require `--owner-type` and `--owner-id`.

**Uniqueness is per owner type.** A tenant and a team may both federate the same company domain, but two teams — or two tenants — may not. A domain is only reserved once its owner has *verified* it; an unverified claim blocks nobody. The command also refuses a domain the same owner already holds.

Unless `--skip-verification` is passed, the command displays the DNS TXT record (name and token) required to verify the domain.

### `neev:domain:verify`

Verify a domain via DNS TXT record lookup.

```bash
# Check DNS and verify
php artisan neev:domain:verify app.acme.com

# Force-verify without DNS check (local dev)
php artisan neev:domain:verify app.acme.com --force

# Re-verify all previously verified domains (dispatches queued jobs)
php artisan neev:domain:verify --all
```

| Argument / Option | Description |
|-------------------|-------------|
| `domain` | The domain to verify (optional when using `--all`) |
| `--force` | Mark verified without DNS check |
| `--all` | Re-verify all previously verified domains |

Performs a `dns_get_record()` lookup on `_neev-verification.{domain}` and matches the TXT value against the stored verification token.

### `neev:domain:list`

List domains with optional filters.

```bash
php artisan neev:domain:list
php artisan neev:domain:list --owner-type=tenant --unverified
php artisan neev:domain:list --owner-type=team --owner-id=1 --json
```

| Option | Description |
|--------|-------------|
| `--owner-type=` | Filter by owner type (`team` or `tenant`) |
| `--owner-id=` | Filter by owner ID |
| `--unverified` | Show only unverified domains |
| `--json` | Output as JSON |

Columns: ID, Domain, Owner Type, Owner ID, Primary, Enforce, Status.

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
