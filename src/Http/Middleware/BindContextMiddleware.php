<?php

namespace Ssntpl\Neev\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
        $this->contextManager->bind();

        $response = $next($request);

        $this->contextManager->clear();

        return $response;
    }
}
