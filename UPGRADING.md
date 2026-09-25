# Upgrade Guide

This guide summarises the breaking changes in each release and what a
consuming application must do when upgrading. For the full list of
changes see [CHANGELOG.md](./CHANGELOG.md).

> **Versioning note:** neev is on the 0.x line. Per
> [SemVer](https://semver.org/#spec-item-4), 0.x minor releases may
> contain breaking changes; they are always flagged as **BREAKING** in
> the changelog and listed here.

---

## 0.6.6 → Unreleased

**Emailed codes carry a purpose (schema change; action required on existing
installs).**
The `otp` table gains a `purpose` column and its unique index moves from
`(owner_id, owner_type)` to `(owner_id, owner_type, purpose)`, so a user holds
one code per purpose — email verification, confirmation, password reset — and a
code is accepted only for the purpose it was sent for. The package edits its
migration in place, so existing installs add a migration of their own. Codes
live 15 minutes, so the simplest path drops any outstanding ones. Run it in
maintenance mode (`php artisan down`) or at a quiet moment: a code issued
between the delete and the column change makes the change fail on PostgreSQL,
which cannot add a `NOT NULL` column to a table holding rows.

```php
DB::table('otp')->delete();

Schema::table('otp', function (Blueprint $table) {
    $table->dropUnique(['owner_id', 'owner_type']);
    $table->string('purpose', 32)->after('owner_type');
    $table->unique(['owner_id', 'owner_type', 'purpose']);
});
```

If you call `AuthService::verifyEmailOtp()`, `checkEmailOtp()` or
`discardEmailOtp()` yourself, pass an `Ssntpl\Neev\Enums\OtpPurpose` as the new
last argument. If you create `OTP` rows directly, set `purpose`.

**Codes are no longer interchangeable (action required if your client relied
on it).** A code from `POST {prefix}/confirmation/otp` used to satisfy
`POST {prefix}/email/verify-otp` too, and a verification code used to confirm
an action or reset a password. Each is now refused outside its purpose: to
verify an address, request a code from `POST {prefix}/email/send` (or the
verification email) rather than from the confirmation endpoint.

**Changing the email or the password retires outstanding codes (no action
required).** An email change discards every code the user holds, since each was
mailed to the old address; a password change discards a pending reset code, as
it already retired the reset link; deleting an account deletes its codes.

**The API-token page's two dialogs no longer collide (no action required).**
Only the shipped `account/tokens.blade.php` changed. An ejected copy still
shows the old token when "Permissions" is clicked right after creating one,
and does not reset the permissions dialog on Escape or a backdrop click. The
simplest fix is to re-eject the view. To patch yours instead: give the
new-token dialog `show="showToken"` and have its Done button set
`showToken = false`; render the permissions dialog outside the
`@if (session('token'))` rather than in its `@else`; drop the `x-show`,
`x-cloak`, `@keydown.escape.window` and `@click.away` attributes from both
`dialog-modal` tags, which the component never rendered; and copy the new
`permissionManager()` script over yours. The dialog `show` prop needs the
modal components from this release.

---

## 0.6.5 → 0.6.6

**A password can be reset with an emailed code (check what your
forgot-password screen and email show).**
The forgot-password email now carries a one-time code beside the link, and
either resets the password — on the API (`POST {prefix}/resetPassword` with
`email` + `otp`) and in the Blade kit (`POST /update-password` with `email` +
`otp`; no new routes). Both proofs are always sent, and the email template
decides which the user sees — the same arrangement as email verification. The
link keeps working as before, so nothing breaks, but check what your users will
see:

- **If you ejected the Blade kit**, your `auth/forgot-password.blade.php` has no
  field for the code. Copy the new one over yours (`--force` re-ejects every
  kit view, so use it only if you have customised none): once a code is out it
  shows the code form, posting to `user-password.update`. Or, to stay
  link-only, remove the code from the email template (below).
- **If you run headless**, your forgot-password screen needs a code input to
  use the code; until it has one, hide the code in the template.
- **The email template is yours** — `neev:ui` ejects
  `emails/email-verify.blade.php` on install, and it already renders `$otp`
  whenever one is set, so reset emails start showing the code as they are.
  Your copy says "enter this code on the device you signed up on", which
  reads oddly in a reset email; the package's now says "Or enter this code
  instead:", which fits both — copy that line into yours (or branch on
  `$purpose`). Every reset email — the API's, the Blade kit's, and the
  signed-in "email me a reset link" action's — now carries the purpose
  `Reset Password` (the kit's used to say `Forgot Password`). To keep resets link-only, hide the code for that
  purpose:

  ```blade
  @if (!empty($otp) && $purpose !== 'Reset Password')
  ```

**A reset link works once (no action required).**
A link is refused once the password has changed since it was sent. The send
time is the link's signed `expires` less `url_expiry_time`, compared with
`password_changed_at`; the URL is unchanged, so links sent before you deploy
keep working under the same rule.

- **If your code writes `password` directly** rather than through
  `AuthService::changePassword()`, set `password_changed_at` in the same save,
  or links sent before that change stay usable until they expire.
- **If you change `url_expiry_time`**, links already out are judged by the new
  value until they expire.
- **Resets are now also limited per account**: 3 reset emails per 15 minutes
  and 10 wrong codes per hour, answered with `429` and `Retry-After` on the
  API. Handle `429` on your forgot-password and reset screens.

**`VerifyUserEmail` subjects name their purpose (action required if you match
on the subject).**
The subject was always `Email Verification`; it is now the purpose the email
was sent for — `Verify Email`, `Reset Password` or `Verify Email Change` — so
update any test assertion or mail filter that matched the old subject.

**Adding a factor and minting recovery codes now need confirmation (action
required if you call either).**
Removing a factor was confirmed in the previous change; these two reach the
same end from the other side. `POST {prefix}/recoveryCodes` and
`POST /account/recovery/codes` hand back a complete second factor in
plaintext, and `POST {prefix}/mfa/add` lets whoever holds a stolen token enrol
their own authenticator. Both now take `password` — or `otp`, from
`POST {prefix}/confirmation/otp` — exactly as removal does.

- **Enrolling the *first* factor is unchanged.** The gate starts once the
  account already holds an active one, so onboarding asks for nothing. A
  pending setup does not count.
- **`GET /account/recovery/codes` no longer generates codes.** It used to mint
  a set whenever the account held none, which meant reading a page created
  credentials. Generating is the confirmed `POST`; the plaintext is flashed to
  the page that follows it and is never recoverable afterwards.
- **Enrolling a passkey is confirmed too, and always.**
  `POST {prefix}/passkeys/register/options` and
  `POST /account/passkeys/register/options` take `password` or `otp`. A passkey
  signs in with the account's whole authority and is never parked at the MFA
  challenge, so it is more than a second factor. Clients that start the
  ceremony must collect the proof first and send it with the options request.
- **`POST {prefix}/mfa/add` and `POST {prefix}/mfa/setup/verify` refuse a
  scoped API token**, as passkey enrolment and token management already did.
  Use a login token.
- **If you ejected the Blade kit**, three views changed. The security page
  gained confirmation dialogs on Add and Edit and a confirmation field beside
  the passkey form, the recovery-codes page gained a dialog on Generate and an
  empty state for an account with no codes yet, and all of them — plus the
  delete-account, remove-factor and log-out-other-sessions dialogs, which each
  carried their own copy — now use a new
  `resources/views/vendor/neev/components/confirm-identity.blade.php`. That is
  five dialogs and the passkey form. Re-eject with
  `php artisan neev:ui blade --force` if you have not customised them;
  otherwise copy the component in and point each dialog at it.
- **The modal components take a `show` prop naming the Alpine variable that
  opens them** — `components/modal.blade.php` and the `dialog-modal` and
  `confirmation-modal` wrappers around it. It defaults to `show`, so every
  existing call site behaves exactly as before and needs no change. **Re-eject
  all three together with the security page**, though: that page's new Set up
  dialog passes `show="showEdit"`, and against an older copy of the components
  the prop is ignored and the dialog binds to `show` — the same variable as
  the Delete and Add controls beside it, so one button opens two dialogs.

  The reason it needs a prop at all: `modal.blade.php` never renders
  `{{ $attributes }}`, so attributes passed at the call site are dropped
  rather than reaching the panel. The kit's own dialogs used to pass
  `x-show="show" x-cloak @keydown.escape.window="show = false"
  @click.away="show = false"` and worked only because the component hardcoded
  the same thing internally. Those dead attributes are removed from the
  shipped views. If you pass anything similar in a view of your own, it is
  not taking effect — name the variable with `show` instead.
- **The recovery-codes page prints with a stylesheet, and no longer reloads.**
  The plaintext arrives once, so a reload after a cancelled print dialog would
  have replaced the only copy of a freshly minted set. It also no longer swaps
  `document.body.innerHTML` out and back, which left markup Alpine had stopped
  driving — Copy, Download and Generate went dead after a print. An
  `@media print` block shows only `#printable-area` instead; keep that id if
  you restyle the printed sheet.
- **Recovery-code generation is limited to five a minute** on both surfaces.

**A failed confirmation reports one message, keyed by the field it asked for
(action required if you match on the old strings).**
The four actions that ask an account to prove itself each carried their own
copy of the "does this account have a password" branch and their own wording:
`Password is Wrong.`, `Password is incorrect.`, `The password is incorrect.`
`AuthService::confirmationError()` is the one place that decides now, so all
of them answer `The password is incorrect.` or
`The confirmation code is invalid or has expired.`

- **API clients matching on the old message strings** need updating. The
  status codes are unchanged (`422` for a missing field, `403` for a wrong
  one).
- **Blade**: `DELETE /account/accountDelete` put its error under the
  `message` key; it is now under `password` or `otp`, like the other three
  always were. The shipped views render every error, so the kit is
  unaffected — only code reading `$errors->first('message')` for this action
  needs the new key.

**Auto-provisioned SSO accounts are created without a password (action
required if you have any from an earlier version).**
`TenantSSOManager` used to write a random password and discard the plaintext,
so the column said those accounts had one while nobody could produce it. They
could not delete their account, sign other sessions out, or remove a second
factor — each of those asks for a password when the column is populated, and
the emailed-code branch was unreachable for them — and after
`password_expiry_days` they met "Your password has expired" on every route
behind `neev-password-not-expired`, with no password to change. New
provisioning matches an OAuth registration: no password, no expiry clock.

Existing rows cannot be told apart from a real password by looking at them, so
nothing is migrated automatically. For accounts you know were provisioned by
SSO and have never set a password of their own, clear the column:

```php
// Adjust the selection to your own records of which accounts are SSO-only.
User::whereNotNull('password')
    ->whereIn('email', $ssoOnlyAddresses)
    ->update(['password' => null, 'password_changed_at' => null]);
```

Or leave them and tell those users to use "forgot password" once: the reset
link goes to the address their IdP already verified, and after it they hold a
password the gates can check. Either way they stop being locked out.

**Removing a multi-factor method now needs confirmation (action required if
you call it).**
`DELETE {prefix}/mfa/delete` and the Blade `POST /account/multiFactorAuth`
with `action=delete` asked for the method name alone, so a stolen session or bearer
token could strip the factor guarding the account. Both now take `password` —
or `otp`, for an account that has none, from
`POST {prefix}/confirmation/otp` — like account deletion and
`logoutAll` already did. A missing field is `422`, a wrong one `403`, and the
factor stays. Update any client that removes factors.

**If you ejected the Blade kit**, edit
`resources/views/vendor/neev/account/security.blade.php`: the Delete control
on each factor row has to collect the confirmation. The shipped stub now opens
a dialog with a password field, or a code field plus an "Email me a code"
button when the account has no password — copy that block, or re-eject the
file with `php artisan neev:ui blade --force` if you have not customised it
(without `--force` the command skips files that already exist). Until you do,
Delete answers with "The password field is required." and no field to fill.

**Signing out other sessions refuses off the database driver (no action
required unless you relied on it appearing to work).**
`POST /account/logoutSessions` with no `session_id` used to rotate the
caller's own session id and report success on `file`, `redis` and `cookie`
drivers, while every other session stayed signed in. It now returns an error
naming the reason. Use `SESSION_DRIVER=database`, or attach Laravel's
`AuthenticateSession` middleware for the driver-agnostic equivalent.

**`TenantResolver::runInContext()` throws on a request that has already bound
its context.** It could never have worked there — `ContextManager` is
immutable after `BindContextMiddleware` binds — but it used to fail halfway
and leave the resolver pointing at the new context. Call it from a queued job,
an artisan command, or before the context is bound. Code that called it inside
a bound request was already reading the wrong tenant; it now gets a
`LogicException` instead.

**BREAKING: a verified platform subdomain is now its own passkey relying
party (action required if tenants sign in with passkeys on your
subdomains).**
Every host under `relying_party_id` used to share one relying party, so a
passkey enrolled on `acme.example.com` was the same credential as one
enrolled on `example.com` or on `other.example.com`. Shared relying party
means shared credentials: a tenant that can run script on its own subdomain
could start a ceremony that returns a victim's credential ids and completes
with the browser showing your platform's name the whole way through. A
verified row inside your zone is now treated like any other — `acme.example.com`
gets `rp.id = "acme.example.com"`.

- **Passkeys enrolled on a platform subdomain stop working there** and are no
  longer offered. They are not deleted: they still appear in
  `GET {prefix}/passkeys` with their old `rp_id`, and users enrol again on the
  subdomain. Tell those users before you deploy.
- **Unaffected:** passkeys on the platform's own hosts (which resolve no
  tenant context and keep `relying_party_id`), passkeys on a tenant's custom
  domain, and legacy credentials with a null `rp_id`, which are still read as
  belonging to the configured relying party.
