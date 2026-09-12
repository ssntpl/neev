# RFC 006 — Hostnames and Email Domains Are Two Different Things

> **Status:** Proposed — research complete; §6 needs maintainer decisions before implementation
> **Drivers:** a reported vulnerability (any team owner could mark any domain verified), a DNS re-verification loop that can never pass, and a slug↔host synchronisation problem that appears the moment slugs become mutable
> **Supersedes:** the `type: subdomain` auto-verification behaviour hardened in #58, which this RFC removes entirely

## 1. Problem

The `domains` table holds two things that look alike and behave nothing alike.

**A serving hostname** — where a tenant is reached. Either a platform
subdomain under a zone we own (`acme.otper.com`) or a custom domain the
customer owns (`app.acme.com`, proven by DNS TXT).

**A verified email domain** — `acme.com` recorded so that anyone
registering with an `@acme.com` address is federated into that team. This
row is *not* a hostname. The app is never served at it, and nobody has an
`@acme.otper.com` address.

### 1.1 Triggering discovery

A developer reported that `POST {prefix}/tenant-domains` accepted
`ssntpl.in` with `type=subdomain` and marked it verified, despite
`ssntpl.in` having nothing to do with the platform. `type` was a
client-supplied field and the only thing standing between a caller and
`verified_at = now()`.

PR #58 closed that by deriving verification from the host and the
claiming team's slug. But the fix exposed three further symptoms that
share one cause:

1. **A `verification_token` that means nothing.** Platform subdomains are
   auto-verified, so they never get a token. They still sit in
   `VerifyAllDomainsJob`'s `whereNotNull('verified_at')` sweep, where
   `Domain::verify()` compares DNS TXT against `null` — so the nightly
   run marks every one of them failed and fires
   `DomainVerificationFailed` forever. (Not an outage: the failure branch
   never clears `verified_at`, and nothing in the package reads
   `verification_failed_at`. It is an alerting problem.)
2. **An `enforce` flag that cannot apply.** `enforce` restricts which
   email addresses may be invited. It is meaningless on a host.
3. **A row that duplicates the slug.** `acme.otper.com` is
   `slug` + zone, stored a second time, and must be kept in step with
   the slug by hand on every rename.

Each symptom is a transport concern and a policy concern sharing a row.
The table's own vocabulary gives it away: the endpoint is
`domainFederate`, the docs call the feature *"domain-based
auto-joining"*, and `isVerifiedForEmail()` matches the **email** domain.
The table is a federation registry that also had hostnames put in it.

## 2. What established systems do

This is not a novel problem, and the design was settled by going to look
rather than by reasoning from first principles. Eighteen systems were
examined across three ecosystems: Laravel multi-tenancy packages,
enterprise identity platforms, and SaaS products that hand out
subdomains.

### 2.1 The constraint that settles it

**Hostname uniqueness and email-domain uniqueness are opposite
requirements.**

A serving hostname *must* be globally unique or resolution is
nondeterministic. `stancl/tenancy` enforces this twice: `->unique()` on
the column *and* an `EnsuresDomainIsNotOccupied` saving hook throwing
`DomainOccupiedByOtherTenantException`.

A verified email domain must *not* be unique. WorkOS **changed** this
deliberately in September 2024: *"Multiple organizations can now add the
same verified domain and configure which verified domains are included in
a domain policy."* Two subsidiaries legitimately both verify `acme.com`.

Two opposite constraints cannot live on one column. Everything else in
this RFC follows from that.

### 2.2 Nobody unifies them

| System | Email / identity domain | Serving hostname |
|---|---|---|
| **WorkOS** | `organization_domain`: `domain`, `organization_id`, `state`, `verification_token`, `verification_strategy`. No certificate or hostname field | AuthKit custom domain — *environment*-scoped, CNAME-verified, **not an API object at all** |
| **Microsoft Entra** | the `domains` collection — *"part of a user name or email address"*; `isVerified`, `authenticationType`, `supportedServices` | a **separate** custom-URL-domain object that *selects* an already-verified row, plus its own `_dnsauth` TXT |
| **Okta** | not an entity — an unverified pattern string in a policy rule | **two** hostname resources (`DomainResponse` with TLS cert; `EmailDomainResponse`) |
| **Auth0** | `connection.options.domain_aliases` (string array) | `/api/v2/custom-domains`: `tls_policy`, `certificate`, `origin_domain_name` |
| **Google Workspace** | `domains` + `domainAliases`, email-only, **no update method** | configured elsewhere; ownership proof is a separate product |

