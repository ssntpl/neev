<?php

namespace Ssntpl\Neev\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Ssntpl\Neev\Models\AccessToken;

abstract class Controller
{
    /**
     * Refuse an action to a scoped API token.
     *
     * Some actions carry the account's whole authority whatever scope the
     * caller was given: minting or editing API tokens, and enrolling a new
     * first factor. A scope that can reach those is not a scope — it can be
     * widened from inside, or stepped around by enrolling a credential that
     * signs in with full authority.
     *
     * A login token is the API's session — minted by authenticating with full
     * credentials, and what a cookie-mode SPA carries — so it is allowed, as
     * the session-authenticated Blade pages always have been. A request
     * authenticated by session carries no token at all and is likewise
     * unaffected.
     *
     * Enforced here rather than as route middleware on purpose: an
     * application may publish and edit `routes/neev.php`, and this is an
     * invariant rather than a policy it may attach where it likes.
     */
    protected function refuseApiTokenCredential(Request $request, string $message): ?JsonResponse
    {
        $credential = $request->attributes->get('neev.access_token');

        if ($credential instanceof AccessToken && $credential->token_type !== AccessToken::login) {
            return response()->json(['message' => $message], 403);
        }

        return null;
    }
}
