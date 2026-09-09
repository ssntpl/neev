<?php

namespace Ssntpl\Neev\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Ssntpl\Neev\Contracts\HasMembersInterface;
use Ssntpl\Neev\Services\ContextManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the ContextManager, making the resolved context immutable.
 *
 * Must run LAST in the middleware chain — after tenant resolution,
 * team resolution, and authentication middleware have all populated
 * the context (tenant, team, user).
 */
class BindContextMiddleware
{
    public function __construct(
        protected ContextManager $contextManager
    ) {
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($refusal = $this->refuseUnownedTeam($request)) {
            return $refusal;
        }

        $this->contextManager->bind();

        $response = $next($request);

        $this->contextManager->clear();

        return $response;
    }

    /**
     * A team named by the request is a claim, not an authorization.
     *
     * `ResolveTeamMiddleware` accepts an `X-Team` header on every route in the
     * neev groups and sets the named team as the context; `TeamScope` then
     * scopes every team-owned model to it. No controller inspects that header,
     * so nothing else would ever check it. The membership test belongs here:
     * this middleware runs last, once the authenticating middleware has put the
     * user on the context.
     *
     * Two sources are deliberately left alone. A team resolved from the host
     * carries no `team` request attribute, so team-branded pages stay reachable
     * by non-members; and a team named by a route parameter is authorized by the
     * controller action it reaches, some of which are open by design.
     */
    protected function refuseUnownedTeam(Request $request): ?Response
    {
        // Only the header form. A route parameter is the controller's to
        // authorize, and some of those actions are open by design.
        if ($request->attributes->get('neev.team_source') !== 'header') {
            return null;
        }

        $team = $request->attributes->get('team');
        $user = $this->contextManager->currentUser();

        // With nobody signed in there is no membership to test against. The
        // request carries no identity, so it gains no member's view.
        // Checked against the interface rather than the concrete Team, so a
        // swapped-in team_model is guarded too.
        if (!$team instanceof HasMembersInterface || $user === null || $team->hasMember($user)) {
            return null;
        }

        $message = __('You cannot perform this action on this team.');

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }

        return redirect(config('neev.home'))->withErrors(['message' => $message]);
    }
}