- **`rp.name` on a tenant's subdomain is now the tenant's name**, not
  `app.name`, since the relying party is the tenant's host.
- Keeping the shared-zone behaviour is a code-level choice, and it takes
  **two** overrides in a subclass of `RelyingPartyResolver` bound in a service
  provider: `settle()`, to leave a platform-zone host unclaimed so `rpId()`
  falls back to `relying_party_id`, *and* `allowedOrigins()`, to admit that
  host anyway. Overriding `settle()` alone produces a state neither version
  ever shipped — the configured relying party with the host the browser is on
  not admitted — in which every ceremony on a platform subdomain fails.

**BREAKING: an API token can no longer manage API tokens (action required if
an integration mints or edits tokens).**
Every route under `{prefix}/apiTokens` used to accept whichever credential
authenticated the request, so a leaked scoped token could widen itself to
`['*']`, mint a fresh wildcard, or delete the account's other tokens —
leaving `neev-token-can` enforcing a scope its own holder could rewrite. All
five now answer `403` to a token whose type is not `login`.

- **Enrolling a passkey is refused to a scoped token too**, for the same
  reason one level along: a passkey login is a complete factor that returns a
  full login token, so a scoped token able to enrol an authenticator could
  sign in past every scope it was given. `POST {prefix}/passkeys/register/options`
  and `POST {prefix}/passkeys/register` answer `403` to a non-login token.