Five platforms, no counterexample. Okta is the sharpest signal: it
declined to unify even two *hostname* tables with each other.

The column sets do not overlap. Hostnames want transport state (TLS,
CNAME, redirect, origin); email domains want membership policy (enforce,
auto-join, JIT provisioning).

### 2.3 The vendor-zone hostname: store or derive?

A real split, and the predictor is what the name is *for*:

| Stored as a row | Derived from a name/slug |
|---|---|
| Entra `contoso.onmicrosoft.com` (`isInitial: true`, undeletable) — a UPN-suffix **identity namespace** | Entra `<tenant>.ciamlogin.com` — the actual **serving host** — is not in the collection |
| Heroku `Domain.kind` (`heroku` \| `custom`) | Laravel Cloud `vanity_domain`, *"constructed from your application and environment name"* |
| | Netlify `site.default_domain` from `site.name`; Vercel `<project>-<scope>.vercel.app`; Shopify `Shop.myshopifyDomain` is a Shop attribute while `Domain` holds only storefront hosts; Zendesk `Brand.subdomain` is an attribute with no domains table |

Entra sits in both columns and shows why: it **stores** the vendor-zone
identity namespace and **derives** the vendor-zone serving hostname.
Neev's `acme.otper.com` is a serving hostname.

### 2.4 Type discriminators

Effectively no precedent for a `hostname|email` discriminator. Entra uses
a capability *set* (`supportedServices`); Laravel Cloud's `DomainType` is
hostname *shape* (`root|www|wildcard`); Shopify's `Domain` has no type
field at all. The only true `kind` column found is Heroku's, and it
distinguishes two *hostnames*.

One warning: `stancl/tenancy` discriminates implicitly — *"records that
contain dots will be treated as domains/hostnames, records that don't
contain any dots will be treated as subdomains."* **Do not copy this.**
That dot heuristic is precisely what breaks when a dotted email row
(`acme.com`) shares a table with serving hostnames. It is a description
of Neev's current bug.

### 2.5 Renames: no consensus exists

Five mature vendors, five incompatible documented policies:

| Policy | System |
|---|---|
| Impossible — the FQDN is the immutable key | Entra |
| No write API at all | Google Workspace |
| Once only, then start over | Shopify (`myshopifyDomain`), Freshdesk |
| Keep forever, cap the count, redirect | Atlassian (up to 15, old name never released) |
| Release the old name | Slack, Heroku, Vercel, Zendesk |

Two things *are* consistent everywhere, and both are mechanisms rather
than policies:

