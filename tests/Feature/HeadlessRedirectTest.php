<?php

namespace Ssntpl\Neev\Tests\Feature;

use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Ssntpl\Neev\Http\Middleware\EnsureEmailIsVerified;
use Ssntpl\Neev\Http\Middleware\EnsureTeamIsActive;
use Ssntpl\Neev\Http\Middleware\NeevMiddleware;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\EmailLinks;
use Ssntpl\Neev\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * A headless install registers no Blade page routes, so anything that sends a
 * browser to the sign-in or verify page has to ask EmailLinks where those live
 * rather than naming a route. These cover the callers in that mode; the URLs
 * themselves are pinned in EmailLinksTest.
 */
class HeadlessRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('neev.ui', null);
        $app['config']->set('neev.team', true);
    }

    protected function loginUrl(): string
    {
        return app(EmailLinks::class)->loginUrl();
    }

    protected function browserRequest(?User $user = null): Request
    {
        $request = Request::create('/dashboard');
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession($this->app['session.store']);

        return $request;
    }

    protected function passThrough(): Closure
    {
        return fn (Request $request) => new Response('OK', 200);
    }

    // -----------------------------------------------------------------
    // Middleware
    // -----------------------------------------------------------------

    public function test_neev_middleware_sends_an_unauthenticated_browser_to_the_login_url(): void
    {
        $response = (new NeevMiddleware())->handle($this->browserRequest(), $this->passThrough(), 'web');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame($this->loginUrl(), $response->headers->get('Location'));
    }

    public function test_email_verification_middleware_sends_a_browser_to_the_verify_url(): void
    {
        $user = User::factory()->unverified()->create();

        $response = (new EnsureEmailIsVerified())->handle($this->browserRequest($user), $this->passThrough());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(app(EmailLinks::class)->verifyEmailUrl(), $response->headers->get('Location'));
    }

    public function test_team_middleware_sends_an_unauthenticated_browser_to_the_login_url(): void
    {
        $response = (new EnsureTeamIsActive())->handle($this->browserRequest(), $this->passThrough());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame($this->loginUrl(), $response->headers->get('Location'));
    }

    // -----------------------------------------------------------------
    // OAuth callback
    // -----------------------------------------------------------------

    public function test_oauth_callback_without_a_code_lands_on_the_login_url(): void
    {
        config(['neev.oauth' => ['google']]);

        $this->get('/neev/oauth/google/callback')
            ->assertRedirect($this->loginUrl());
    }
}
