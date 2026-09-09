<?php

namespace Ssntpl\Neev\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Services\ContextManager;
use Symfony\Component\HttpFoundation\Response;

class ResolveTeamMiddleware
{
    public function __construct(
        protected ContextManager $contextManager
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $routeParam = $request->route('team');
        $teamParam = $routeParam ?? $request->header('X-Team');

        if ($teamParam === null) {
            return $next($request);
        }

        $teamClass = Team::getClass();

        // Route model binding may already have resolved the parameter into a
        // model, in which case there is nothing left to look up.
        $team = $teamParam instanceof $teamClass
            ? $teamParam
            : (ctype_digit((string) $teamParam)
                ? $teamClass::find((int) $teamParam)
                : $teamClass::resolveBySlug((string) $teamParam));

        if (!$team) {
            return response()->json(['message' => 'Team not found.'], 404);
        }

        $this->contextManager->setTeam($team);
        $request->attributes->set('team', $team);

        // Where the team came from decides who may vouch for it. A route
        // parameter lands on one controller action, which applies its own rule
        // — the team profile page is deliberately open to outsiders so they can
        // ask to join, while the members page is not. The `X-Team` header is
        // accepted on every route in the neev groups and no controller inspects
        // it, so BindContextMiddleware authorizes that form centrally.
        $request->attributes->set('neev.team_source', $routeParam !== null ? 'route' : 'header');

        return $next($request);
    }
}
