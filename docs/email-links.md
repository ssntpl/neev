# Email Links

Every link neev puts in an email, and every response it gives when one is
followed, comes from a single class: `Ssntpl\Neev\Services\EmailLinks`. It is
the one seam to override when you want links to land on your own pages, or
want a followed link to answer differently.

Before this existed, each controller built its own URL inline, and headless
installs got frontend paths hardcoded to `config('app.url')`. Those decisions
now live in one place you can subclass.

---

## Contents

- [The defaults](#the-defaults)
- [Overriding](#overriding)
- [URL builders](#url-builders)
- [Response hooks](#response-hooks)
- [Helpers for your subclass](#helpers-for-your-subclass)

---

## The defaults

Two things decide what a link looks like:

1. **Does the flow finish on the click?** Verifying an address and confirming
   an address change both complete the moment the link is opened — there is
   nothing left for a page to do, so the link points straight at the route
   that does the work.
2. **Or does it need a form?** A password reset needs somewhere to type the
   new password, and a magic link mints a token that must not travel in a URL.
   Those land on a page instead.

The second question is answered differently depending on whether the Blade
starter kit is installed (`config('neev.ui') === 'blade'`), because the kit
ships the pages that a headless install has to provide itself.

| Flow | Blade kit installed | Headless |
|------|--------------------|----------|
| Email verification | `verification.verify` (signed) | `mail.verify` (signed) |
| Email change | `email.change.verify` (signed) | `neev.email.change.verify` (signed) |
| Password reset | `reset.request` (signed) | `{base}/reset-password?{signed query}` |
| Magic link | `login.link` (signed) | `{base}/login-link?{signed query}` |
| Team invitation | `register` (signed) | `{base}/register?invitation_id=…&hash=…` |
| OAuth callback | `{base}/{route_prefix}/oauth/{service}/callback` | same |
| Sign-in page | `login` | `{base}/login` |
| Verify-email page | `verification.notice` | `{base}/verify-email` |

`{base}` is `EmailLinks::base()`, which defaults to `config('app.url')` with
any trailing slash removed.

The last two rows are not emailed — they are where the package sends a browser
that cannot go on: an expired session, an unverified address, a failed SSO
callback, a team the user may not reach, a spent magic link. Only the Blade kit
registers `login` and `verification.notice`, so these live here alongside the
emailed links: everything that has to name one of your pages asks `EmailLinks`,
and a headless install overrides `base()` — or the two methods — instead of
registering routes under names the package expects.

> **Note on the invitation link.** The headless invitation URL carries no
> signature — only `invitation_id` and `sha1(email)` — yet holding it is
> treated as proof the invitation reached that inbox, and registration marks
> the address verified on the strength of it. This is preserved deliberately:
> signing it would invalidate every invitation already in flight. Registration
> does check that the address being registered is the one the invitation was
> addressed to.

---

## Overriding

Subclass it, change what you want, bind it once:

```php
// app/Services/AppEmailLinks.php
namespace App\Services;

use Ssntpl\Neev\Services\EmailLinks;

class AppEmailLinks extends EmailLinks
{
    public function base(): string
    {
        return 'https://app.example.com';
    }
}
```

```php
// app/Providers/AppServiceProvider.php
public function register(): void
{
    $this->app->bind(\Ssntpl\Neev\Services\EmailLinks::class, \App\Services\AppEmailLinks::class);
}
```

Overriding `base()` alone is enough when your frontend lives on a different
host from the API but keeps neev's paths (`/reset-password`, `/login-link`,
`/register`).

### Sending a link to your own page

Verification and email-change links finish on the click, so by default they
never touch your frontend. If you want them to land on a page of yours — to
show a branded confirmation, for instance — wrap the signed URL with `page()`:

```php
class AppEmailLinks extends EmailLinks
{
    public function verificationUrl(User $user, DateTimeInterface $expiresAt): string
    {
        return $this->page('/verify-email', parent::verificationUrl($user, $expiresAt));
    }
}
```

`page()` carries the signed URL's query across to your path, so your page can
forward it to `GET {prefix}/email/verify` and act on the result.

### Changing what a followed link answers

The response hooks are separate from the URL builders, so you can keep the
default links and change only the reply:

```php
class AppEmailLinks extends EmailLinks
{
    public function verified(Request $request, User $user): Response
    {
        return $request->expectsJson()
            ? response()->json(['message' => __('Welcome aboard.')])
            : redirect('/welcome');
    }
}
```

---

## URL builders

| Method | Signature |
|--------|-----------|
| `base()` | `(): string` |
| `verificationUrl()` | `(User $user, DateTimeInterface $expiresAt): string` |
| `emailChangeUrl()` | `(User $user, string $newEmail, DateTimeInterface $expiresAt): string` |
| `passwordResetUrl()` | `(User $user, DateTimeInterface $expiresAt): string` |
| `magicLinkUrl()` | `(User $user, DateTimeInterface $expiresAt): string` |
| `invitationUrl()` | `(int\|string $invitationId, string $email, DateTimeInterface $expiresAt): string` |
| `oauthCallbackUrl()` | `(string $service): string` |
| `loginUrl()` | `(): string` |
| `verifyEmailUrl()` | `(): string` |

Expiry is passed in, not decided here — callers derive it from
`config('neev.url_expiry_time')` (60 minutes by default).

---

## Response hooks

Each returns a `Symfony\Component\HttpFoundation\Response`. The defaults
answer JSON when `$request->expectsJson()` and redirect otherwise.

| Method | Default | Status |
|--------|---------|--------|
| `verified($request, $user)` | `Email verification done.` | 200 |
| `alreadyVerified($request, $user)` | `Email verification already done.` | 200 |
| `verificationFailed($request)` | `Invalid or expired verification link.` | 403 |
| `emailChanged($request, $user)` | `Email address has been updated and verified.` | 200 |
| `emailInUse($request, $user)` | `This email address is already in use.` | 409 |
| `emailChangeFailed($request)` | `Invalid or expired verification link.`, redirects to `loginUrl()` | 403 |

`alreadyVerified` is deliberately not an error. A second click, or a mail
scanner that reaches the link before the recipient does, is a normal thing to
happen to a link sitting in an inbox.

---

## Helpers for your subclass

| Method | Purpose |
|--------|---------|
| `page(string $path, string $signedUrl): string` | Your path on `base()`, carrying a signed URL's query across |
| `signed(string $route, array $params, DateTimeInterface $expiresAt): string` | `URL::temporarySignedRoute()` |
| `response(Request $request, string $message, int $status = 200, ?string $redirect = null, bool $error = false): Response` | The JSON-or-redirect reply used by every hook |
| `blade(): bool` | Whether the Blade starter kit is installed |

---

## See also

- [Authentication](./authentication.md) — the flows these links belong to
- [SPA Authentication](./spa-authentication.md) — wiring a separate frontend
- [Configuration](./configuration.md) — `url_expiry_time`, `route_prefix`, `ui`