- **Unaffected:** signing in and using the login token it returns, a
  cookie-mode SPA (its cookie carries a login token), the Blade account pages,
  and `$user->createApiToken(...)` called from your own code, jobs or commands.
- **`PUT`/`DELETE {prefix}/apiTokens` resolve their target among API tokens
  only.** They reached every token the account held, including the caller's
  own login token — so an `expiry` there reset the absolute ceiling on a
  session. Revoke a session through `DELETE {prefix}/sessions/{id}` or by
  logging out; a request naming a login token id now gets `400`/`404`.
- **Affected:** any script or service that authenticates with a *scoped API
  token* and then calls these endpoints. Give it a login token obtained by
  signing in, or mint the tokens it needs from your own application code.
- `permissions` must now be an array of strings; a bare string is `422` rather
  than a 500.

**BREAKING: team invitations already in flight stop working (action required
if you have pending invitations).**
The emailed invitation link carried the invitation's row id and
`sha1(email)`. Neither is a secret — the id is a plain auto-increment and the
address is known to whoever is guessing — yet holding the pair was treated as
proof the invitation had reached that inbox: registration marked the address
verified and granted the invited role on the strength of it. The link now
carries a per-invitation secret instead.

`team_invitations` gains a `token` column, so installs that have already run
that migration add it themselves:

```php
Schema::table('team_invitations', function (Blueprint $table) {
    $table->string('token')->nullable()->after('role');
});
```

- **Pending invitations have no secret and are refused.** Invite those
  addresses again — `POST {prefix}/teams/inviteUser` and the Blade action
  both replace the existing row, issuing a fresh secret and a new link.
- **The link's query parameter is `token`, not `hash`** — on both the Blade
  and headless URLs. Two consequences that need action whether or not you
  override anything:
  - **If you ejected the Blade kit**, edit
    `resources/views/vendor/neev/auth/register.blade.php`: the hidden field is
    now `name="token"` fed by `$token`, where it was `name="hash"` fed by
    `$hash`. Re-running `php artisan neev:ui blade` **skips files that already
    exist**, so it will not repair this for you. Until you change it, every
    invited user reaches the form and is then refused with "Invalid or expired
    invitation link."
  - **If you are headless**, your `/register` page must read `token` from the
    invitation URL and forward it to `POST {prefix}/register` as `token`. A
    page still forwarding `hash` sends nothing the endpoint reads.
- **`EmailLinks::invitationUrl()` changed signature** from
  `(int|string $invitationId, string $email, DateTimeInterface $expiresAt)`
  to `(int|string $invitationId, string $token, …)`. If you override it, pass
  the plaintext through to your own page.
- **Accepting an invitation now requires a verified address.** The signed-in
  accept and decline actions (`PUT {prefix}/teams/inviteUser`, the Blade
  equivalent) checked only that the account's address matched the
  invitation's. Registration issues a token for an address immediately, so
  that check let anyone who knew an invited address register it and take the
  membership and role in its name — the emailed secret closed the
  registration door, this closes the other one. An unverified address is also
  no longer told what it was invited to. Users mid-verification must verify
  before accepting.
- **If you ejected `account/teams.blade.php`**, its Accept/Reject form posted
  `$invitation->team->id` as `invitation_id`. That was always wrong — it acted
  on whichever invitation happened to share that integer — and is now fixed in
  the stub to `$invitation->id`. Apply the same one-line change to your copy.
- **`expires_at` is now enforced.** It was stored on every invitation and
  never read, so invitations the mail described as lasting seven days in fact
  lasted forever. Accepting an expired invitation is refused, on the
  registration path and for a signed-in invitee alike.

---

## 0.6.4 → 0.6.5

**`POST /neev/logoutAll` now requires confirmation (action required).**
It previously revoked every other login token on the bearer token alone
— the one account-takeover tool in the API that asked for nothing, while
its Blade counterpart had always required a password.

- **Update every client that calls it.** Send the account's password:

  ```http
  POST /neev/logoutAll
  Authorization: Bearer {token}
  Content-Type: application/json

  {"password": "CurrentPassword123!"}
  ```

  A request with no confirmation now returns `422`, and a wrong value
  `403`.

- **An account with no password sends `otp` instead.** `users.password`
  is `null` for every OAuth and SSO registration. Request a code from
  the new `POST /neev/confirmation/otp`, then send it as `otp`. The same
  applies to `DELETE /neev/users`, which previously accepted an empty
  body from those accounts and now requires the code.

- **Unaffected:** `POST /neev/logout` (the current session only), and
  revoking one named session — `DELETE /neev/sessions/{id}`, or
  `POST /account/logoutSessions` with a `session_id`. Those still ask for
  nothing, so the all-at-once gate can be sidestepped one session at a
  time: `GET /neev/sessions` to enumerate, then one `DELETE` per id.
  Gate them in your own application if that matters for your deployment.


---

## 0.6.3 → 0.6.4

**OAuth logins are recorded as `oauth:<provider>` (action required if you read
`login_attempts.method`).**
The OAuth callbacks wrote the bare provider name from `neev.oauth` into the same
column that names built-in methods (`password`, `passkey`, `magic auth`, `sso`,
`oauth`) — so a provider called `sso` inherited the SSO exemption in
`NeevMiddleware` and walked an MFA-enrolled account past its challenge. The value
is namespaced now: `oauth:google`.

- **No migration ships for this.** Existing rows keep the bare provider name;
  logins from the deploy onward use the new form. To convert history, run this
  once per configured provider:

  ```php
  DB::table('login_attempts')
      ->where('method', 'google')
      ->update(['method' => 'oauth:google']);
  ```

- **Update anything that matches the provider name** —
  `where('method', 'google')` and login-history UI that prints
  `$attempt->method`.

**Magic links are now stateful and single-use (action required).**
The stateless signed-URL flow is gone. Links are opaque tokens stored
hashed in the new `magic_link_tokens` table, deleted on redemption, and
superseded whenever a newer link is issued for the same channel.

- **Run `php artisan migrate`** — the new `magic_link_tokens` table is
  required. Any signed magic links already in users' inboxes stop
  working on deploy; users simply request a new one.
- **Links are single-use and expire faster.** A link can be redeemed
  exactly once, and the default expiry drops from 60 to 10 minutes
  (`magic_link.expires_in`, `NEEV_MAGIC_LINK_EXPIRY`). The legacy
  `url_expiry_time` no longer governs magic links — it still applies to
  password-reset and email-verification links.
- **Redemption now takes a `token` parameter**, not signed-URL query
  params. Frontends must forward the `token` from the link to
  `POST /neev/loginUsingLink`. The emailed URL points at
  `{EmailLinks::base()}{path}?token=...` (default
  `{app.url}/login-link`), so your `/login-link` page reads `token` from
  the query string and posts it.
- **The magic-link host comes from `EmailLinks::base()`.** The web
  channel has no `base_url` key any more. The default is unchanged
  (`app.url`), but an app that published `config/neev.php` and set
  `magic_link.channels.web.base_url` to a separate frontend must move
  that origin into an `EmailLinks::base()` override — the stale config
  key is ignored, not honoured, so the links would otherwise quietly go
  back to pointing at the backend.
