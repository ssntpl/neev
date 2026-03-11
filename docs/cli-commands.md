# CLI Commands

Complete reference for all Neev Artisan commands. Commands adapt to your [identity strategy](./architecture.md) — in **shared** mode they operate on teams, in **isolated** mode they operate on tenants.

> Run `php artisan list neev` to see all available commands.

---

## Setup & Maintenance

These commands handle initial setup and scheduled maintenance.

### `neev:install`

Interactive setup wizard for new installations.

```bash
php artisan neev:install
```

Prompts for: team support, email verification, domain federation, tenant isolation. Publishes config and migrations.

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

Retention is controlled by `config('neev.last_login_attempts_in_days')`.

### `neev:clean-passwords`

Remove expired password history records.

```bash
php artisan neev:clean-passwords
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
| `--domain=` | Attach a domain (auto-verifies subdomains, shows DNS instructions for custom) |
| `--activate` | Activate the team immediately |

**Shared mode**: Creates a `Team` with the given owner. If `--activate` is passed, sets `activated_at`.

**Isolated mode**: Creates a `Tenant`. If `--owner` is provided, also creates a default team with the owner attached. If `--domain` is provided, attaches it to the tenant.

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
# Add subdomain (auto-verified if it matches your subdomain suffix)
php artisan neev:domain:add acme.yourapp.com --team=acme-corp --primary

# Add custom domain (requires DNS verification)
php artisan neev:domain:add app.acme.com --tenant=acme-corp

# Skip verification (local dev)
php artisan neev:domain:add custom.local --team=1 --skip-verification
```

| Argument / Option | Description |
|-------------------|-------------|
| `domain` | The domain to add (prompted if omitted) |
| `--tenant=` | Tenant ID or slug |
| `--team=` | Team ID or slug |
| `--primary` | Set as primary domain |
| `--enforce` | Enforce domain-based federation |
| `--skip-verification` | Mark as verified immediately |

Subdomains matching `config('neev.tenant_isolation_options.subdomain_suffix')` are automatically verified. Custom domains display DNS TXT record instructions.

### `neev:domain:verify`

Verify a domain via DNS TXT record lookup.

```bash
# Check DNS and verify
php artisan neev:domain:verify app.acme.com

# Force-verify without DNS check (local dev)
php artisan neev:domain:verify app.acme.com --force
```

| Argument / Option | Description |
|-------------------|-------------|
| `domain` | The domain to verify (prompted if omitted) |
| `--force` | Mark verified without DNS check |

Performs a `dns_get_record()` lookup on `_neev-verification.{domain}` and matches the TXT value against the stored verification token.

### `neev:domain:list`

List domains with optional filters.

```bash
php artisan neev:domain:list
php artisan neev:domain:list --tenant=acme-corp --unverified
php artisan neev:domain:list --team=1 --json
```

| Option | Description |
|--------|-------------|
| `--tenant=` | Filter by tenant ID or slug |
| `--team=` | Filter by team ID or slug |
| `--unverified` | Show only unverified domains |
| `--json` | Output as JSON |

Columns: ID, Domain, Team ID, Tenant ID, Primary, Enforce, Status.

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

Looks up the user via the `emails` table and attaches them with `joined=true`.

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

Refuses to remove the team owner.

### `neev:member:list`

List members of a team or tenant.

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

Creates or updates `TenantAuthSettings` (isolated) or `TeamAuthSettings` (shared). Validates the provider against `config('neev.tenant_auth_options.sso_providers')`.

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
