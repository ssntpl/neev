<?php

namespace Ssntpl\Neev\Services;

use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;
use Ssntpl\Neev\Models\User;

/**
 * Every link this package emails, and every response it gives when one is
 * followed. Extend it, override what you want, bind it once:
 *
 *     class AppEmailLinks extends EmailLinks
 *     {
 *         public function base(): string
 *         {
 *             return 'https://app.example.com';
 *         }
 *     }
 *
 *     // AppServiceProvider::register()
 *     $this->app->bind(EmailLinks::class, AppEmailLinks::class);
 */
class EmailLinks
{
    /**
     * Where your own pages live. Used by every link that has to land on a
     * page rather than finishing on the click.
     */
    public function base(): string
    {
        return rtrim(config('app.url'), '/');
    }

    /**
     * Verifying finishes on the click, so this points straight at the route
     * that does it and needs no page of yours.
     */
    public function verificationUrl(User $user, DateTimeInterface $expiresAt): string
    {
        $signed = $this->signed(
            $this->blade() ? 'verification.verify' : 'mail.verify',
            ['id' => $user->id, 'hash' => hash('sha256', $user->email)],
            $expiresAt,
        );

        return $signed;
        // Want your own page? return $this->page('/verify-email', $signed);
    }

    /**
     * Confirming a new address also finishes on the click.
     */
    public function emailChangeUrl(User $user, string $newEmail, DateTimeInterface $expiresAt): string
    {
        $signed = $this->signed(
            $this->blade() ? 'email.change.verify' : 'neev.email.change.verify',
            ['id' => $user->id, 'email' => $newEmail],
            $expiresAt,
        );

        return $signed;
        // Want your own page? return $this->page('/verify-email-change', $signed);
    }

    /**
     * A reset needs a new password, so the link has to reach a form. The
     * Blade kit ships one; otherwise it lands on your page.
     */
    public function passwordResetUrl(User $user, DateTimeInterface $expiresAt): string
    {
        $signed = $this->signed(
            $this->blade() ? 'reset.request' : 'neev.resetPassword',
            ['id' => $user->id, 'hash' => hash('sha256', $user->email)],
            $expiresAt,
        );

        return $this->blade() ? $signed : $this->page('/reset-password', $signed);
    }

    /**
     * Following this logs the person in. The Blade kit starts a session; other
     * apps get a token, which must not travel in a URL — so the link lands on
     * your page, which exchanges the query for it.
     */
    public function magicLinkUrl(User $user, DateTimeInterface $expiresAt): string
    {
        $signed = $this->signed(
            $this->blade() ? 'login.link' : 'loginUsingLink',
            ['id' => $user->id],
            $expiresAt,
        );

        return $this->blade() ? $signed : $this->page('/login-link', $signed);
    }

    /**
     * Accepting an invitation needs a registration form, so this lands on a
     * page too.
     *
     * NOTE: the non-Blade link carries no signature, only invitation_id and
     * sha1(email), yet RegistrationService::acceptInvitation() treats holding
     * it as proof the invite reached that inbox and marks the address
     * verified. Preserved as-is; signing it invalidates invitations already
     * in flight.
     */
    public function invitationUrl(int|string $invitationId, string $email, DateTimeInterface $expiresAt): string
    {
        if ($this->blade()) {
            return $this->signed('register', ['id' => $invitationId, 'hash' => sha1($email)], $expiresAt);
        }

        return $this->base() . '/register?' . http_build_query([
            'invitation_id' => $invitationId,
            'hash' => sha1($email),
        ]);
    }

    public function oauthCallbackUrl(string $service): string
    {
        return $this->base() . '/' . trim(config('neev.route_prefix', 'neev'), '/') . '/oauth/' . $service . '/callback';
    }

    public function verified(Request $request, User $user): Response
    {
        return $this->response($request, __('Email verification done.'));
    }

    /** A second click, or a mail scanner reaching the link first. Not an error. */
    public function alreadyVerified(Request $request, User $user): Response
    {
        return $this->response($request, __('Email verification already done.'));
    }

    public function verificationFailed(Request $request): Response
    {
        return $this->response($request, __('Invalid or expired verification link.'), 403, error: true);
    }

    public function emailChanged(Request $request, User $user): Response
    {
        return $this->response($request, __('Email address has been updated and verified.'));
    }

    /** Someone else claimed the address between request and confirmation. */
    public function emailInUse(Request $request, User $user): Response
    {
        return $this->response($request, __('This email address is already in use.'), 409, error: true);
    }

    public function emailChangeFailed(Request $request): Response
    {
        return $this->response(
            $request,
            __('Invalid or expired verification link.'),
            403,
            redirect: route('login'),
            error: true,
        );
    }

    /** One of your pages, carrying a signed link's query across to it. */
    protected function page(string $path, string $signedUrl): string
    {
        $url = $this->base() . $path;

        return $url . (str_contains($url, '?') ? '&' : '?') . parse_url($signedUrl, PHP_URL_QUERY);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function signed(string $route, array $params, DateTimeInterface $expiresAt): string
    {
        return URL::temporarySignedRoute($route, $expiresAt, $params);
    }

    /** JSON when the caller asked for JSON, otherwise a page. */
    protected function response(
        Request $request,
        string $message,
        int $status = 200,
        ?string $redirect = null,
        bool $error = false,
    ): Response {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], $status);
        }

        $response = redirect($redirect ?? config('neev.home'));

        return $error ? $response->withErrors(['message' => $message]) : $response;
    }

    /** The Blade starter kit ships its own pages for these flows. */
    protected function blade(): bool
    {
        return config('neev.ui') === 'blade';
    }
}
