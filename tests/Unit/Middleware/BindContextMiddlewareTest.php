<?php

namespace Ssntpl\Neev\Tests\Unit\Middleware;

use Closure;
use Illuminate\Http\Request;
use Ssntpl\Neev\Http\Middleware\BindContextMiddleware;
use Ssntpl\Neev\Services\ContextManager;
use Ssntpl\Neev\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;

class BindContextMiddlewareTest extends TestCase
{
    private BindContextMiddleware $middleware;
    private ContextManager $contextManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contextManager = app(ContextManager::class);
        $this->middleware = new BindContextMiddleware($this->contextManager);
    }

    private function passThrough(): Closure
    {
        return fn (Request $req): Response => response()->json(['message' => 'OK'], 200);
    }

    public function test_clears_context_after_response(): void
    {
        $this->assertFalse($this->contextManager->isBound());

        $request = Request::create('/test');

        $this->middleware->handle($request, $this->passThrough());

        // Context should be cleared after the response is built
        $this->assertFalse($this->contextManager->isBound());
    }

    public function test_passes_through_to_next_middleware(): void
    {
        $request = Request::create('/test');

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_context_is_bound_before_next_runs(): void
    {
        $request = Request::create('/test');
        $boundDuringNext = null;

        $next = function (Request $req) use (&$boundDuringNext): Response {
            $boundDuringNext = app(ContextManager::class)->isBound();
            return response()->json(['message' => 'OK'], 200);
        };

        $this->middleware->handle($request, $next);

        $this->assertTrue($boundDuringNext);
    }
}