1. **Nobody mutates the string.** The universal shape is add-new → flip a
   pointer → retire-old. Flipping the pointer never rewrites data (Entra:
   *"Changing the primary domain for your organization doesn't change the
   user name for any existing users"*).
2. **Retirement is a state, not a `DELETE`.** Cloudflare's `status`
   includes `moved` and `deleted`; Okta has a terminal `DELETED`; Laravel
   Cloud has `disabled` distinct from `failed`; hyn uses `softDeletes()`.

### 2.6 The finding that matters most for an auth package

**Heroku changed their hostname scheme specifically to stop subdomain
reuse.** Since 14 June 2023 the host is
`APPNAME-<12-char-random>.herokuapp.com`, because *"the addition of the
identifier helps to mitigate the reuse of subdomains"* — citing traffic
interception, phishing, cookie theft, and **bypassing OAuth
allowlisting**.

That is a vendor who shipped the naive version, got burned, and rebuilt
it. For an auth package the lesson is direct: **a slug-derived host over
a reusable slug is a takeover vector.** A reclaimed host may still appear
in SSO redirect URIs and SAML ACS URLs, OAuth callback allowlists,
emailed signed links, and users' password managers — so recycling it
redirects credentials, not merely links.

Zendesk's rename documentation enumerates the blast radius for an auth
product specifically: *"You cannot sign in via SSO until you update the
subdomain"*, API failures, invalid reply-to addresses, a new CNAME and
certificate required.

## 3. Defects in the current schema that this resolves

All five verified against the code on `main` at the time of writing.

**(a) Verifying a serving host silently grants email federation.**
`Domain::isVerifiedForEmail()` matches *any* verified row by string, for
*any* owner, and does not consult `enforce`. A team that verifies
`acme.com` as a custom serving host thereby makes every `@acme.com`
registration "federated" installation-wide, suppressing personal-team
creation at `RegistrationService.php:66` and `:99`. This is the
conflation causing harm today.

**(b) No global uniqueness on the host.** `domains.domain` carries
`->index()` only. Uniqueness exists solely as `Rule::unique` in
`TenantDomainController.php` and `AddDomainCommand.php`, scoped per
`owner_type` and only against already-verified rows — so a team and a
tenant can both hold `app.acme.com`, and `domainForMode()` resolves by
owner-type precedence rather than by a real invariant.

**(c) Stale platform rows block slug reuse.** The row duplicates
`slug` + zone. Rename a team and the old `oldslug.otper.com` row stays
verified and keeps routing, while a new team taking the freed slug is
blocked by the verified-row uniqueness rule.

**(d) `is_primary` does double duty.** It marks the canonical serving
host, *and* — through `Team::domain()`, a deprecated alias for
`primaryDomain()` — it selects the row whose `enforce` flag gates
invitations (`TeamController.php:184`, `TeamApiController.php:261`). One
flag answering both "which host is canonical" and "which domain governs
membership" cannot survive the split.

**(e) `domain_rules` is never acted upon.** `TeamController.php:529` and
`TeamApiController.php:698` insert exactly one hardcoded
`['name' => 'mfa', 'value' => false]` row on verification. The rows are
writable and readable through `updateDomainRule` / `getDomainRule`, and
`Domain::rule($name)` exists — but **nothing in the package ever calls
`rule()`**, so no rule changes any behaviour. Its one intended policy
belongs in `team_auth_settings` / `tenant_auth_settings`, which already
exist.

> Two claims from the research memo were checked and **corrected** here:
> `is_primary`'s second meaning arrives via the `domain()` alias rather
> than `primaryDomain()` directly (d); and `domain_rules` *is* readable
> through an API endpoint — it is unconsumed, not unread (e).

## 4. Proposed design

### 4.1 Two tables, named for what they hold

The word `domains` **is** the ambiguity. `hyn/multi-tenant` called the
entity `hostnames` with an `fqdn` column and avoided this by
construction.

```php
// hostnames — "this owner is served at this host" (transport)
Schema::create('hostnames', function (Blueprint $table) {
    $table->id();
    $table->morphs('owner');                        // team | tenant
    $table->string('host')->unique();               // deterministic resolution
    $table->string('status')->default('pending');   // pending|verified|failed|disabled|moved
    $table->string('verification_token')->nullable();
    $table->timestamp('verified_at')->nullable();
    $table->timestamp('verification_failed_at')->nullable();
    $table->timestamp('verification_expires_at')->nullable();
    $table->string('redirect_to')->nullable();
    $table->unsignedSmallInteger('redirect_status')->nullable();
    $table->timestamps();
});

// email_domains — "users at this domain belong to this owner" (policy)
Schema::create('email_domains', function (Blueprint $table) {
    $table->id();
    $table->morphs('owner');
    $table->string('domain');                       // deliberately NOT unique
    $table->string('status')->default('pending');
    $table->string('verification_strategy')->default('dns');  // dns|manual
    $table->string('verification_token')->nullable();
    $table->timestamp('verified_at')->nullable();
    $table->timestamp('verification_failed_at')->nullable();
    $table->boolean('enforce')->default(false);
    $table->timestamps();
    $table->unique(['owner_type', 'owner_id', 'domain']);
});
```

Plus `primary_hostname_id` (nullable FK) on `teams` and `tenants`.

**Why two tables rather than one with a discriminator:** the uniqueness
rules are opposite (§2.1). Two tables give portable `unique('host')` and
no unique on `email_domains.domain` on MySQL, Postgres and SQLite alike.
Expressing both in one table needs a partial unique index, which MySQL
does not have.

**Two proofs is what the industry actually pays.** WorkOS proves email
domains with TXT and hostnames with CNAME; Entra needs the domain TXT
*and* an independent `_dnsauth` TXT. Use distinct record names
(`_neev-email.<domain>` vs `_neev-host.<host>`) so the two proofs cannot
be mistaken for each other.

### 4.2 Column disposition

| Today | Proposal | Because |
|---|---|---|
| `domain` (indexed, not unique) | → `hostnames.host` **unique**; `email_domains.domain` **not** unique | §2.1; fixes defect (b) |
| `verified_at` | keep, add `status` + `verification_expires_at`; make it read-only at the API surface | a nullable timestamp cannot express never-verified / awaiting-DNS / verified / was-verified-but-TXT-removed / disabled — states the existing `DomainReverified` and `DomainVerificationFailed` events already imply. GitLab's `enabled_until`; Cloudflare's `status`. Entra's `isVerified` and Google's `verified` are read-only |
| `verification_token` | keep on both; add `verification_strategy` now | Entra uses `verificationDnsRecords`; Vercel's `verification` is a **list of alternative challenges**. One column cannot express alternatives — adopting the strategy enum reserves the seam at no cost |
| **`is_primary`** | **delete**; replace with `owner.primary_hostname_id` | `stancl/tenancy` shipped this exact column and removed it in commit [`7ad93add`](https://github.com/archtechx/tenancy/commit/7ad93add) — *"Remove is_primary from domain migrations"* — before v3, never restored. A pointer from the parent makes "exactly one primary" a schema invariant instead of `markAsPrimary()`'s two-statement update, and yields the natural guard *"primary domains cannot be removed until unset as primary"*. Laravel Cloud exposes `Environment.primaryDomain`; Shopify has `Shop.primaryDomain`. Also required by defect (d) |
| `enforce` | keep, on `email_domains` only | it is a membership policy. Note WorkOS moved **exclusivity** off the verification record onto the policy layer: verification non-exclusive, enforcement exclusive |
| `verification_failed_at` | keep on both; fold into `status` | already ahead of every Laravel package — stancl, spatie and hyn have zero verification columns |
| **`domain_rules`** | **delete** | no precedent in any system examined, and unconsumed in Neev — defect (e) |
| `findPrimaryByHost()` | delete | dead code; never called outside the model |

### 4.3 The platform subdomain is derived, not stored

Stop writing platform-subdomain rows. `TenantResolver::resolveFromHost()`
gains one step before the hostname lookup: if the host ends in
`'.' . $zone` for the configured platform zone, take the label and
`resolveBySlug($label)`. That is `Domain::isPlatformSubdomainFor()`'s
logic without the row.

Consequences, all of them subtractive:

- No claim path for a platform host. `POST {prefix}/tenant-domains`
  becomes federation-and-custom-host only, and the auto-verify branch —
  the original vulnerability — is deleted rather than guarded.
- Nothing to keep in step on rename. The host follows the slug by
  definition.
- Platform hosts are never in the DNS sweep, so symptom 1 of §1.1
  disappears instead of needing a special case in `verify()`.
- `platform_domain` stops being a security boundary. It becomes routing
  input, and narrows from string-or-array to a single string.

**This is safe only if slugs are never recycled** (§2.6). That is the
non-negotiable half of the trade.

### 4.4 Mechanism, not policy

Per [design-principles.md](../design-principles.md), the package ships the mechanism and the
application decides where it applies. Decided already:

- **Slugs are mutable**, and whether to allow, rate-limit or freeze
  changes is the implementor's call.
- **Slugs are never recycled.** The package enforces this; it is a
  security invariant, not a policy knob.
- **Downgrading a previously verified host is the implementor's
  decision.** The package must therefore *provide* the primitive — today
  there is none, and a listener's only option is raw attribute
  manipulation, which [design-principles.md](../design-principles.md) explicitly warns against.
- **No `verification_token` for platform hosts.** Requiring a DNS record
  per tenant subdomain is an infrastructure burden that puts propagation
  delay into onboarding, for no security gain on a zone we control.
- **Whether a tenant has a platform host at all** is a column on the
  owner, not app config.

What the package provides:

| Primitive | Purpose |
|---|---|
| `SlugChanged` event | lets the app react — prompt the tenant to reconfigure their IdP |
| `$owner->assignHost()` / `releaseHost()` | code-layer methods; no route can reach them |
| `markUnverified()` / `revoke()` | named downgrade, replacing raw attribute writes |
| retired-host redirect middleware | opt-in; 301 a retired host to the current one |
| `$owner->canonicalHost()` | the one place that answers "where is this tenant served" |

**Signed links break across a rename, and that is accepted.** Laravel's
`hasCorrectSignature()` builds its HMAC payload from
`$request->getSchemeAndHttpHost()`, so the host is inside the signature —
a redirect cannot rescue an in-flight magic link, it only converts "wrong
host" into "invalid signature". The blackout is bounded by
`url_expiry_time` (60 minutes by default) and must be documented as a
consequence of renaming. External IdP redirect URIs and API base URLs are
the tenant's to update; `SlugChanged` is how the app knows to ask.

## 5. Migration and sequencing

1. **#58** — narrow `platform_domains` to a single `platform_domain`
   string and merge. It is a stopgap protecting the interim, and its
   central mechanism is superseded by §4.3.
2. **Create `hostnames` and `email_domains`.** Backfill by classifying
   each existing `domains` row: a host under the platform zone is
   dropped (§4.3 derives it); anything else is copied to `email_domains`,
   and additionally to `hostnames` if it currently resolves. Rows that
   are both keep an entry in each — that is the point of the split.
3. **Slug retirement** — the store that makes never-recycle a database
   guarantee rather than application etiquette.
4. **Resolver, helpers, events** — §4.3 and §4.4.
5. **Delete** `domain_rules`, `is_primary`, `findPrimaryByHost()`.

Neev is pre-1.0, so a breaking rename is cheap now and expensive later.

## 6. Open questions for maintainer review

**Q1. Rename policy.** §2.5 shows no industry consensus, so this is
decided on risk appetite. Options: reserve retired hosts forever
(Atlassian shape — recommended for an auth package), or break pure
derivation with a random suffix (Heroku shape — reuse becomes harmless
but hosts become ugly). **Do not ship freely-mutable-and-reusable slugs
with a derived host**; that is the exact bug Heroku spent a migration
fixing.

**Q2. Does the retired-host serving window ever close?** Never-recycling
makes "forever" safe, but forever means DNS and TLS coverage forever. A
long finite window is easier to operate.

**Q3. May one owner have more than one hostname?** The schema above
allows it (`hostnames` is a collection with a `primary_hostname_id`
pointer). Confirm that is wanted — a tenant served at both their custom
domain and the platform subdomain — because a single `host` column on the
owner would be simpler if not.

**Q4. Re-verification cadence.** Only two concrete numbers exist
anywhere: GitLab removes unverified custom domains after 7 days and
reverifies periodically via `enabled_until`; Okta re-polls a custom email
sender domain every 24h. WorkOS's verification guide declines to cover
re-verification at all. A number has to be picked, not looked up.

**Q5. Team slug scoping.** Independent of the rest. In isolated mode a
team slug is a path identifier inside a tenant, not a host, so it should
be `unique(['tenant_id','slug'])`; in non-isolated mode the team *is*
host-resolvable and needs installation-wide uniqueness. Today
`teams.slug` carries a column-level `->unique()`, so `engineering` can
exist only once across every tenant — which is both a poor experience and
a quiet cross-tenant information leak, since the `-1` suffix reveals that
another tenant holds the name.

## 7. Evidence provenance

**Independently verified against primary sources:**
[stancl/tenancy `7ad93add`](https://github.com/archtechx/tenancy/commit/7ad93add) ·
[stancl/tenancy domains migration](https://github.com/archtechx/tenancy/blob/master/assets/migrations/2019_09_15_000020_create_domains_table.php) ·
[Entra `domain` resource](https://learn.microsoft.com/en-us/graph/api/resources/domain?view=graph-rest-1.0) ·
[WorkOS `organization_domain`](https://workos.com/docs/reference/organization-domain) ·
[WorkOS AuthKit domain policies changelog](https://workos.com/changelog/new-customizations-for-authkit-domain-policies) ·
[Vercel add-a-domain-to-a-project](https://vercel.com/docs/rest-api/reference/endpoints/projects/add-a-domain-to-a-project) ·
[GitLab pages_domains API](https://docs.gitlab.com/api/pages_domains/) ·
[Heroku app names and subdomains](https://devcenter.heroku.com/articles/app-names-and-subdomains)

**Cited with a source but not independently re-checked** — reliable but
second-hand: Laravel Cloud `vanity_domain` and `DomainResource`; Shopify
`Domain` object and the once-only `myshopifyDomain`; Atlassian's
15-rename limit; Slack's workspace-URL release; Zendesk's rename blast
radius; Freshdesk's once-only change; Okta and Auth0 field lists; Google
Directory `domains`; Cloudflare `custom_hostnames`; hyn `hostnames`
migration.

**Asserted without a source — treated as unverified and not relied on in
this RFC:** that a code search for `is_primary` in archtechx/tenancy
returns zero results today (the removal commit itself is verified); Entra
External ID error codes and `forceDelete` operational limits; GitLab's
`redirect_routes` physical schema; and a claim that only one organisation
may hold a given verified domain per WorkOS environment, which
**contradicts** the changelog verified above — the changelog is
authoritative, and verification is non-exclusive.

Sections 1 and 3 were verified directly against the Neev source on
`main`; two research claims were corrected in the process and are flagged
inline in §3.
