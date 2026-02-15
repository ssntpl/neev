<?php

namespace Ssntpl\Neev\Tests\Unit\Middleware;

use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Ssntpl\Neev\Database\Factories\LoginAttemptFactory;
use Ssntpl\Neev\Database\Factories\MultiFactorAuthFactory;
use Ssntpl\Neev\Http\Middleware\NeevMiddleware;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;
use Symfony\Component\HttpFoundation\Response;

class NeevMiddlewareTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    private NeevMiddleware $middleware;

    protected function setUp(): void
    {
        parent::setUp();

        $this->middleware = new NeevMiddleware();

        Route::get('/login', fn () => 'login')->name('login');
        Route::get('/mfa/{method}', fn () => 'mfa')->name('otp.mfa.create');
        Route::get('/verify', fn () => 'verify')->name('verification.notice');
    }

    /**
     * Build a request with an optional authenticated user and session.
     */
    private function buildRequest(string $path = '/test', ?User $user = null): Request
    {
        $request = Request::create($path);

        if ($user) {
            $request->setUserResolver(fn () => $user);
        }

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

    // -----------------------------------------------------------------
    // No user / unauthenticated
    // -----------------------------------------------------------------

    public function test_redirects_to_login_when_no_user_authenticated(): void
    {
        $request = $this->buildRequest();

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertTrue($response->isRedirection());
        $this->assertLocationContains('/login', $response);
    }

    // -----------------------------------------------------------------
    // Inactive user
    // -----------------------------------------------------------------

    public function test_redirects_to_login_with_error_when_user_is_inactive(): void
    {
        $user = User::factory()->inactive()->create();

        $request = $this->buildRequest('/test', $user);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertTrue($response->isRedirection());
        $this->assertLocationContains('/login', $response);

        // The response should have errors flashed to the session
        $session = $response->getSession();
        $this->assertNotEmpty($session->get('errors'));
    }

    // -----------------------------------------------------------------
    // MFA: user has MFA, attempt exists but no multi_factor_method
    // -----------------------------------------------------------------

    public function test_redirects_to_mfa_form_when_user_has_mfa_and_attempt_has_no_multi_factor_method(): void
    {
        $user = User::factory()->create();

        MultiFactorAuthFactory::new()->create([
            'user_id' => $user->id,
            'method' => 'authenticator',
            'preferred' => true,
        ]);

        $attempt = LoginAttemptFactory::new()->create([
            'user_id' => $user->id,
            'multi_factor_method' => null,
        ]);

        $request = $this->buildRequest('/test', $user);
        session(['attempt_id' => $attempt->id]);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertTrue($response->isRedirection());
        $this->assertLocationContains('/mfa/', $response);
    }

    // -----------------------------------------------------------------
    // MFA: user has MFA but no attempt in session
    // -----------------------------------------------------------------

    public function test_redirects_to_login_when_user_has_mfa_but_no_attempt_in_session(): void
    {
        $user = User::factory()->create();

        MultiFactorAuthFactory::new()->create([
            'user_id' => $user->id,
            'method' => 'authenticator',
        ]);

        $request = $this->buildRequest('/test', $user);
        // No attempt_id in session

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertTrue($response->isRedirection());
        $this->assertLocationContains('/login', $response);
    }

    // -----------------------------------------------------------------
    // MFA: user has MFA and attempt has multi_factor_method set
    // -----------------------------------------------------------------

    public function test_passes_through_when_user_has_mfa_and_attempt_has_multi_factor_method(): void
    {
        $user = User::factory()->create();

        MultiFactorAuthFactory::new()->create([
            'user_id' => $user->id,
            'method' => 'authenticator',
        ]);

        $attempt = LoginAttemptFactory::new()->withMFA('authenticator')->create([
            'user_id' => $user->id,
        ]);

        $request = $this->buildRequest('/test', $user);
        session(['attempt_id' => $attempt->id]);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getContent());
    }

    // -----------------------------------------------------------------
    // Email verification: redirects when email not verified
    // -----------------------------------------------------------------

    public function test_redirects_to_verification_notice_when_email_not_verified(): void
    {
        $this->enableEmailVerification();

        $user = User::factory()->create();
        $user->email->update(['verified_at' => null]);
        $user->refresh();

        $request = $this->buildRequest('/dashboard', $user);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertTrue($response->isRedirection());
        $this->assertLocationContains('/verify', $response);
    }

    // -----------------------------------------------------------------
    // Email verification: does NOT redirect for bypass paths
    // -----------------------------------------------------------------

    public function test_does_not_redirect_for_email_verify_bypass_path(): void
    {
        $this->enableEmailVerification();

        $user = User::factory()->create();
        $user->email->update(['verified_at' => null]);
        $user->refresh();

        $request = $this->buildRequest('/email/verify', $user);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_does_not_redirect_for_email_send_bypass_path(): void
    {
        $this->enableEmailVerification();

        $user = User::factory()->create();
        $user->email->update(['verified_at' => null]);
        $user->refresh();

        $request = $this->buildRequest('/email/send', $user);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_does_not_redirect_for_logout_bypass_path(): void
    {
        $this->enableEmailVerification();

        $user = User::factory()->create();
        $user->email->update(['verified_at' => null]);
        $user->refresh();

        $request = $this->buildRequest('/logout', $user);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_does_not_redirect_for_email_change_bypass_path(): void
    {
        $this->enableEmailVerification();

        $user = User::factory()->create();
        $user->email->update(['verified_at' => null]);
        $user->refresh();

        $request = $this->buildRequest('/email/change', $user);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_does_not_redirect_for_email_update_bypass_path(): void
    {
        $this->enableEmailVerification();

        $user = User::factory()->create();
        $user->email->update(['verified_at' => null]);
        $user->refresh();

        $request = $this->buildRequest('/email/update', $user);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Email verification: does not redirect when disabled
    // -----------------------------------------------------------------

    public function test_does_not_redirect_when_email_verification_is_disabled(): void
    {
        config(['neev.email_verified' => false]);

        $user = User::factory()->create();
        $user->email->update(['verified_at' => null]);
        $user->refresh();

        $request = $this->buildRequest('/dashboard', $user);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Happy path: active user, no MFA, verified email
    // -----------------------------------------------------------------

    public function test_passes_through_for_active_user_with_no_mfa_and_verified_email(): void
    {
        $user = User::factory()->create();

        $attempt = LoginAttemptFactory::new()->create(['user_id' => $user->id]);

        $request = $this->buildRequest('/dashboard', $user);
        session(['attempt_id' => $attempt->id]);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getContent());
    }

    // -----------------------------------------------------------------
    // Happy path: active user, no MFA, no attempt (and no MFA configured)
    // -----------------------------------------------------------------

    public function test_passes_through_for_active_user_with_no_mfa_and_no_attempt(): void
    {
        $user = User::factory()->create();

        $request = $this->buildRequest('/dashboard', $user);
        // No attempt_id in session, but also no MFA configured

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getContent());
    }

    // -----------------------------------------------------------------
    // Wildcard bypass path: email/verify/123 matches email/verify*
    // -----------------------------------------------------------------

    public function test_does_not_redirect_for_email_verify_wildcard_path(): void
    {
        $this->enableEmailVerification();

        $user = User::factory()->create();
        $user->email->update(['verified_at' => null]);
        $user->refresh();

        $request = $this->buildRequest('/email/verify/123', $user);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Helper assertion
    // -----------------------------------------------------------------

    private function assertLocationContains(string $needle, Response $response): void
    {
        $location = $response->headers->get('Location');
        $this->assertNotNull($location, "Expected redirect Location header, got null.");
        $this->assertStringContainsString($needle, $location);
    }
}
