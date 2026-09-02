<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Ssntpl\Neev\Http\Middleware\TenantMiddleware;
use Ssntpl\Neev\Services\EmailLinks;
use Ssntpl\Neev\Tests\TestCase;

/**
 * SSO is driven entirely by the tenant's own auth settings, so every route in
 * the flow — including the public auth-config lookup an SPA reads before it
 * shows a sign-in button — has to resolve a tenant before the controller runs.
 */
class SsoRoutesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, string> The middleware a named route runs through. */
    protected function middlewareOf(string $name): array
    {
        return Route::getRoutes()->getByName($name)->gatherMiddleware();
    }

    public function test_every_sso_route_resolves_the_tenant_first(): void
    {
        foreach (['sso.redirect', 'sso.callback', 'api.tenant.auth'] as $name) {
            $this->assertContains(
                TenantMiddleware::class,
                $this->middlewareOf($name),
                "Route [{$name}] should resolve the tenant."
            );
        }
    }

    public function test_the_browser_facing_sso_routes_still_run_the_web_stack(): void
    {
        foreach (['sso.redirect', 'sso.callback'] as $name) {
            $this->assertContains('web', $this->middlewareOf($name));
        }
    }

    public function test_a_callback_without_a_code_lands_on_the_login_url(): void
    {
        $this->get(route('sso.callback'))
            ->assertRedirect(app(EmailLinks::class)->loginUrl());
    }
}
