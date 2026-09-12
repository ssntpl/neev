<?php

namespace Ssntpl\Neev\Tests\Unit\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Mockery;
use Ssntpl\Neev\Database\Factories\LoginAttemptFactory;
use Ssntpl\Neev\Events\LoggedIn;
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
        Event::fake([LoggedIn::class]);

        $user = User::factory()->create();

        $this->authService->login(
            $this->makeRequestWithSession(),
            $this->geoIP,
            $user,
            LoginAttempt::Password
        );

        Event::assertDispatched(LoggedIn::class, function (LoggedIn $event) use ($user) {
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

    // ---------------------------------------------------------------
    // Attempt handling — the $mfa / $attempt slots
    // ---------------------------------------------------------------

    /**
     * A login that already has an attempt row must settle that row rather than
     * open a second one. PasskeyController::loginViaWeb used to pass its
     * attempt positionally into the $mfa slot, which left the real attempt
     * orphaned as a failure, created a duplicate, and — because a non-null
     * multi_factor_method is what NeevMiddleware reads as proof the MFA
     * challenge was answered — quietly satisfied that gate.
     *
     * Nothing errored at the time because Eloquent's Model::__toString()
     * JSON-encodes, so the model coerced happily into the string slot. This
     * asserts the behaviour instead, which is the part that actually matters.
     */
    public function test_an_existing_attempt_is_settled_rather_than_duplicated(): void
    {
        $user = User::factory()->create();
        $attempt = $user->loginAttempts()->create([
            'method' => LoginAttempt::Passkey,
            'is_success' => false,
        ]);

        app(AuthService::class)->login(
            $this->makeRequestWithSession(),
            app(GeoIP::class),
            $user,
            LoginAttempt::Passkey,
            attempt: $attempt,
        );

        $rows = $user->loginAttempts()->get();

        $this->assertCount(1, $rows, 'A second attempt row means the passed attempt was ignored.');
        $this->assertTrue((bool) $rows->first()->is_success);
        $this->assertNull(
            $rows->first()->multi_factor_method,
            'A passkey login answers no MFA challenge, so it must not stamp the gate column.',
        );
    }

    // ---------------------------------------------------------------
    // Revocation on password change
    // ---------------------------------------------------------------

    private function createSessionsTable(): void
    {
        Schema::dropIfExists('sessions');
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    private function seedSession(string $id, ?int $userId): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'payload' => '',
            'last_activity' => time(),
        ]);
    }

    /**
     * The whole point of changing a password after a compromise is that the old
     * one stops working. Leaving every existing session signed in means it
     * does not.
     */
    public function test_changing_a_password_drops_the_accounts_other_sessions(): void
    {
        config(['session.driver' => 'database']);
        $this->createSessionsTable();

        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->seedSession('current-session', $user->id);
        $this->seedSession('stale-session', $user->id);
        $this->seedSession('somebody-elses', $other->id);

        $request = $this->makeRequestWithSession();
        $this->app['request'] = $request;

        app(AuthService::class)->changePassword($user, 'a-brand-new-password');

        // The other user's session is untouched; this user's stale one is gone.
        $this->assertDatabaseHas('sessions', ['id' => 'somebody-elses']);
        $this->assertDatabaseMissing('sessions', ['id' => 'stale-session']);
    }

    /** Login tokens are the API's sessions, so they answer to the password too. */
    public function test_changing_a_password_drops_login_tokens_but_not_api_tokens(): void
    {
        $user = User::factory()->create();

        $user->createLoginToken(60);
        $user->createLoginToken(60);
        $user->createApiToken('integration');

        $this->assertSame(2, $user->loginTokens()->count());

        app(AuthService::class)->changePassword($user, 'a-brand-new-password');

        $this->assertSame(0, $user->loginTokens()->count(), 'Login tokens survive a password change.');
        $this->assertSame(
            1,
            $user->apiTokens()->count(),
            'API tokens are the user\'s own deliberate credentials — revoking them is the app\'s call.',
        );
    }

    /** The token making the request is spared, so the caller is not logged out mid-call. */
    public function test_the_login_token_in_use_survives_its_own_password_change(): void
    {
        $user = User::factory()->create();

        $current = $user->createLoginToken(60);
        $user->createLoginToken(60);

        $request = $this->makeRequestWithSession();
        $request->attributes->set('token_id', $current->accessToken->id);
        $this->app['request'] = $request;

        app(AuthService::class)->changePassword($user, 'a-brand-new-password');

        $this->assertSame(1, $user->loginTokens()->count());
        $this->assertNotNull($user->loginTokens()->find($current->accessToken->id));
    }

    /**
     * On file/redis/cookie drivers another session cannot be reached. Reporting
     * 0 is honest; pretending to have revoked would not be.
     */
    public function test_session_revocation_reports_nothing_on_a_non_database_driver(): void
    {
        config(['session.driver' => 'file']);

        $user = User::factory()->create();

        $this->assertSame(0, app(AuthService::class)->revokeOtherSessions($user));
    }

    /** Called outside a request — a console password reset — spares nothing. */
    public function test_revocation_spares_nothing_when_there_is_no_current_credential(): void
    {
        config(['session.driver' => 'database']);
        $this->createSessionsTable();

        $user = User::factory()->create();
        $this->seedSession('some-session', $user->id);

        $this->assertSame(1, app(AuthService::class)->revokeOtherSessions($user));
        $this->assertDatabaseMissing('sessions', ['id' => 'some-session']);
    }

    public function test_api_tokens_can_be_revoked_on_request(): void
    {
        $user = User::factory()->create();
        $user->createApiToken('one');
        $user->createApiToken('two');

        $this->assertSame(2, app(AuthService::class)->revokeApiTokens($user));
        $this->assertSame(0, $user->apiTokens()->count());
    }
}
