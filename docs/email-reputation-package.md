# Email Reputation Package (Proposed)

## What exists in Neev today

- `EmailDomainValidator` service — hardcoded list of ~50 free email domains, simple `in_array` check
- `require_company_email` config — toggles waitlisting teams created by free email users
- `free_email_domains` config — extends the hardcoded list
- Waitlist logic in registration — sets `activated_at = null` on teams from free email signups

All of this will be removed from Neev as it's outside the scope of an auth/identity package.

## What the new package should do

A standalone Composer package that scores email addresses for trust/reputation.

### Checks to perform
- **Free email provider** — Gmail, Yahoo, Outlook, etc.
- **Disposable/temporary email** — Mailinator, Guerrillamail, 10MinuteMail, etc.
- **Private relay email** — iCloud Hide My Email, Firefox Relay, etc.
- **Company email validation** — domain exists, MX records valid, domain age
- **Domain reputation** — is the domain known, how old is it

### Output
A confidence score and classification, so the consuming app can decide what action to take (block, waitlist, allow, flag for review).

### References
- https://gist.github.com/ammarshah/f5c2624d767f91a7cbdc4e54db8dd0bf
- https://github.com/disposable/disposable-email-domains
- https://github.com/disposable/disposable