- **Clients must handle `confirmation_required`.** Because links are
  single-use, a GET must never consume one: mail-scanning gateways
  (Outlook SafeLinks, Mimecast) prefetch links and would burn them
  before the user clicks. `GET /neev/loginUsingLink` therefore returns
  `{"auth_state": "confirmation_required"}` — render a "confirm sign-in"
  button and `POST` the same token back to complete login.
  `GET|POST /neev/loginUsingLink/validate` checks a token without
  consuming it. To restore one-click GET redemption, set
  `NEEV_MAGIC_LINK_CONFIRMATION=false` — only do this if you are certain
  your users are not behind a scanning mail gateway.
- **Blade users:** the legacy `GET /login/{id}` route (`login.link`) is
  removed; redemption is `GET|POST /login-link/verify`
  (`login.link.verify`). Re-run `php artisan neev:ui blade` or apply the
  new `auth/confirm-login-link.blade.php` view if you ejected the kit.
  Apps that published `routes/neev.php` must re-apply the route change.
- **Unverified addresses are unaffected (no action required).** A magic
  link is still mailed to an unverified address, and redeeming it still
  marks the email verified and fires `EmailVerified` — following the link
  proves inbox control just as the verification mail would. This matches
  0.6.0 exactly; nothing about it changed.
- **Malformed input is now a rejection, not a 500.**
  `POST /neev/sendLoginLink` without an `email` returns `422` (it raised
  a `TypeError` before), and a non-scalar `token` on the redemption and
  validate endpoints is treated as an invalid token rather than raising
  "Array to string conversion".
- **Browser binding** (`magic_link.bind_to_browser`, default off) binds
  a link to the device that requested it. When enabled, generation
  throws `MagicLinkBindingException` if the request has no binding
  source (`X-Device-Id` header, a `binding` field, or a session) —
  rather than minting a link that could never be redeemed. Session-less
  API clients must send `X-Device-Id` before enabling it.
- **Issuance is capped per account** — `MagicLinkManager::ISSUANCE_LIMIT`
  (3) links per channel per `ISSUANCE_WINDOW` (5 minutes), a code
  constant rather than config. `POST /neev/sendLoginLink` answers `429`
  with a `Retry-After` header and a `retry_after` field; the Blade form
  redirects back with the message in the error bag; a direct caller of
  `MagicLinkManager::generate()` (your own job or command) must handle
  the new `MagicLinkThrottledException`. Every issuance invalidates the
  previous link, so without a cap anyone who knew an address could keep
  its owner's link permanently dead. To widen it, extend the manager,
  override `reserveIssuance()`, and rebind the singleton.
- All of these refusals happen **before** the previous link is
  invalidated, so a rejected send never costs the user the working link
  already in their inbox.
