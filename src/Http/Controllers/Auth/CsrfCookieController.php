<?php

namespace Ssntpl\Neev\Http\Controllers\Auth;

use Illuminate\Http\Response;
use Ssntpl\Neev\Http\Controllers\Controller;
use Ssntpl\Neev\Services\SpaCsrfToken;
use Symfony\Component\HttpFoundation\Cookie;

class CsrfCookieController extends Controller
{
    /**
     * Issue the signed double-submit CSRF cookie for SPA cookie mode.
     *
     * The cookie is attached to the response directly (not queued) so it
     * works regardless of the consuming app's cookie middleware stack,
     * and is deliberately not HttpOnly — the SPA must read it to echo
     * the value in the X-XSRF-TOKEN header.
     */
    public function show(SpaCsrfToken $csrf): Response
    {
        $cookie = new Cookie(
            name: config('neev.spa.csrf_cookie_name', 'XSRF-TOKEN'),
            value: $csrf->issue(),
            expire: now()->addMinutes(120),
            path: '/',
            domain: config('neev.spa.cookie_domain'),
            secure: (bool) config('neev.spa.cookie_secure', true),
            httpOnly: false,
            raw: false,
            sameSite: config('neev.spa.cookie_same_site', 'lax'),
        );

        return response()->noContent()->withCookie($cookie);
    }
}
