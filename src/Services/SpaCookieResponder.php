<?php

namespace Ssntpl\Neev\Services;

use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Delivers auth tokens via HttpOnly cookie for SPA cookie mode.
 *
 * For requests from a configured stateful origin, attach() moves the
 * token from the JSON body into the auth cookie — the SPA never sees
 * it. Non-SPA callers (mobile, server-to-server) get the unchanged
 * JSON shape and no cookie. Cookies are attached to the response
 * directly (not queued) so behaviour does not depend on the consuming
 * app's cookie middleware stack.
 */
class SpaCookieResponder
{
    public function __construct(
        protected StatefulOriginResolver $origins,
    ) {
    }

    /**
     * Move the response's `token` field into the auth cookie when the
     * request comes from a stateful SPA origin.
     *
     * @param int $expiryMinutes Cookie lifetime — pass the token's own
     *                           expiry (login token or MFA JWT).
     */
    public function attach(Request $request, JsonResponse $response, int $expiryMinutes): JsonResponse
    {
        if (!$this->origins->isStateful($request)) {
            return $response;
        }

        $payload = $response->getData(true);
        $token = $payload['token'] ?? null;
        if (!is_string($token) || $token === '') {
            return $response;
        }

        unset($payload['token']);
        $response->setData($payload);

        return $response->withCookie($this->makeCookie($token, $expiryMinutes));
    }

    /**
     * Expire the auth cookie on responses to cookie-authenticated
     * requests (the `neev.spa` attribute set by
     * EnsureSpaRequestsAreStateful when it promoted the cookie).
     */
    public function clear(Request $request, JsonResponse $response): JsonResponse
    {
        if ($request->attributes->get('neev.spa') !== true) {
            return $response;
        }

        return $response->withCookie($this->makeCookie('', -60));
    }

    /**
     * Re-issue the auth cookie so the browser's copy tracks the token's
     * slid expiry — without this the cookie is dropped at its original
     * deadline even though the token behind it is still valid.
     *
     * Only acts on cookie-authenticated requests whose token expiry
     * moved this request (the `neev.token_expires_at` attribute set by
     * NeevAPIMiddleware), and never overwrites a cookie the response
     * set for itself, so logout still clears and token-issuing
     * endpoints keep their own.
     */
    public function refresh(Request $request, Response $response, string $token): Response
    {
        $expiresAt = $request->attributes->get('neev.token_expires_at');

        if ($request->attributes->get('neev.spa') !== true || !$expiresAt instanceof DateTimeInterface) {
            return $response;
        }

        $name = config('neev.spa.cookie_name', 'neev_session');

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $response;
            }
        }

        $minutes = (int) ceil(($expiresAt->getTimestamp() - now()->getTimestamp()) / 60);

        if ($minutes < 1) {
            return $response;
        }

        $response->headers->setCookie($this->makeCookie($token, $minutes));

        return $response;
    }

    /**
     * Build the auth cookie for attaching to any response type — used
     * by redirect flows (OAuth/SSO callbacks), where the stateful
     * decision belongs to the caller.
     */
    public function authCookie(string $token, int $expiryMinutes): Cookie
    {
        return $this->makeCookie($token, $expiryMinutes);
    }

    protected function makeCookie(string $value, int $expiryMinutes): Cookie
    {
        return new Cookie(
            name: config('neev.spa.cookie_name', 'neev_session'),
            value: $value,
            expire: now()->addMinutes($expiryMinutes),
            path: '/',
            domain: config('neev.spa.cookie_domain'),
            secure: (bool) config('neev.spa.cookie_secure', true),
            httpOnly: true,
            raw: false,
            sameSite: config('neev.spa.cookie_same_site', 'lax'),
        );
    }
}
