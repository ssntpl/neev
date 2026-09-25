<?php

namespace Ssntpl\Neev\Tests\Unit\Middleware;

use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Ssntpl\Neev\Database\Factories\LoginAttemptFactory;
use Ssntpl\Neev\Database\Factories\MultiFactorAuthFactory;
use Ssntpl\Neev\Http\Middleware\NeevMiddleware;
use Ssntpl\Neev\Models\LoginAttempt;
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

    /**
     * A guest has no sign-in to end, so turning it away must not throw away
     * the rest of its session.
     */
    public function test_a_guest_keeps_its_session_when_turned_away(): void
    {
        session(['locale' => 'fr']);
        $token = session()->token();

        $request = $this->buildRequest();

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertLocationContains('/login', $response);
        $this->assertSame('fr', session('locale'));
        $this->assertSame($token, session()->token());
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

    public function test_redirects_to_mfa_form_when_the_attempt_has_not_succeeded_yet(): void
    {
        $user = User::factory()->create();

        MultiFactorAuthFactory::new()->create([
            'user_id' => $user->id,
            'method' => 'authenticator',
            'preferred' => true,
        ]);

        $attempt = LoginAttemptFactory::new()->create([
            'user_id' => $user->id,
            'multi_factor_method' => 'authenticator',
            'is_success' => false,
        ]);

        $request = $this->buildRequest('/test', $user);
        session(['attempt_id' => $attempt->id]);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertTrue($response->isRedirection());
        $this->assertLocationContains('/mfa/', $response);
    }

    // -----------------------------------------------------------------
    // MFA: the login method itself carried the second factor
    // -----------------------------------------------------------------

    /**
     * A passkey is verified with `userVerification: 'required'`, so the
     * ceremony already proves possession of the authenticator plus a local
     * user check. Asking for a second factor on top of it is asking the same
     * question twice.
     */
    public function test_passes_through_when_user_has_mfa_and_logged_in_with_a_passkey(): void
    {
        $user = User::factory()->create();

        MultiFactorAuthFactory::new()->create([
            'user_id' => $user->id,
            'method' => 'authenticator',
            'preferred' => true,
        ]);

        $attempt = LoginAttemptFactory::new()->create([
            'user_id' => $user->id,
            'method' => LoginAttempt::Passkey,
            'multi_factor_method' => null,
        ]);

        $request = $this->buildRequest('/test', $user);
        session(['attempt_id' => $attempt->id]);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getContent());
    }

    /**
     * Either signal closes the gate, but they close it differently. A
     * *successful* login with no `multi_factor_method` completed before the
     * factor existed - the account enrolled from somewhere else while this
     * session was open. There is no challenge in flight for it: the challenge
     * page reads the account from `session('email')`, which only a login
     * parked at the challenge writes, so sending it there bounced to /login,
     * which bounced an authenticated user back to the home page, which landed
     * here again. It is unauthenticated instead, session and all.
     */
    public function test_signs_out_a_session_that_predates_the_enrolment(): void
    {
        $user = User::factory()->create();

        MultiFactorAuthFactory::new()->create([
            'user_id' => $user->id,
            'method' => 'authenticator',
            'preferred' => true,
        ]);

        $attempt = LoginAttemptFactory::new()->create([
            'user_id' => $user->id,
            'method' => LoginAttempt::Password,
            'multi_factor_method' => null,
            'is_success' => true,
        ]);

        Auth::login($user);
        $request = $this->buildRequest('/test', $user);
        session(['attempt_id' => $attempt->id]);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertTrue($response->isRedirection());
        $this->assertLocationContains('/login', $response);
        $this->assertGuest();
        $this->assertNull(session('attempt_id'));
    }

    /** The mirror case: a factor named, but the login never completed. */
    public function test_redirects_to_mfa_form_when_a_named_factor_was_never_supplied(): void
    {
        $user = User::factory()->create();

        MultiFactorAuthFactory::new()->create([
            'user_id' => $user->id,
            'method' => 'authenticator',
            'preferred' => true,
        ]);

        $attempt = LoginAttemptFactory::new()->create([
            'user_id' => $user->id,
            'method' => LoginAttempt::Password,
            'multi_factor_method' => 'authenticator',
            'is_success' => false,
        ]);

        $request = $this->buildRequest('/test', $user);
        session(['attempt_id' => $attempt->id]);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertTrue($response->isRedirection());
        $this->assertLocationContains('/mfa/', $response);
    }

    /**
     * Tenant/team SSO hands the organization's identity provider the
     * authentication policy for its own members. The API side has always
     * treated it that way; the web side used to challenge it and then strand
     * it, because the SSO callback leaves no `session('email')` for the
     * challenge page to work with.
     */
    public function test_passes_through_when_user_has_mfa_and_logged_in_via_tenant_sso(): void
    {
        $user = User::factory()->create();

        MultiFactorAuthFactory::new()->create([
            'user_id' => $user->id,
            'method' => 'authenticator',
            'preferred' => true,
        ]);

        $attempt = LoginAttemptFactory::new()->create([
            'user_id' => $user->id,
            'method' => LoginAttempt::SSO,
            'multi_factor_method' => null,
        ]);

        $request = $this->buildRequest('/test', $user);
        session(['attempt_id' => $attempt->id]);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getContent());
    }

    /**
     * A federated login proves nothing about a second factor — whether the
     * provider asked for one is invisible here — so it is challenged like any
     * other single factor.
     */
    public function test_redirects_to_mfa_form_when_user_has_mfa_and_logged_in_with_oauth(): void
    {
        $user = User::factory()->create();

        MultiFactorAuthFactory::new()->create([
            'user_id' => $user->id,
            'method' => 'authenticator',
            'preferred' => true,
        ]);

        $attempt = LoginAttemptFactory::new()->create([
            'user_id' => $user->id,
            'method' => LoginAttempt::OAuthPrefix . 'google',
            'multi_factor_method' => 'authenticator',
            'is_success' => false,
        ]);

        $request = $this->buildRequest('/test', $user);
        session(['attempt_id' => $attempt->id]);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertTrue($response->isRedirection());
        $this->assertLocationContains('/mfa/', $response);
    }

    /**
     * The SSO exemption is read off `method` by value, and an OAuth provider
     * is named by config — so a provider called "sso" would once have
     * inherited it and walked past the challenge. The namespaced form has no
     * way to collide with a built-in name.
     */
    public function test_redirects_to_mfa_form_for_an_oauth_provider_named_after_a_built_in_method(): void
    {
        config(['neev.oauth' => ['sso']]);

        $user = User::factory()->create();

        MultiFactorAuthFactory::new()->create([
            'user_id' => $user->id,
            'method' => 'authenticator',
            'preferred' => true,
        ]);

        $attempt = LoginAttemptFactory::new()->create([
            'user_id' => $user->id,
            'method' => LoginAttempt::OAuthPrefix . 'sso',
            'multi_factor_method' => 'authenticator',
            'is_success' => false,
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

        Auth::login($user);
        $request = $this->buildRequest('/test', $user);
        // No attempt_id in session

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertTrue($response->isRedirection());
        $this->assertLocationContains('/login', $response);
        // Redirecting while still signed in is what loops: /login sends an
        // authenticated user straight back to a page this gate refuses.
        $this->assertGuest();
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

    // Email verification is handled by the separate EnsureEmailIsVerified middleware

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
        $user->forceFill(['email_verified_at' => null])->save();
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
