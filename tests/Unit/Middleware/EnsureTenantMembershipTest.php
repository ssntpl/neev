<?php

namespace Ssntpl\Neev\Tests\Unit\Middleware;

use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Http\Middleware\EnsureTenantMembership;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\TenantResolver;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantMembershipTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    private EnsureTenantMembership $middleware;
    private TenantResolver $tenantResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantResolver = new TenantResolver();
        $this->middleware = new EnsureTenantMembership($this->tenantResolver);

        Route::get('/login', fn () => 'login')->name('login');
    }

    /**
     * Build a request with optional user and format.
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
    // Tenant isolation disabled
    // -----------------------------------------------------------------

    public function test_passes_through_when_tenant_isolation_disabled(): void
    {
        config(['neev.tenant_isolation' => false]);

        $user = User::factory()->create();
        $request = $this->buildRequest('/dashboard', $user);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // No user
    // -----------------------------------------------------------------

    public function test_passes_through_when_no_user(): void
    {
        $this->enableTenantIsolation();

        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $this->tenantResolver->setCurrentTenant($team);

        $request = $this->buildRequest('/dashboard', null);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // No tenant
    // -----------------------------------------------------------------

    public function test_passes_through_when_no_tenant(): void
    {
        $this->enableTenantIsolation();

        $user = User::factory()->create();
        // Do NOT set a current tenant on the resolver

        $request = $this->buildRequest('/dashboard', $user);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // User is NOT a member: JSON request
    // -----------------------------------------------------------------

    public function test_returns_403_json_when_user_is_not_a_member_json_request(): void
    {
        $this->enableTenantIsolation();

        $user = User::factory()->create();
        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $this->tenantResolver->setCurrentTenant($team);

        // User is NOT attached to the team

        // Log the user in via Auth so logout() works
        Auth::login($user);

        $request = $this->buildRequest('/dashboard', $user, 'json');

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(403, $response->getStatusCode());

        // User should be logged out
        $this->assertNull(Auth::user());
    }

    // -----------------------------------------------------------------
    // User is NOT a member: HTML request
    // -----------------------------------------------------------------

    public function test_redirects_to_login_when_user_is_not_a_member_html_request(): void
    {
        $this->enableTenantIsolation();

        $user = User::factory()->create();
        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $this->tenantResolver->setCurrentTenant($team);

        Auth::login($user);

        $request = $this->buildRequest('/dashboard', $user, 'html');

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertTrue($response->isRedirection());
        $this->assertStringContainsString('/login', $response->headers->get('Location'));

        // User should be logged out
        $this->assertNull(Auth::user());
    }

    // -----------------------------------------------------------------
    // Logs out user and invalidates session when not a member
    // -----------------------------------------------------------------

    public function test_logs_out_user_and_invalidates_session_when_not_a_member(): void
    {
        $this->enableTenantIsolation();

        $user = User::factory()->create();
        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $this->tenantResolver->setCurrentTenant($team);

        Auth::login($user);
        $this->assertNotNull(Auth::user());

        $request = $this->buildRequest('/dashboard', $user, 'json');

        // Capture the session token before the middleware runs
        $sessionBefore = $request->session()->token();

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(403, $response->getStatusCode());

        // Auth should be logged out
        $this->assertNull(Auth::user());

        // Session should have been invalidated and regenerated (new token)
        $sessionAfter = $request->session()->token();
        $this->assertNotEquals($sessionBefore, $sessionAfter);
    }

    // -----------------------------------------------------------------
    // User belongs to tenant: passes through
    // -----------------------------------------------------------------

    public function test_passes_through_when_user_belongs_to_tenant(): void
    {
        $this->enableTenantIsolation();

        $user = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);
        $this->attachUserToTeam($user, $team);
        $this->tenantResolver->setCurrentTenant($team);

        $request = $this->buildRequest('/dashboard', $user);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getContent());
    }

    // -----------------------------------------------------------------
    // Both user and tenant null: passes through
    // -----------------------------------------------------------------

    public function test_passes_through_when_both_user_and_tenant_are_null(): void
    {
        $this->enableTenantIsolation();

        // No user, no tenant
        $request = $this->buildRequest('/dashboard', null);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Error message content
    // -----------------------------------------------------------------

    public function test_json_error_message_contains_not_member_text(): void
    {
        $this->enableTenantIsolation();

        $user = User::factory()->create();
        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $this->tenantResolver->setCurrentTenant($team);

        Auth::login($user);

        $request = $this->buildRequest('/dashboard', $user, 'json');

        $response = $this->middleware->handle($request, $this->passThrough());

        $body = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('message', $body);
        // The message comes from the neev::auth.not_member translation key
        $this->assertNotEmpty($body['message']);
    }

    // -----------------------------------------------------------------
    // HTML error: redirect has 'tenant' error bag
    // -----------------------------------------------------------------

    public function test_html_redirect_has_tenant_error_key(): void
    {
        $this->enableTenantIsolation();

        $user = User::factory()->create();
        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $this->tenantResolver->setCurrentTenant($team);

        Auth::login($user);

        $request = $this->buildRequest('/dashboard', $user, 'html');

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertTrue($response->isRedirection());

        // The response should flash errors with a 'tenant' key
        $session = $response->getSession();
        $errors = $session->get('errors');
        $this->assertNotNull($errors);
        $this->assertTrue($errors->has('tenant'));
    }
}
