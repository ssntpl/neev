<?php

namespace Ssntpl\Neev\Tests\Unit\Middleware;

use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Http\Middleware\ResolveTeamMiddleware;
use Ssntpl\Neev\Services\ContextManager;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;
use Symfony\Component\HttpFoundation\Response;

class ResolveTeamMiddlewareTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    private ResolveTeamMiddleware $middleware;
    private ContextManager $contextManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contextManager = app(ContextManager::class);
        $this->middleware = new ResolveTeamMiddleware($this->contextManager);
    }

    private function passThrough(): Closure
    {
        return fn (Request $req): Response => response()->json(['message' => 'OK'], 200);
    }

    private function createRequestWithTeamParam($teamParam): Request
    {
        $request = Request::create('/teams/' . $teamParam);
        $route = new Route('GET', '/teams/{team}', fn () => null);
        $route->bind($request);
        $route->setParameter('team', $teamParam);
        $request->setRouteResolver(fn () => $route);

        return $request;
    }

    private function createRequestWithoutTeamParam(): Request
    {
        $request = Request::create('/dashboard');
        $route = new Route('GET', '/dashboard', fn () => null);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);

        return $request;
    }

    // -----------------------------------------------------------------
    // Passes through when no {team} route parameter
    // -----------------------------------------------------------------

    public function test_passes_through_when_no_team_parameter(): void
    {
        $request = $this->createRequestWithoutTeamParam();

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertFalse($this->contextManager->hasTeam());
    }

    // -----------------------------------------------------------------
    // Resolves team by numeric ID
    // -----------------------------------------------------------------

    public function test_resolves_team_by_numeric_id(): void
    {
        $team = TeamFactory::new()->create();

        $request = $this->createRequestWithTeamParam((string) $team->id);
        $resolvedTeam = null;

        $next = function (Request $req) use (&$resolvedTeam): Response {
            $resolvedTeam = $req->attributes->get('team');
            return response()->json(['message' => 'OK'], 200);
        };

        $response = $this->middleware->handle($request, $next);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertNotNull($resolvedTeam);
        $this->assertEquals($team->id, $resolvedTeam->id);
        $this->assertTrue($this->contextManager->hasTeam());
        $this->assertEquals($team->id, $this->contextManager->currentTeam()->id);
    }

    // -----------------------------------------------------------------
    // Resolves team by slug
    // -----------------------------------------------------------------

    public function test_resolves_team_by_slug(): void
    {
        $team = TeamFactory::new()->create(['slug' => 'acme-team']);

        $request = $this->createRequestWithTeamParam('acme-team');

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($this->contextManager->hasTeam());
        $this->assertEquals($team->id, $this->contextManager->currentTeam()->id);
    }

    // -----------------------------------------------------------------
    // Returns 404 when team not found
    // -----------------------------------------------------------------

    public function test_returns_404_when_team_not_found_by_id(): void
    {
        $request = $this->createRequestWithTeamParam('99999');

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(404, $response->getStatusCode());
        $this->assertStringContainsString('Team not found', $response->getContent());
        $this->assertFalse($this->contextManager->hasTeam());
    }

    public function test_returns_404_when_team_not_found_by_slug(): void
    {
        $request = $this->createRequestWithTeamParam('nonexistent-slug');

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(404, $response->getStatusCode());
        $this->assertFalse($this->contextManager->hasTeam());
    }

    // -----------------------------------------------------------------
    // Sets team on request attributes
    // -----------------------------------------------------------------

    public function test_sets_team_on_request_attributes(): void
    {
        $team = TeamFactory::new()->create(['slug' => 'my-team']);

        $request = $this->createRequestWithTeamParam('my-team');
        $attributeTeam = null;

        $next = function (Request $req) use (&$attributeTeam): Response {
            $attributeTeam = $req->attributes->get('team');
            return response()->json(['message' => 'OK'], 200);
        };

        $this->middleware->handle($request, $next);

        $this->assertNotNull($attributeTeam);
        $this->assertEquals($team->id, $attributeTeam->id);
    }
}
