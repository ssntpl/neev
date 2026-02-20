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
        $teamParam = $request->route('team');

        if ($teamParam === null) {
            return $next($request);
        }

        $teamClass = Team::getClass();

        $team = ctype_digit((string) $teamParam)
            ? $teamClass::find((int) $teamParam)
            : $teamClass::resolveBySlug((string) $teamParam);

        if (!$team) {
            return response()->json(['message' => 'Team not found.'], 404);
        }

        $this->contextManager->setTeam($team);
        $request->attributes->set('team', $team);

        return $next($request);
    }
}
