# Email Reputation Package

> **Status:** v1 scope decided (2026-07-02) — classification-only, standalone package. Implementation not started.

## Background

Neev's `EmailDomainValidator` service, `require_company_email` config,
and the free-email waitlist logic were removed in v0.4.0 as outside the
scope of an auth/identity package. This document defines their
replacement: a standalone Composer package (`ssntpl/*`) that classifies
email addresses so consuming apps decide what to do.

## v1 scope — classification only (decided)

**No network calls.** v1 answers, from bundled data and string
inspection alone:

| Classification | Source |
|---|---|
| `free` (Gmail, Yahoo, Outlook, …) | bundled provider list |
| `disposable` (Mailinator, Guerrillamail, …) | bundled list, vendored from [disposable-email-domains](https://github.com/disposable/disposable-email-domains) with a documented refresh process |
| `relay` (iCloud Hide My Email, Firefox Relay, …) | bundled pattern list |
| `unknown` (none of the above — likely a company domain) | fallback |

**Output:** a classification value (not a score) — deterministic, fast,
and honest about what it can know without the network. Scoring implied
false precision; classification lets apps write clear policy
(`if ($result->isDisposable()) …`).

**Integration:** a validation-rule class plus a plain service, consumed
by the app — in neev-based apps, typically inside a
`RegistrationService`-adjacent listener or the app's registration
request validation. Neev itself takes **no dependency** on the package
(design principle: notifications/reputation are app-side policy;
events and `RegistrationService` are the hook points).

**Explicitly deferred to v2+ (network tier):** MX validation, domain
age/WHOIS, reputation APIs. These need timeout/failure policy, caching,
and privacy consideration (sharing user domains with third parties) —
none of which should block the useful 80%.

Design notes for whoever implements v1:

- Relay addresses are legitimate privacy tools — the package classifies,
  it must not recommend blocking them; README should say "allow + flag"
  is the sane default.
- List freshness: vendor the disposable list at build time with the
  source commit pinned, and document a `composer update`-time or CI
  refresh path. No runtime fetching.
- License check on the vendored lists before first release.
