<?php

namespace Ssntpl\Neev\Tests\Unit\Middleware;

use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Mockery;
use Ssntpl\Neev\Contracts\ContextContainerInterface;
use Ssntpl\Neev\Contracts\IdentityProviderOwnerInterface;
use Ssntpl\Neev\Http\Middleware\EnsureContextSSO;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\TenantResolver;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;
use Symfony\Component\HttpFoundation\Response;

class EnsureContextSSOTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    private TenantResolver $tenantResolver;
    private EnsureContextSSO $middleware;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('neev.tenant_auth', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantResolver = app(TenantResolver::class);
        $this->middleware = new EnsureContextSSO($this->tenantResolver);
    }

    private function passThrough(): Closure
    {
        return fn (Request $req): Response => response()->json(['message' => 'OK'], 200);
    }

    private function createAuthenticatedRequest(): Request
    {
        $user = User::factory()->create();
        $request = Request::create('/test');
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    // -----------------------------------------------------------------
    // Passes through when tenant_auth disabled
    // -----------------------------------------------------------------

    public function test_passes_through_when_tenant_auth_disabled(): void
    {
        config(['neev.tenant_auth' => false]);

        $request = $this->createAuthenticatedRequest();

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Passes through when no context resolved
    // -----------------------------------------------------------------

    public function test_passes_through_when_no_context(): void
    {
        config(['neev.tenant_auth' => true]);

        $request = $this->createAuthenticatedRequest();

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Passes through when context does not implement IdentityProviderOwnerInterface
    // -----------------------------------------------------------------

    public function test_passes_through_when_context_not_identity_provider(): void
    {
        config(['neev.tenant_auth' => true]);

        $context = Mockery::mock(ContextContainerInterface::class);
        $context->shouldReceive('getContextType')->andReturn('team');
        $context->shouldReceive('getContextId')->andReturn(1);
        $context->shouldReceive('getContextSlug')->andReturn('test');

        $resolver = Mockery::mock(TenantResolver::class);
        $resolver->shouldReceive('resolvedContext')->andReturn($context);
        $middleware = new EnsureContextSSO($resolver);

        $request = $this->createAuthenticatedRequest();

        $response = $middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Passes through when context does not require SSO
    // -----------------------------------------------------------------

    public function test_passes_through_when_sso_not_required(): void
    {
        config(['neev.tenant_auth' => true]);

        $context = Mockery::mock(ContextContainerInterface::class, IdentityProviderOwnerInterface::class);
        $context->shouldReceive('requiresSSO')->andReturn(false);

        $resolver = Mockery::mock(TenantResolver::class);
        $resolver->shouldReceive('resolvedContext')->andReturn($context);
        $middleware = new EnsureContextSSO($resolver);

        $request = $this->createAuthenticatedRequest();

        $response = $middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Passes through when SSO required but not configured
    // -----------------------------------------------------------------

    public function test_passes_through_when_sso_required_but_not_configured(): void
    {
        config(['neev.tenant_auth' => true]);

        $context = Mockery::mock(ContextContainerInterface::class, IdentityProviderOwnerInterface::class);
        $context->shouldReceive('requiresSSO')->andReturn(true);
        $context->shouldReceive('hasSSOConfigured')->andReturn(false);

        $resolver = Mockery::mock(TenantResolver::class);
        $resolver->shouldReceive('resolvedContext')->andReturn($context);
        $middleware = new EnsureContextSSO($resolver);

        $request = $this->createAuthenticatedRequest();

        $response = $middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Passes through when no user is authenticated
    // -----------------------------------------------------------------

    public function test_passes_through_when_no_user(): void
    {
        config(['neev.tenant_auth' => true]);

        $context = Mockery::mock(ContextContainerInterface::class, IdentityProviderOwnerInterface::class);
        $context->shouldReceive('requiresSSO')->andReturn(true);
        $context->shouldReceive('hasSSOConfigured')->andReturn(true);

        $resolver = Mockery::mock(TenantResolver::class);
        $resolver->shouldReceive('resolvedContext')->andReturn($context);
        $middleware = new EnsureContextSSO($resolver);

        $request = Request::create('/test');

        $response = $middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Passes through when user authenticated via SSO (session flag)
    // -----------------------------------------------------------------

    public function test_passes_through_when_session_has_sso_auth_method(): void
    {
        config(['neev.tenant_auth' => true]);

        $context = Mockery::mock(ContextContainerInterface::class, IdentityProviderOwnerInterface::class);
        $context->shouldReceive('requiresSSO')->andReturn(true);
        $context->shouldReceive('hasSSOConfigured')->andReturn(true);

        $resolver = Mockery::mock(TenantResolver::class);
        $resolver->shouldReceive('resolvedContext')->andReturn($context);
        $middleware = new EnsureContextSSO($resolver);

        $user = User::factory()->create();
        $request = Request::create('/test');
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession(app('session.store'));
        $request->session()->put('auth_method', 'sso');

        $response = $middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Redirects when SSO required but user did not authenticate via SSO
    // -----------------------------------------------------------------

    public function test_redirects_to_sso_when_required_and_not_sso_authenticated(): void
    {
        config(['neev.tenant_auth' => true]);

        $context = Mockery::mock(ContextContainerInterface::class, IdentityProviderOwnerInterface::class);
        $context->shouldReceive('requiresSSO')->andReturn(true);
        $context->shouldReceive('hasSSOConfigured')->andReturn(true);

        $resolver = Mockery::mock(TenantResolver::class);
        $resolver->shouldReceive('resolvedContext')->andReturn($context);
        $middleware = new EnsureContextSSO($resolver);

        $user = User::factory()->create();
        $request = Request::create('/test');
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession(app('session.store'));

        $response = $middleware->handle($request, $this->passThrough());

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertStringContainsString('sso/redirect', $response->headers->get('Location'));
    }

    // -----------------------------------------------------------------
    // Returns JSON 403 for API requests when SSO required
    // -----------------------------------------------------------------

    public function test_returns_json_403_for_api_requests(): void
    {
        config(['neev.tenant_auth' => true]);

        $context = Mockery::mock(ContextContainerInterface::class, IdentityProviderOwnerInterface::class);
        $context->shouldReceive('requiresSSO')->andReturn(true);
        $context->shouldReceive('hasSSOConfigured')->andReturn(true);

        $resolver = Mockery::mock(TenantResolver::class);
        $resolver->shouldReceive('resolvedContext')->andReturn($context);
        $middleware = new EnsureContextSSO($resolver);

        $user = User::factory()->create();
        $request = Request::create('/test', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $request->setUserResolver(fn () => $user);

        $response = $middleware->handle($request, $this->passThrough());

        $this->assertEquals(403, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals('SSO authentication required for this organization.', $content['message']);
        $this->assertArrayHasKey('sso_redirect_url', $content);
    }
}
