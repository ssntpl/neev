<?php

namespace Ssntpl\Neev\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Ssntpl\Neev\Models\AccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires the calling API token to carry every named ability.
 *
 * Attach per route, as with the other enforcement middleware:
 *
 *     Route::middleware(['neev:api', 'neev-token-can:write'])->put(...);
 *     Route::middleware(['neev:api', 'neev-token-can:read,write'])->post(...);
 *
 * `AccessToken` has carried a `permissions` column and a `can()` method since
 * the beginning, and nothing ever consulted them — a scoped token was a fully
 * privileged token. This is the piece that makes the column mean something.
 *
 * It is deliberately fail-closed. A request that arrives without a token has
 * nothing whose abilities can be checked, so it is refused rather than waved
 * through; this belongs on token-authenticated routes, after `neev:api`.
 *
 * Refusals are always JSON. Everything this middleware sees was authenticated
 * by a bearer token, and `neev:api` itself answers a failed token with JSON no
 * matter what the client's Accept header says; a redirect here would send a
 * scoped API client to an HTML page.
 */
class EnsureTokenCan
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $token = $request->attributes->get('neev.access_token');

        if (!$token instanceof AccessToken) {
            return $this->deny(__('This action requires an API token.'));
        }

        foreach ($abilities as $ability) {
            if (!$token->can($ability)) {
                return $this->deny(__('This token is not permitted to perform this action.'));
            }
        }

        return $next($request);
    }

    protected function deny(string $message): Response
    {
        return response()->json(['message' => $message], 403);
    }
}
