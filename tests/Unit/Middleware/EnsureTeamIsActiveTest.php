<?php

namespace Ssntpl\Neev\Tests\Unit\Middleware;

use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Http\Middleware\EnsureTeamIsActive;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;
use Symfony\Component\HttpFoundation\Response;

class EnsureTeamIsActiveTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    private EnsureTeamIsActive $middleware;

    protected function setUp(): void
    {
        parent::setUp();

        $this->middleware = new EnsureTeamIsActive();

        Route::get('/login', fn () => 'login')->name('login');
        Route::get('/dashboard', fn () => 'dashboard')->name('dashboard');
    }

    /**
     * Build a request with an optional user. Accepts 'json' or 'html' to configure Accept header.
     */
    private function buildRequest(string $path = '/dashboard', ?User $user = null, string $format = 'html'): Request
    {
        $server = [];
        if ($format === 'json') {
            $server['HTTP_ACCEPT'] = 'application/json';
        }

        $request = Request::create($path, 'GET', [], [], [], $server);

        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession(app('session.store'));

        return $request;
    }

    /**
     * The "next" closure that returns a simple 200 OK response.
     */
    private function passThrough(): Closure
    {
        return fn (Request $req): Response => response('OK', 200);
    }

    /**
     * Attach a user to a team with joined=true.
     */
    private function attachUserToTeam(User $user, Team $team): void
    {
        $team->allUsers()->attach($user->id, [
            'role' => 'member',
            'joined' => true,
        ]);
    }

    // -----------------------------------------------------------------
    // No user: JSON request
    // -----------------------------------------------------------------

    public function test_returns_401_json_when_no_user_and_expects_json(): void
    {
        $request = $this->buildRequest('/dashboard', null, 'json');

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertStringContainsString('Unauthenticated', $response->getContent());
    }

    // -----------------------------------------------------------------
    // No user: HTML request
    // -----------------------------------------------------------------

    public function test_redirects_to_login_when_no_user_and_html_request(): void
    {
        $request = $this->buildRequest('/dashboard', null, 'html');

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertTrue($response->isRedirection());
        $this->assertStringContainsString('/login', $response->headers->get('Location'));
    }

    // -----------------------------------------------------------------
    // Inactive team: JSON request
    // -----------------------------------------------------------------

    public function test_returns_403_json_with_waitlisted_when_team_inactive_and_json_request(): void
    {
        $user = User::factory()->create();
        $team = TeamFactory::new()->inactive('pending review')->create(['user_id' => $user->id]);
        $this->attachUserToTeam($user, $team);

        // Set as current team
        $user->update(['current_team_id' => $team->id]);
        $user->refresh();

        $request = $this->buildRequest('/dashboard', $user, 'json');

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(403, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);
        $this->assertTrue($body['waitlisted']);
        $this->assertStringContainsString('pending approval', $body['message']);
        $this->assertEquals('pending review', $body['inactive_reason']);
    }

    // -----------------------------------------------------------------
    // Inactive team: HTML request
    // -----------------------------------------------------------------

    public function test_redirects_with_warning_when_team_inactive_and_html_request(): void
    {
        $user = User::factory()->create();
        $team = TeamFactory::new()->inactive()->create(['user_id' => $user->id]);
        $this->attachUserToTeam($user, $team);

        $user->update(['current_team_id' => $team->id]);
        $user->refresh();

        $request = $this->buildRequest('/dashboard', $user, 'html');

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertTrue($response->isRedirection());
        $this->assertStringContainsString('/dashboard', $response->headers->get('Location'));

        // Check that a 'warning' flash message was set
        $session = $response->getSession();
        $this->assertNotNull($session->get('warning'));
    }

    // -----------------------------------------------------------------
    // Active team: passes through
    // -----------------------------------------------------------------

    public function test_passes_through_when_team_is_active(): void
    {
        $user = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $user->id]); // active by default
        $this->attachUserToTeam($user, $team);

        $user->update(['current_team_id' => $team->id]);
        $user->refresh();

        $request = $this->buildRequest('/dashboard', $user);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getContent());
    }

    // -----------------------------------------------------------------
    // User has no team: passes through
    // -----------------------------------------------------------------

    public function test_passes_through_when_user_has_no_team(): void
    {
        $user = User::factory()->create();

        $request = $this->buildRequest('/dashboard', $user);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getContent());
    }

    // -----------------------------------------------------------------
    // Fallback to first team when no currentTeam set
    // -----------------------------------------------------------------

    public function test_uses_first_team_when_no_current_team_set(): void
    {
        $user = User::factory()->create();
        $team = TeamFactory::new()->inactive('under review')->create(['user_id' => $user->id]);
        $this->attachUserToTeam($user, $team);

        // Do NOT set current_team_id -- middleware should fall back to teams->first()
        $request = $this->buildRequest('/dashboard', $user, 'json');

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(403, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);
        $this->assertTrue($body['waitlisted']);
    }

    // -----------------------------------------------------------------
    // Inactive team with null inactive_reason
    // -----------------------------------------------------------------

    public function test_returns_403_with_null_inactive_reason(): void
    {
        $user = User::factory()->create();
        $team = TeamFactory::new()->inactive()->create(['user_id' => $user->id]);
        $this->attachUserToTeam($user, $team);

        $user->update(['current_team_id' => $team->id]);
        $user->refresh();

        $request = $this->buildRequest('/dashboard', $user, 'json');

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(403, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);
        $this->assertTrue($body['waitlisted']);
        $this->assertNull($body['inactive_reason']);
    }
}
