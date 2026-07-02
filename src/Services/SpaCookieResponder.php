<?php

namespace Ssntpl\Neev\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

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
