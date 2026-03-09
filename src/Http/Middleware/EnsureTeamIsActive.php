<?php

namespace Ssntpl\Neev\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTeamIsActive
{
    /**
     * Handle an incoming request.
     *
     * Check if the user's team is active. If not, block access to team features.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
            return redirect()->route('login');
        }

        $team = $user->currentTeam ?? $user->teams->first();

        if ($team && !$team->isActive()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Your team is pending approval.',
                    'waitlisted' => true,
                    'inactive_reason' => $team->inactive_reason,
                ], 403);
            }

            return redirect(config('neev.home'))->with('warning', 'Your team is pending approval. Some features are restricted.');
        }

        return $next($request);
    }
}