- **An unusable channel is now rejected, not silently downgraded.**
  `POST /neev/sendLoginLink` returns `422` ("Unsupported login link
  channel.") and `MagicLinkManager::generate()` throws
  `MagicLinkChannelException` when the requested channel is not declared
  under `magic_link.channels`, or is a deep-link channel whose `scheme`
  and `universal_link` are both empty. Previously both cases fell through
  to a web URL: a client asking for `channel=mobile` got a `200` and an
  emailed link that opens a browser instead of the app, with nothing
  logged. **If you send mobile links, set `NEEV_MOBILE_SCHEME` or
  `NEEV_MOBILE_UNIVERSAL_LINK` before deploying** — a mobile send that
  used to appear to work will now fail loudly.
- **The magic-link host no longer follows the request.** The Blade flow
  built its emailed URL with `route()`, which takes the host from the
  `Host` header — an unauthenticated attacker could have the application
  mail a working login token pointing at a host of their choosing. The
  URL is now built by `MagicLinkManager` from configuration (and, in
  tenant mode, the tenant's own verified domain). `channels.web.path` now
  defaults to unset and follows the UI mode: `/login-link/verify` for the
  Blade kit, `/login-link` for headless. Set `NEEV_MAGIC_LINK_WEB_PATH`
  to override.
- Redemption routes are now rate-limited (`throttle:10,1`).
- Schedule `neev:clean-magic-links` alongside `neev:clean-login-attempts`
  to purge expired tokens.

**A tenant's own hosts are now admitted as passkey origins (no action
required).** Every admitted origin is still named exactly, as before —
subdomain matching was already off, so a host under `relying_party_id`
never completed a ceremony on its own. What is new is where the names
come from:

- **A tenant's hosts** come from its verified `domains` rows, so a
  tenant on `acme.example.com` or on its own `acme.com` needs no entry
  in `allowed_origins`. Only the host the request's `Origin` names is
  admitted, and since that host is now its own relying party, a sibling
  has nothing to be admitted against.
- **Your own hosts** still come from `allowed_origins`, unchanged. If
  you serve passkeys from `app.example.com` or `login.example.com` as
  well as the apex, each must be listed verbatim — as it had to be
  before. Apps serving passkeys only from `app.url` need no change.

`allowed_origins` applies on every relying party, which is where a
native app's `android:apk-key-hash:…` facet goes. If every host under
your platform domain really is your own, you may widen the check by
overriding `allowSubdomains()` in a subclass of `RelyingPartyResolver`
and binding it in a service provider. That loosens a security boundary
beyond what any released version did — see
[docs/authentication.md](./docs/authentication.md#origins).

**BREAKING: the API's two passkey options endpoints are now `POST`.**
`GET /neev/passkeys/register/options` and
`GET /neev/passkeys/login/options` are gone; call
`POST /neev/passkeys/register/options` (no body) and
`POST /neev/passkeys/login/options` with `{"email": "..."}` in the body
instead of `?email=`. Update any client that calls them — a `GET` now
returns `405`.

The reason is the relying party, which is now the context's verified
domain equal to the request's `Origin` — an exact match, because
`domains` is also the federation registry and a row there (`acme.com`,
federated so `@acme.com` staff auto-join) need not be served anywhere. A
request that names no origin keeps `relying_party_id`, which the browser
then refuses on a tenant's own host. Browsers attach `Origin` themselves
on every POST and on every cross-origin request, and omit it on a
same-origin GET, where it is a forbidden header name client JavaScript
cannot add back — so a GET options endpoint could not name its origin
from the host it is served on, which is the ordinary Blade layout and any
SPA deployed beside its API. As POST, both endpoints name their origin
wherever they are called from, and passkeys on a tenant's own domain need
no change of deployment. The Blade starter kit's own options routes
(`POST /account/passkeys/register/options`,
`POST /passkeys/login/options`) were already POST and are unchanged. See
[docs/authentication.md](./docs/authentication.md#supported-domains).

**Passkeys gain a per-credential relying party column (schema change).**
The `passkeys` table gains an `rp_id` column so each credential records
the relying party it was issued under. The package edits its migration
in place, so existing installs add the column themselves:

```php
Schema::table('passkeys', function (Blueprint $table) {
    $table->string('rp_id')->nullable()->index()->after('credential_id');
});
```

The column is nullable, so existing rows are valid immediately — a null
`rp_id` is read as the configured `relying_party_id`, which is where
those credentials were enrolled. No data migration is needed.

---

## 0.6.2 → 0.6.3

**OAuth logins are now challenged for MFA (action required if you enable OAuth
providers).**
The OAuth callback used to sign an MFA-enrolled account straight in. It now
stops at the same challenge a password login does, so:

- **API clients** must handle `auth_state: mfa_required` from
  `POST {prefix}/oauth/{service}/callback`. The response carries the
  short-lived MFA JWT in `token` and the enrolled factors in `mfa_options`,
  exactly as the password login does; complete it with
  `POST {prefix}/mfa/otp/verify` to get the real login token. A client that
  assumes `token` is always a login token will send an unusable credential.
- **Web flows** are redirected to `EmailLinks::mfaChallengeUrl()` instead of to
  the intended URL (`config('neev.home')` when nothing was stashed); the
  intended URL is picked up once the challenge passes. That is the Blade kit's
  `otp.mfa.create` page when the kit is installed, and `{base}/mfa-challenge/{method}`
  on your own frontend otherwise — the OAuth routes are registered either way,
  so a headless install reaches this too. Override the method alongside
  `loginUrl()` if your page lives elsewhere.
- **On a same-origin SPA monolith** the `neev_session` cookie no longer carries
  a login token out of the callback. It carries the short-lived MFA JWT, which
  `POST {prefix}/mfa/otp/verify` swaps for the real login token; the Blade
  challenge page's own `POST /otp/mfa` does the same for a session
  flow. A frontend that read that cookie expecting a login token will find a
  credential that is only good for the OTP step until the challenge passes.

Accounts with no active MFA method are unaffected.

**A passkey login now satisfies the MFA gate.**
The passkey ceremony runs with `userVerification: 'required'`, so it already
proves possession plus a local user check. A web passkey login by an
MFA-enrolled account used to be parked at the challenge page; it now reaches
protected routes directly, which is what the API already did. Nothing to do on
upgrade — if your application requires a second factor on top of a passkey,
gate it in your own middleware.

**Tenant/team SSO now satisfies the MFA gate on the web too (fixes a lockout).**
The API side has always issued a full token straight from the SSO callback, on
the grounds that the organization's identity provider owns its authentication
policy. The web side was gated by `NeevMiddleware` and then stranded, because
`TenantSSOController::callback()` never sets `session('email')` — the challenge
page had no account to act on and bounced to the login screen, so an
MFA-enrolled member could not sign in through their organization's provider at
all. Both sides now let an SSO login through.

If you were relying on the web gate as a second factor for SSO members, it was
not working — it locked them out rather than challenging them. Enforce MFA at
the identity provider, where the policy and the enrollment already live, or
gate it in your own middleware.

**`login_attempts.multi_factor_method` now means "the factor this login
demands", not "the factor it used".**
It is written when the challenge opens rather than when the code verifies, and
`is_success` carries whether the login completed. `NeevMiddleware` checks both
and either one closes the gate, so a session parked at the challenge when you
deploy is still challenged rather than let through on a row written the old way
round. The web flow previously left
`multi_factor_method` null while parked and marked the attempt successful
after the *first* factor; both halves now follow the API's convention.

- **Reporting on `login_attempts` needs a second look.** A row with
  `multi_factor_method` set no longer means the second factor was supplied —
  pair it with `is_success` to tell a completed login from an abandoned
  challenge. An abandoned web challenge is now recorded as unsuccessful, where
  it used to be recorded as a success.
- **`AuthService::login()` and `recordLoginAttempt()` take a trailing
  `bool $pendingMfa = false`.** Both new parameters are last and default to the
  old behaviour, so existing calls are unaffected. Pass `pendingMfa: true`
  alongside `mfa:` if you have a custom first factor that parks at the
  challenge, or the login will be recorded as complete before the second
  factor.

---

## 0.6.1 → 0.6.2

**`type` on `POST {prefix}/tenant-domains` is ignored (action required if you
hand out subdomains).**
Whether a claimed domain is verified immediately or has to publish a DNS TXT
record is now derived from the host and the claiming team. A tenant's subdomain
is its slug, so team `acme` is issued `acme.otper.com` and that single claim is
taken on trust; everything else — another team's slug, one of your own
operational hosts like `app.otper.com`, the apex, any outside domain — publishes
the TXT record. Set `platform_domain` to the zone (or zones) your installation
hands subdomains out under:

```php
// config/neev.php
'platform_domain' => 'otper.com',
```

Until you set it, **nothing auto-verifies** — every domain added through that
endpoint comes back with a `verification_token` and waits for DNS. If your app
was sending `type: subdomain` to get instant verification, that is the change to
make; the field itself is now ignored rather than rejected, so no request will
start failing.

**While you are there, review `slug.reserved`.** A slug is now the host a team
is issued, so that list is what keeps your own operational names — and any brand
you would not want in front of your domain — out of tenants' hands. The package
ships the operational defaults it can know about; `google.otper.com` on your
certificate is a decision only you can make.

Existing rows are untouched. Worth auditing them once, though: under the old
behaviour any team owner could set `verified_at` on any domain by asking, so a
verified claim in your `domains` table is not evidence that the team owns it.

```php
// Verified claims that would not auto-verify under the new rule.
Domain::whereNotNull('verified_at')
    ->with('owner')
    ->get()
    ->reject(fn ($d) => Domain::isPlatformSubdomainFor($d->domain, $d->owner?->slug));
```

Every row this returns is verified for a host outside your platform zones, so
each one either passed DNS verification honestly or was taken on trust under the
old rule, and the record cannot tell you which. Holding a
`verification_token` is not the tiebreaker it looks like: `PUT {prefix}/domains`
and the Blade domain pages rotate a token onto a row without clearing
`verified_at`, so a claim made under the old behaviour can carry one. Confirm the
survivors against your own records of who owns what.

**Email MFA codes gain an attempt counter (one schema note).**
`multi_factor_auths` gains an `attempts` column so an emailed MFA code is
spent after 5 wrong guesses, as the email-verification code already was.
The package edits its migration in place, so installs that have already
run it add the column themselves:

```php
Schema::table('multi_factor_auths', function (Blueprint $table) {
    $table->unsignedTinyInteger('attempts')->default(0);
});
```

Two behaviour changes come with it, neither needing action. Reopening the
MFA challenge page no longer extends a live code's expiry — it previously
refreshed `expires_at` without issuing a new code, so the same secret
could be kept alive indefinitely. And a code is cleared once spent,
whether entered correctly or exhausted, so a user who runs out of guesses
must request a new code rather than retrying the old one.

**A password change now signs the account's other devices out (action
required only on non-database session drivers).** `AuthService::changePassword()`
drops the account's other login tokens and, on the `database` session driver,
its other web sessions; the session and token making the request survive. API
tokens are untouched — revoke them from a `PasswordChanged` listener if your
product wants that. On `file`, `redis` or `cookie` sessions the package cannot
reach the other sessions; attach Laravel's `AuthenticateSession` middleware to
your authenticated routes to get the same effect there. Sessions are read from
`session.connection`, so a separate session database works unchanged. See
[docs/security.md](./docs/security.md#what-a-password-change-revokes).

---

## 0.6.0 → 0.6.1

All four changes in this release are security fixes. None needs a schema or
config change, but three alter behaviour a consuming application may have been
relying on.

**An `X-Team` header now requires membership (action required if you used it to
act across teams).** `ResolveTeamMiddleware` accepted the header on every route
in the neev groups and made the named team the request context, and `TeamScope`
then scoped every team-owned model to it — so any signed-in user could read
another team's records by setting one header. `BindContextMiddleware` now
refuses a header-named team the caller is not a member of. Two other sources are
deliberately untouched: a team resolved from the **host** still serves
non-members, so team-branded pages stay reachable, and a team named by a **route
parameter** is still the controller's to authorize. If an admin or support tool
of yours sets `X-Team` to a team its operator does not belong to, give that
operator membership or reach the team through a route parameter with your own
authorization.

**`DELETE {prefix}/teams/members/leave` (Blade) is gated on membership (no
action required).** It took both the team and the subject from the request and
checked neither against the caller. `TeamApiController::leave()` was fixed in
0.6.0; the web twin carries the same rules now.

**The Blade MFA challenge answers for the session, not the request body (action
required only if you posted to it directly).** `POST /otp/mfa` resolved the
account from an `email` field in the request and signed it in without a
credential check — one second factor for an address was a complete standalone
credential. The account now comes from `session('email')`, set by the password
step, and `auth_method` must name a factor the account has actually enrolled. A
custom login page must go through the package's password step to open the
challenge rather than posting an `email` of its own.

**A rejected MFA code no longer opens the gate (no action required).**
`verifyMFAOTPStore()` stamped `login_attempts.multi_factor_method` before
verifying the code and left it set on failure, which `NeevMiddleware` read as
proof the challenge had been answered.

---

## 0.5.0 → 0.6.0

**Laravel 13 support (additive; no action required).**
The `laravel/framework` requirement widens to `^12.0|^13.0`, so apps
may upgrade to Laravel 13 whenever they choose. Nothing is required of
apps staying on Laravel 12. Packages developing against neev should
note `orchestra/testbench` now allows `^11.0` for the Laravel 13 line.

**Middleware aliases renamed (action required if you use them).**
The opt-in alias middleware now use a hyphen instead of a colon:

| Old | New |
|-----|-----|
| `neev:verified-email` | `neev-verified-email` |
| `neev:password-not-expired` | `neev-password-not-expired` |
| `neev:active-team` | `neev-active-team` |
| `neev:active-tenant` | `neev-active-tenant` |
| `neev:tenant-member` | `neev-tenant-member` |
| `neev:resolve-team` | `neev-resolve-team` |
| `neev:ensure-sso` | `neev-ensure-sso` |

A colon is Laravel's separator between a middleware name and its
parameters, so `neev:verified-email` resolved as the `neev` middleware
taking a `verified-email` argument rather than as its own alias. Search
your routes for the colon form and rename. The middleware **groups**
(`neev:web`, `neev:api`, `neev:login`, `neev:tenant`) are unchanged —
group names are looked up separately and take no parameters. Earlier
sections of this file still show the colon form; they described the
release they belong to.

**Emailed links now work without a session (action required for
headless frontends).**
`GET {prefix}/email/verify` moved out of the authenticated route group,
and `{prefix}/email/change/verify` now answers `GET` as well as `POST`.
The signature is the credential, so:

- Stop sending `Authorization: Bearer …` to these endpoints — you may,
  but it is no longer needed and no longer influences the result.
- A verification link acts on the account it was minted for, not on
  whoever is signed in. If your frontend relied on the old behaviour of
  refusing a link that did not match the current session, that check is
  gone.
- These endpoints answer JSON only when the request asks for it
  (`Accept: application/json`). A plain browser GET gets a redirect —
  to `neev.home` on success, or to `login` with an error bag for a
  failed email change.

**Where emailed links point is now `EmailLinks` (action required only
if you patched it).**
Link building moved out of the controllers into
`Ssntpl\Neev\Services\EmailLinks`. If you were overriding controllers
or filtering mail to rewrite URLs, replace that with a subclass:

```php
// app/Providers/AppServiceProvider.php — register()
$this->app->bind(
    \Ssntpl\Neev\Services\EmailLinks::class,
    \App\Services\AppEmailLinks::class,
);
```

One default changed: the headless **verification** link used to point at
`{app.url}/verify-email?…` and now points straight at the API route that
performs the verification, because that flow finishes on the click and
needs no page of yours. If you want it back on your page, override
`verificationUrl()` — see [docs/email-links.md](./docs/email-links.md).
Password-reset, magic-link, and invitation links still land on your
frontend as before.

**Verification is now inferred from other proofs (behaviour change, no
action required).**
An unverified address is marked verified when the user signs in through
OAuth, follows a magic link, or registers through a team invitation —
each demonstrates control of the inbox. Previously these paths refused
the user instead. The OAuth case carries a trade-off worth reading:
[docs/security.md](./docs/security.md#oauth-and-email-verification).

Relatedly, an **unverified address can now request and use a password
reset link**. If your app depended on reset being unavailable to
unverified users, add that check in your own layer.

**Email OTP now requires a verified address (behaviour change).**
`POST {prefix}/mfa/add` with `auth_method: email` answers `422
Email is not verified.` for an unverified account instead of enrolling
the factor. Note also that **every** failure from this endpoint is now
`422` rather than a `200` carrying an error message — including
`Email already Configured.`, which used to return `200`. Clients that
branched on the message inside a `200` need updating.

**Team actions are authorised by membership (behaviour change).**
Team update, domain listing, join-request handling, member removal, the
Blade team pages, and role changes now require the caller to be a joined
member and answer `403 You cannot perform this action on this team.`
Two things follow:

- A team that does not exist is refused the same way as one that is not
  yours. Endpoints that used to answer `400 Team not found` now answer
  `403`, deliberately — they no longer confirm which team ids exist.
- Owning a team and belonging to it are separate records. Every path
  that creates a team attaches the creator as a member, but if you
  created teams directly in your own code, backfill the pivot:

  ```php
  Team::whereDoesntHave('allUsers', fn ($q) => $q->whereColumn('users.id', 'teams.user_id'))
      ->each(fn ($team) => $team->addMember($team->owner));
  ```

**`VerifyUserEmail` constructor changed (action required if you send it
yourself).**
`$expiry` split into `$link_expiry` and `$otp_expiry`, because the link
and the code expire on different clocks. The new signature is
`(url, username, purpose, link_expiry, otp_expiry, otp)`. If you ejected
the `email-verify` template before this release, `{{ $expiry }}` no
longer resolves — use `{{ $link_expiry }}` and `{{ $otp_expiry }}`.

**`User` casts moved to a `casts()` method (no action required).**
Declared as a method rather than a `$casts` property, so a subclass
declaring its own `$casts` no longer silently replaces neev's. If you
had been re-declaring neev's casts in your subclass to work around this,
you can drop them.

**Email verification code (additive; one schema note).**
Verification emails now carry a numeric code alongside the signed link,
verifiable via `POST {prefix}/email/verify-otp` or the Blade kit's
verification page. The `otp` table gains an `attempts` column — the
package edits its migration in place, so existing installs add it
themselves: `$table->unsignedTinyInteger('attempts')->default(0);`
Apps that ejected the `email-verify` template before this release
won't show the code until they add the `$otp` block (see the stub
template) — everything else works regardless.

**`AccessToken::mfa_token` removed (action required only if you
reference it).**
`NeevAPIMiddleware` used to confine a token of that type to the MFA
endpoints by matching the request path against the configured route
prefix. Nothing in the package ever minted one — the API MFA step-up is
a short-lived JWT guarded by the `neev:login` group — so the type was
dead weight and the path check a second, weaker copy of a rule the
route groups already enforce. A token is now judged on its hash and its
expiry alone.

Grep your app for `AccessToken::mfa_token` and `AccessTokenFactory::mfa()`
(also removed) and drop the references; the constant no longer exists,
so a reference is a fatal error rather than a silent no-op. Any rows
already stored with `token_type = 'mfa_token'` keep working — the column
is a plain string and the type is no longer consulted — but they are now
accepted on every API route, so delete them if that matters:

```php
AccessToken::where('token_type', 'mfa_token')->delete();
```

**Team name uniqueness scoped to the tenant (schema change).**
`teams` swaps `unique(['name', 'user_id'])` and
`unique(['tenant_id', 'slug'])` for a single
`unique(['tenant_id', 'name', 'user_id'])`. Two tenants can now each
hold an owner's "Acme"; slug uniqueness is unchanged, carried by the
`slug` column's own installation-wide unique index (stricter than the
per-tenant one that was dropped, and what lets slug lookups skip the
tenant filter).

The package edits its migration in place, so existing installs make the
swap themselves:

```php
Schema::table('teams', function (Blueprint $table) {
    $table->dropUnique(['name', 'user_id']);
    $table->dropUnique(['tenant_id', 'slug']);
    $table->unique(['tenant_id', 'name', 'user_id']);
});
```

Deduplicate first if any `(tenant_id, name, user_id)` triple repeats, or
the index will not build.

> **Caveat:** SQL treats `NULL`s as distinct in a unique index, so on
> installs running without tenants (`tenant_id IS NULL` on every row)
> the new index does not fire and one owner *can* hold two teams with
> the same name — the old `(name, user_id)` index blocked that. If that
> matters to you, enforce it in validation or add a partial index for
> `tenant_id IS NULL`.

**Acting on a join request from the Blade form is owner-only (action
required if you relied on members doing it).**
`PUT {prefix}/teams/members/request/action` now refuses anyone but the
team owner. Accepting a request admits someone to the team and can hand
them a role, which is what inviting does, and inviting is owner-only.
The API counterpart (`PUT {prefix}/teams/request`) is unchanged and
still allows any member.

**Login `redirect` is validated (action required only if you passed
absolute URLs).**
The Blade login form's `redirect` now has to be a same-site path.
Absolute (`https://…`), protocol-relative (`//host`), `/\host`, bare
`/`, and non-string values fall back to `config('neev.home')`. If you
were passing an absolute URL to send users to another host after login,
that no longer works — it was an open redirect. See
[docs/security.md](./docs/security.md#open-redirect-protection).

**Passkey and SSO columns narrowed to `string` (no action required in
most cases).**
`passkeys.public_key`, `passkeys.aaguid`, `passkeys.transports` and
`sso_client_id` on both auth-settings tables moved from `text` to
`string`, which caps them at 255 characters. Existing rows are
untouched. If your provider issues a client id — or your authenticators
a public key — longer than 255 characters, keep those columns as `text`
in your own migration.

**Remember-me removed (action required only if you relied on it).**
The login page's checkbox, the `remember` flag `LoginRequest` passed to
`Auth::login()`, and `remember_token` in `User::$hidden` are gone. A
remembered session outlives the session lifetime, MFA and
password-expiry policies neev exists to enforce, and nothing in the
package ever issued or cleared the cookie beyond passing that flag. The
`remember_token` column is untouched, so if you want the behaviour back,
call `Auth::login($user, true)` from your own login path. A submitted
`remember` field is now simply ignored.

**Import the password rule from neev (action required if you published
`config/neev.php`).**
`php artisan config:cache` fails on a published config that imports
`Illuminate\Validation\Rules\Password` — the cache is written with
`var_export()` and read back with `require`, and an object without
`__set_state()` makes that file fatal on load. This is what Laravel
reports as a non-serializable config value. Change the import at the top
of your `config/neev.php`:

```php
-use Illuminate\Validation\Rules\Password;
+use Ssntpl\Neev\Rules\Password;
```

`Ssntpl\Neev\Rules\Password` extends Illuminate's rule and adds only
that method, so `Password::min(8)->symbols()` reads and behaves exactly
as before. If you would rather not edit the file, run the rule list
through `Ssntpl\Neev\Rules\Password::upgrade()` where you build it.
`PasswordHistory` and `PasswordUserData` gained the same method in the
package, so they need no change.

**Team routes are registered only when `team => true` (action required
if you run without teams and linked to them).**
The web `/teams/*` group, the `/account/teams` page, and the API
`teams`, `domains` and `changeTeamOwner` blocks used to register
regardless of the `team` config value, exposing endpoints whose
controllers assume a team context. With `'team' => false` those paths
now answer `404` and `route('teams.create')` throws a
`RouteNotFoundException` — wrap any link to them in
`@if (config('neev.team'))`. Installs with teams on are unaffected.

**`GET {prefix}/tenant-domains/current` response shape (action required
only if you read `team`).**
The endpoint returned the resolved context under a `team` key whatever
that context actually was, and resolution from the request host or the
`X-Tenant` header always yields a `Tenant`. It now reports:

```json
{ "data": { "type": "tenant", "context": {…}, "domain": {…}, "team": null } }
```

`team` is kept for older callers but is populated only when `type` is
`team` — that is, only when the application made a Team the context
itself via `TenantResolver::setCurrentTenant()`. Read `context` and
branch on `type`.

**Accounts without a password (behaviour change, no action required).**
Accounts created through OAuth, tenant SSO, a magic link or a passkey
hold `users.password = null`, and `Hash::check()` against a null hash
can never succeed — so those accounts could not be deleted, and their
password and email could not be changed. Now:

- `DELETE {prefix}/deleteUser` and the Blade delete dialog require
  `password` only when the account has one; otherwise the authenticated
  session is the confirmation and the body may be empty. Clients that
  always sent a password are unaffected.
- Change-password answers `403 Your account has no password yet. Use
  the emailed link to set one.`, and it now answers `404 User not
  found.` where it previously dereferenced a missing user.
- Changing the address still costs a password — it is what owns the
  account — and answers `403 Set a password on your account before
  changing your email address.` until one is set.
- The link to set one is the new `POST /account/password/reset-link`
  (Blade kit, 5 requests per minute). Headless frontends build the same
  link with `EmailLinks::passwordResetUrl()`. If you render your own
  security page, branch on `$user->password` the way the ejected stub
  now does. See
  [docs/authentication.md](./docs/authentication.md#accounts-without-a-password).

**Teams now record their tenant on create (behaviour change).**
In isolated mode a `Team` created while a tenant context is resolved
gets that tenant's id, which it previously did not — `Team` does not use
`BelongsToTenant` (its global scope would break the resolution that
reads Teams), and the assignment that trait provides was missing with
it. An explicit `tenant_id` still wins, and in shared mode nothing is
assigned. Teams created before this release keep `tenant_id = NULL`;
backfill them if your queries depend on it.

## 0.4.5 → 0.5.0

**The package is now headless by default (RFC 002, action required for
Blade UI users).**
Page views are no longer auto-loaded from the package, and the Blade
page routes (`/login`, `/account/...`) only register when
`config('neev.ui') === 'blade'`.

- **Using the shipped Blade UI?** Run `php artisan neev:ui blade` —
  it ejects the views to `resources/views/vendor/neev` (app-owned from
  then on) and sets `'ui' => 'blade'`. If you had already published
  the views, your files are untouched (same path); just set the `ui`
  config.
- **Headless / SPA / API-only?** Nothing to do — the Blade page routes
  disappear (they were dead weight), and verification/invitation email
  links now point at your frontend (`{app.url}/verify-email?...`,
  `{app.url}/register?invitation_id=...&hash=...`) carrying the
  signed query for the API endpoints.
- Email templates: the installer copies them to
  `resources/views/vendor/neev/emails` so they're yours to edit; the
  package retains fallbacks. The available variables per template are
  documented and stable (see RFC 002 §5.5).
- The `neev-views` publish tag is replaced by `neev-blade-kit` and
  `neev-mail`.

**OAuth/SSO routes moved under the route prefix (action required for
identity providers).**
All machine-facing routes now live under the configurable
`route_prefix` (default `neev`):

| Old path | New path |
|---|---|
| `/oauth/{service}` and `/oauth/{service}/callback` (web) | `/neev/oauth/{service}[/callback]` |
| `/sso/redirect`, `/sso/callback` | `/neev/sso/redirect`, `/neev/sso/callback` |
| `/api/tenant/auth` | `/neev/tenant/auth` |

- **Update the redirect URIs registered at your identity providers**
  (Microsoft Entra, Google, Okta app registrations and OAuth apps) to
  the new callback URLs.
- Apps that published `routes/neev.php` keep their published copy —
  re-publish or re-apply your customisations to pick up the prefix.
- To rename the namespace (e.g. `/auth/...`), set
  `NEEV_ROUTE_PREFIX=auth`. Route *names* (`neev.*` etc.) are
  unchanged either way.

**Authenticator MFA setup now requires verification (behaviour change).**
Adding an authenticator creates it in `pending` status; the user must
submit a valid TOTP (API: `POST /neev/mfa/setup/verify`; web: the
Verify form on the security page) before the method becomes active and
is enforced at login. Consequences for existing installs:

- The `multi_factor_auths` table gains a `status` column. The package
  edits its migration in place; existing installs must add the column
  themselves with `default('active')` so already-configured methods
  keep working: `$table->string('status')->default('active');`
- API clients that add an authenticator must follow up with the
  setup-verify call — until then the method is not enforced and does
  not appear in `mfa_options`.
- `MfaMethodAdded` fires on activation (email OTP: immediately, since
  the account email is already verified).
- Schedule `neev:clean-pending-mfa-setups` to purge abandoned setups
  (`mfa_pending_setup_retention_days`, default 2 days).

**Laravel 11 support dropped.**
`laravel/framework` requirement is now `^12.0`. Laravel 11 is past
security-EOL with permanently-unpatched advisories (Composer ≥2.9
refuses to install it by default). Apps still on Laravel 11 should pin
neev to `<=0.4.5` and plan a framework upgrade.

**Event class renames.**
`Ssntpl\Neev\Events\LoggedInEvent` → `LoggedIn` and
`LoggedOutEvent` → `LoggedOut`. Update listener registrations and
type-hints. Payloads are unchanged (`public $user`).

**New events are additive** — see CHANGELOG for the full list. One
behavioural note: neev now fires `Illuminate\Auth\Events\Registered`.
If your app's User model implements `MustVerifyEmail`, Laravel's
auto-registered `SendEmailVerificationNotification` listener will react
to it — disable that listener or neev's own verification mail to avoid
duplicate emails. (Neev's shipped User model does not implement the
contract, so default installs are unaffected.)

## 0.4.4 → 0.4.5

**Users table consolidation (schema change).**
The `emails` and `passwords` tables were dropped; `email`,
`email_verified_at`, `password`, `password_history` (JSON), and
`password_changed_at` are now columns on `users`. Multi-email-per-user
support was removed.

- The package edits its `create_users_table` migration in place — it
  does **not** ship a data migration for existing installs. Apps
  upgrading with production data must write their own migration to
  copy each user's primary email/password onto `users` and drop the
  two tables.
- The `Email` and `Password` models, the `VerifyEmail` trait, and the
  email management endpoints (add / delete / set-primary email) no
  longer exist. Use `User::findByEmail()`, `User::uniqueEmailRule()`,
  and `hasVerifiedEmail()` / `markEmailAsVerified()` on the user model.
- Remove `neev:clean-passwords` from your scheduler — the command is
  gone (password history is self-trimming).

**Login flow (API contract change).**
- Login responses now return `auth_state` (`authenticated` |
  `mfa_required`). Clients must branch on it instead of assuming a
  token is always present at the top level.
- The MFA step-up between password and OTP verification is a
  short-lived JWT (signed with `NEEV_JWT_SECRET`, falling back to
  `APP_KEY`), verified by `POST /neev/mfa/otp/verify` under the new
  `neev:login` middleware group.

**Email verification / password reset.**
- Always via signed URLs now. OTP-based email verification and
  OTP-based API password reset were removed, along with the
  `email_verification_method` config key.

**Passkeys.**
- New `relying_party_id` and `allowed_origins` config keys. Multi-origin
  deployments (apex + subdomains, staging + production) must list every
  origin in `allowed_origins`.

## 0.4.3 → 0.4.4

- **Email verification is no longer enforced automatically.** The
  hardcoded checks were removed from `NeevMiddleware` /
  `NeevAPIMiddleware`. Attach the `neev:verified-email` alias to the
  routes that require a verified email.
- **API tokens are accepted only via the `Authorization: Bearer`
  header.** The query-string and request-body fallbacks were removed.
- `platform_team_id` and `managed_by_tenant_id` columns were removed
  from `tenants`, along with the related CLI options and relationships.

## 0.4.2 → 0.4.3

- **Renames:** `current_team_id` → `default_team_id` (users table),
  `currentTeam()` → `defaultTeam()`, `switchTeam()` →
  `setDefaultTeam()`, and the endpoint `PUT /neev/teams/switch` →
  `PUT /neev/teams/default`.
- **`TenantScope` is now fail-closed.** With tenant mode enabled and no
  tenant resolved, queries scope to `tenant_id IS NULL` (platform
  users) instead of running unscoped. Platform code that needs to
  operate inside a tenant context outside a request should use
  `TenantResolver::runInContext()`.

## 0.4.0 → 0.4.1

- **`role` column dropped from the `team_user` pivot.** Roles are
  managed exclusively via laravel-acl (`assignRole()` / `getRole()`).
- Dead `$team->default_role` usage removed.

## 0.3.x → 0.4.0 (config overhaul)

The config surface was reduced from ~30 keys to ~20 with two orthogonal
identity flags. Republish the config (`php artisan vendor:publish
--tag=neev-config --force`) and re-apply your customisations.

| Removed key(s) | Replacement |
|---|---|
| `identity_strategy`, `tenant_isolation`, `tenant_isolation_options` | `tenant` boolean |
| `tenant_auth`, `tenant_auth_options` | `tenant_auth_settings` / `team_auth_settings` DB tables (`neev:auth:configure`) |
| `email_verified` | opt-in `neev:verified-email` middleware |
| `domain_federation` | per-domain behaviour on the `domains` table |
| `require_company_email`, free-email list (`EmailDomainValidator` removed) | none yet — planned standalone email-reputation package |
| `magicauth` | magic links always available |
| `login_soft_attempts`, `login_hard_attempts`, `login_block_minutes` | progressive `login_throttle` (`delay_after`, `max_delay_seconds`) |
| `password_soft_expiry_days`, `password_hard_expiry_days` | single `password_expiry_days` + opt-in `neev:password-not-expired` middleware |
| `otp_min`, `otp_max` | `otp_length` |
| `dashboard_url`, `frontend_url` | `home` |
| `geo_ip_db` etc. | `maxmind` array (`db_path`, `edition`, `license_key`) |
| `record_failed_login_attempts` | `log_failed_logins` |
| `last_login_attempts_in_days` | `login_history_retention_days` |

Other breaking changes in 0.4.0:

- **Domain model:** polymorphic `owner` (morphTo) replaces
  `team_id`/`tenant_id`; DNS verification consolidated into
  `$domain->verify()`.
- **Enforcement middleware is opt-in** — nothing is auto-applied.
  Attach `neev:password-not-expired`, `neev:active-tenant`,
  `neev:active-team`, `neev:ensure-sso` where your app needs them.
- `NeevAuthenticatable` umbrella trait added — prefer it over composing
  the individual traits on your User model.
