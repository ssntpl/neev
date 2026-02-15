<?php

namespace Ssntpl\Neev\Tests\Unit\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Mockery;
use Ssntpl\Neev\Database\Factories\LoginAttemptFactory;
use Ssntpl\Neev\Events\LoggedInEvent;
use Ssntpl\Neev\Models\LoginAttempt;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\AuthService;
use Ssntpl\Neev\Services\GeoIP;
use Ssntpl\Neev\Tests\TestCase;

class AuthServiceTest extends TestCase
{
    use RefreshDatabase;

    private AuthService $authService;
    private GeoIP $geoIP;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authService = new AuthService();
        $this->geoIP = Mockery::mock(GeoIP::class);
        $this->geoIP->shouldReceive('getLocation')->andReturn(null);
    }

    /**
     * Build a Request with a proper Laravel session attached.
     */
    private function makeRequestWithSession(): Request
    {
        $request = Request::create('/login', 'POST');
        $session = new Store('test', new ArraySessionHandler(120));
        $session->start();
        $request->setLaravelSession($session);

        return $request;
    }

    // ---------------------------------------------------------------
    // Inactive / null user
    // ---------------------------------------------------------------

    public function test_throws_validation_exception_for_inactive_user(): void
    {
        $user = User::factory()->inactive()->create();

        $this->expectException(ValidationException::class);

        $this->authService->login(
            $this->makeRequestWithSession(),
            $this->geoIP,
            $user,
            LoginAttempt::Password
        );
    }

    public function test_throws_validation_exception_for_null_user(): void
    {
        $this->expectException(ValidationException::class);

        $this->authService->login(
            $this->makeRequestWithSession(),
            $this->geoIP,
            null,
            LoginAttempt::Password
        );
    }

    public function test_validation_exception_message_mentions_deactivated(): void
    {
        $user = User::factory()->inactive()->create();

        try {
            $this->authService->login(
                $this->makeRequestWithSession(),
                $this->geoIP,
                $user,
                LoginAttempt::Password
            );
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
            $this->assertStringContainsString('deactivated', $e->errors()['email'][0]);
        }
    }

    // ---------------------------------------------------------------
    // Successful login -- Auth::login
    // ---------------------------------------------------------------

    public function test_calls_auth_login_for_normal_login(): void
    {
        Event::fake();

        $user = User::factory()->create();
        $request = $this->makeRequestWithSession();

        Auth::shouldReceive('login')
            ->once()
            ->with($user, false);

        $this->authService->login(
            $request,
            $this->geoIP,
            $user,
            LoginAttempt::Password
        );
    }

    // ---------------------------------------------------------------
    // Event dispatching
    // ---------------------------------------------------------------

    public function test_dispatches_logged_in_event(): void
    {
        Event::fake([LoggedInEvent::class]);

        $user = User::factory()->create();

        $this->authService->login(
            $this->makeRequestWithSession(),
            $this->geoIP,
            $user,
            LoginAttempt::Password
        );

        Event::assertDispatched(LoggedInEvent::class, function (LoggedInEvent $event) use ($user) {
            return $event->user->id === $user->id;
        });
    }

    // ---------------------------------------------------------------
    // LoginAttempt creation
    // ---------------------------------------------------------------

    public function test_creates_login_attempt_record(): void
    {
        Event::fake();

        $user = User::factory()->create();

        $this->authService->login(
            $this->makeRequestWithSession(),
            $this->geoIP,
            $user,
            LoginAttempt::Password
        );

        $this->assertDatabaseHas('login_attempts', [
            'user_id' => $user->id,
            'method' => LoginAttempt::Password,
            'is_success' => true,
        ]);
    }

    public function test_creates_login_attempt_with_correct_method(): void
    {
        Event::fake();

        $user = User::factory()->create();

        $this->authService->login(
            $this->makeRequestWithSession(),
            $this->geoIP,
            $user,
            LoginAttempt::Passkey
        );

        $this->assertDatabaseHas('login_attempts', [
            'user_id' => $user->id,
            'method' => LoginAttempt::Passkey,
        ]);
    }

    public function test_creates_login_attempt_with_mfa_method(): void
    {
        Event::fake();

        $user = User::factory()->create();

        $this->authService->login(
            $this->makeRequestWithSession(),
            $this->geoIP,
            $user,
            LoginAttempt::Password,
            'authenticator'
        );

        $this->assertDatabaseHas('login_attempts', [
            'user_id' => $user->id,
            'method' => LoginAttempt::Password,
            'multi_factor_method' => 'authenticator',
        ]);
    }

    // ---------------------------------------------------------------
    // Updating existing attempt
    // ---------------------------------------------------------------

    public function test_updates_existing_attempt_if_provided(): void
    {
        Event::fake();

        $user = User::factory()->create();

        $attempt = LoginAttemptFactory::new()->failed()->create([
            'user_id' => $user->id,
            'method' => LoginAttempt::Password,
        ]);

        $this->assertFalse($attempt->is_success);

        $this->authService->login(
            $this->makeRequestWithSession(),
            $this->geoIP,
            $user,
            LoginAttempt::Password,
            null,
            $attempt
        );

        $attempt->refresh();
        $this->assertTrue($attempt->is_success);
    }

    public function test_does_not_create_new_attempt_when_existing_provided(): void
    {
        Event::fake();

        $user = User::factory()->create();

        $attempt = LoginAttemptFactory::new()->failed()->create([
            'user_id' => $user->id,
            'method' => LoginAttempt::Password,
        ]);

        $countBefore = LoginAttempt::count();

        $this->authService->login(
            $this->makeRequestWithSession(),
            $this->geoIP,
            $user,
            LoginAttempt::Password,
            null,
            $attempt
        );

        $this->assertSame($countBefore, LoginAttempt::count());
    }

    // ---------------------------------------------------------------
    // Session -- attempt_id
    // ---------------------------------------------------------------

    public function test_stores_attempt_id_in_session(): void
    {
        Event::fake();

        $user = User::factory()->create();
        $request = $this->makeRequestWithSession();

        $this->authService->login(
            $request,
            $this->geoIP,
            $user,
            LoginAttempt::Password
        );

        // The AuthService uses the global session() helper, so check there
        $attemptId = session('attempt_id');
        $this->assertNotNull($attemptId);

        $this->assertDatabaseHas('login_attempts', [
            'id' => $attemptId,
            'user_id' => $user->id,
        ]);
    }

    public function test_stores_existing_attempt_id_in_session(): void
    {
        Event::fake();

        $user = User::factory()->create();

        $attempt = LoginAttemptFactory::new()->failed()->create([
            'user_id' => $user->id,
        ]);

        $request = $this->makeRequestWithSession();

        $this->authService->login(
            $request,
            $this->geoIP,
            $user,
            LoginAttempt::Password,
            null,
            $attempt
        );

        // The AuthService uses the global session() helper, so check there
        $this->assertSame($attempt->id, session('attempt_id'));
    }

    // ---------------------------------------------------------------
    // Session regeneration
    // ---------------------------------------------------------------

    public function test_regenerates_session_on_login(): void
    {
        Event::fake();

        $user = User::factory()->create();
        $request = $this->makeRequestWithSession();

        $sessionBefore = $request->session()->getId();

        $this->authService->login(
            $request,
            $this->geoIP,
            $user,
            LoginAttempt::Password
        );

        // After regeneration the session ID changes
        $sessionAfter = $request->session()->getId();
        $this->assertNotSame($sessionBefore, $sessionAfter);
    }
}
