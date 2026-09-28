<?php

namespace Ssntpl\Neev\Tests\Feature\Account;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use OTPHP\TOTP;
use Ssntpl\Neev\Database\Factories\MultiFactorAuthFactory;
use Ssntpl\Neev\Models\LoginAttempt;
use Ssntpl\Neev\Models\MultiFactorAuth;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

/**
 * When a web session is stamped as holding a factor, and what a pending
 * authenticator setup may and may not do.
 *
 * `login_attempts.multi_factor_method` is what NeevMiddleware reads as proof
 * that a session answered its challenge. The Blade enrolment path used to
 * write it the moment an enrolment *started*, so a setup that was never
 * verified vouched for the session for good: it survived a factor enrolled
 * from elsewhere, which docs/mfa.md promises ends every session that predates
 * it. The stamp is now written when the session completes the enrolment.
 */
class MfaEnrolmentStampTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableMFA();
        Mail::fake();
    }

    /** A web session signed in while the account had no factor at all. */
    private function sessionWithoutAFactor(): array
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $attempt = $user->loginAttempts()->create([
            'method' => LoginAttempt::Password,
            'multi_factor_method' => null,
            'is_success' => true,
        ]);
        $this->actingAs($user);
        session(['attempt_id' => $attempt->id]);

        return [$user, $attempt];
    }

    private function enrolElsewhere(User $user, string $method = 'email'): void
    {
        MultiFactorAuthFactory::new()->create(['user_id' => $user->id, 'method' => $method, 'preferred' => true]);
    }

    private function totpFor(User $user): string
    {
        $secret = $user->fresh()->multiFactorAuths()->where('method', 'authenticator')->first()->secret;

        return TOTP::create(secret: $secret)->now();
    }

    public function test_starting_an_authenticator_setup_does_not_stamp_the_session(): void
    {
        [$user, $attempt] = $this->sessionWithoutAFactor();

        $this->post(route('multi.auth'), ['auth_method' => 'authenticator'])->assertSessionHasNoErrors();

        $this->assertSame(MultiFactorAuth::STATUS_PENDING, $user->fresh()->multiFactorAuths()->first()->status);
        $this->assertNull($attempt->fresh()->multi_factor_method, 'a setup that has not been verified proves nothing');
    }

    /** The hole: an abandoned setup used to keep the session alive for good. */
    public function test_an_abandoned_setup_does_not_let_the_session_outlive_a_factor_enrolled_elsewhere(): void
    {
        [$user] = $this->sessionWithoutAFactor();
        $this->post(route('multi.auth'), ['auth_method' => 'authenticator']);

        $this->enrolElsewhere($user);

        // A real request resolves the user afresh; the test guard keeps one
        // instance, whose loaded (empty) factor list would otherwise hide the
        // new one from the gate.
        $this->actingAs($user->fresh());
        $this->get('/account/profile')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_verifying_the_setup_stamps_the_session_and_keeps_it_signed_in(): void
    {
        [$user, $attempt] = $this->sessionWithoutAFactor();
        $this->post(route('multi.auth'), ['auth_method' => 'authenticator']);

        $this->post(route('otp.mfa.store'), [
            'action' => 'verify',
            'email' => $user->email,
            'auth_method' => 'authenticator',
            'otp' => $this->totpFor($user),
        ])->assertSessionHas('status', 'Method verified and enabled.');

        $this->assertSame('authenticator', $attempt->fresh()->multi_factor_method);
        $this->assertCount(1, $user->fresh()->activeMultiFactorAuths()->where('method', 'authenticator')->get());
        $this->get('/account/profile')->assertOk();
        $this->assertAuthenticated();
    }

    public function test_enrolling_email_stamps_the_session_because_it_is_active_at_once(): void
    {
        [$user, $attempt] = $this->sessionWithoutAFactor();

        $this->post(route('multi.auth'), ['auth_method' => 'email'])->assertSessionHasNoErrors();

        $this->assertSame('email', $attempt->fresh()->multi_factor_method);
        $this->get('/account/profile')->assertOk();
        $this->assertAuthenticated();
    }

    public function test_a_refused_enrolment_does_not_stamp_the_session(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $attempt = $user->loginAttempts()->create(['method' => LoginAttempt::Password, 'is_success' => true]);
        $this->actingAs($user);
        session(['attempt_id' => $attempt->id]);

        $this->post(route('multi.auth'), ['auth_method' => 'email'])->assertSessionHasErrors('message');

        $this->assertNull($attempt->fresh()->multi_factor_method);
    }

    /** The login history records what the session signed in with, not what it enrolled later. */
    public function test_a_session_that_answered_a_challenge_keeps_that_factor_on_record(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'password' => bcrypt('Password123!')]);
        $this->enrolElsewhere($user, 'email');
        $attempt = $user->loginAttempts()->create([
            'method' => LoginAttempt::Password,
            'multi_factor_method' => 'email',
            'is_success' => true,
        ]);
        $this->actingAs($user);
        session(['attempt_id' => $attempt->id]);

        $this->post(route('multi.auth'), ['auth_method' => 'authenticator', 'password' => 'Password123!']);
        $this->post(route('otp.mfa.store'), [
            'action' => 'verify',
            'email' => $user->email,
            'auth_method' => 'authenticator',
            'otp' => $this->totpFor($user),
        ])->assertSessionHas('status', 'Method verified and enabled.');

        $this->assertSame('email', $attempt->fresh()->multi_factor_method);
    }

    // -----------------------------------------------------------------
    // A planted setup dies when the owner turns a factor on
    // -----------------------------------------------------------------

    /**
     * The verify route sits outside the session gate, so a stolen session
     * could finish a setup it planted after the owner had enrolled a factor
     * elsewhere — activating a second factor with no confirmation. Enrolling
     * a factor discards every setup still pending instead.
     */
    public function test_enrolling_a_factor_elsewhere_discards_a_pending_setup(): void
    {
        [$user] = $this->sessionWithoutAFactor();
        $this->post(route('multi.auth'), ['auth_method' => 'authenticator']);
        $planted = $user->fresh()->multiFactorAuths()->first()->secret;

        // The owner turns email OTP on from another device.
        $user->fresh()->addMultiFactorAuth('email');

        $this->assertSame(0, $user->fresh()->multiFactorAuths()->where('status', MultiFactorAuth::STATUS_PENDING)->count());

        $this->post(route('otp.mfa.store'), [
            'action' => 'verify',
            'email' => $user->email,
            'auth_method' => 'authenticator',
            'otp' => TOTP::create(secret: $planted)->now(),
        ])->assertSessionHasErrors('message');

        $this->assertCount(1, $user->fresh()->activeMultiFactorAuths, 'only the owner\'s email factor is active');
        $this->assertNull($user->loginAttempts()->latest('id')->first()->multi_factor_method);
    }

    public function test_the_api_cannot_finish_a_setup_the_owner_has_moved_past_either(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $token = $user->createLoginToken(1440)->plainTextToken;
        $planted = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/mfa/add', ['auth_method' => 'authenticator'])->assertOk()->json('secret');

        $user->fresh()->addMultiFactorAuth('email');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/mfa/setup/verify', ['auth_method' => 'authenticator', 'otp' => TOTP::create(secret: $planted)->now()])
            ->assertStatus(400);

        $this->assertSame(['email'], $user->fresh()->activeMultiFactorAuths->pluck('method')->all());
    }

    /** The user is told the setup was discarded, not that their code was wrong. */
    public function test_a_discarded_setup_is_named_as_such_on_both_surfaces(): void
    {
        [$user] = $this->sessionWithoutAFactor();
        $this->post(route('multi.auth'), ['auth_method' => 'authenticator']);
        $planted = $user->fresh()->multiFactorAuths()->first()->secret;
        $user->fresh()->addMultiFactorAuth('email');

        $this->post(route('otp.mfa.store'), [
            'action' => 'verify',
            'email' => $user->email,
            'auth_method' => 'authenticator',
            'otp' => TOTP::create(secret: $planted)->now(),
        ]);
        $this->assertStringContainsString('No setup is in progress', session('errors')->first('message'));

        $token = $user->createLoginToken(1440)->plainTextToken;
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/mfa/setup/verify', ['auth_method' => 'authenticator', 'otp' => TOTP::create(secret: $planted)->now()])
            ->assertStatus(400)
            ->assertJsonFragment(['message' => 'No setup is in progress for this method. Start it again.']);
    }

    // -----------------------------------------------------------------
    // Only an enrolment this request completed stamps the session
    // -----------------------------------------------------------------

    /** "Set up" on an active authenticator completes nothing, and a passkey login's history stays a passkey login. */
    public function test_reopening_set_up_on_an_active_factor_does_not_stamp_a_passkey_session(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'password' => bcrypt('Password123!')]);
        MultiFactorAuthFactory::new()->create(['user_id' => $user->id, 'method' => 'authenticator', 'preferred' => true]);
        $attempt = $user->loginAttempts()->create(['method' => LoginAttempt::Passkey, 'multi_factor_method' => null, 'is_success' => true]);
        $this->actingAs($user);
        session(['attempt_id' => $attempt->id]);

        $this->post(route('multi.auth'), ['auth_method' => 'authenticator', 'password' => 'Password123!'])
            ->assertSessionHasNoErrors();

        $this->assertNull($attempt->fresh()->multi_factor_method);
    }

    // -----------------------------------------------------------------
    // A pending setup's secret is not reusable by whoever planted it
    // -----------------------------------------------------------------

    public function test_starting_a_pending_setup_again_rotates_its_secret(): void
    {
        [$user] = $this->sessionWithoutAFactor();

        $this->post(route('multi.auth'), ['auth_method' => 'authenticator']);
        $first = $user->fresh()->multiFactorAuths()->first()->secret;

        $this->post(route('multi.auth'), ['auth_method' => 'authenticator']);
        $second = $user->fresh()->multiFactorAuths()->first()->secret;

        $this->assertNotSame($first, $second, 'the secret a planted setup carried must not be the one the owner scans');
        $this->assertSame(1, $user->fresh()->multiFactorAuths()->count(), 'still one pending row');
    }

    public function test_the_rotated_secret_is_the_one_shown_and_the_one_that_verifies(): void
    {
        [$user] = $this->sessionWithoutAFactor();
        $this->post(route('multi.auth'), ['auth_method' => 'authenticator']);
        $planted = $user->fresh()->multiFactorAuths()->first()->secret;

        $response = $this->post(route('multi.auth'), ['auth_method' => 'authenticator']);
        $shown = session('secret');
        $this->assertNotSame($planted, $shown);
        $this->assertSame($user->fresh()->multiFactorAuths()->first()->secret, $shown);

        // A code from the planted secret no longer verifies the setup.
        $this->post(route('otp.mfa.store'), [
            'action' => 'verify',
            'email' => $user->email,
            'auth_method' => 'authenticator',
            'otp' => TOTP::create(secret: $planted)->now(),
        ])->assertSessionHasErrors('message');
        $this->assertSame(MultiFactorAuth::STATUS_PENDING, $user->fresh()->multiFactorAuths()->first()->status);
    }

    public function test_an_active_authenticator_keeps_its_secret_when_set_up_is_reopened(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'password' => bcrypt('Password123!')]);
        $active = MultiFactorAuthFactory::new()->create(['user_id' => $user->id, 'method' => 'authenticator', 'preferred' => true]);
        $attempt = $user->loginAttempts()->create(['method' => LoginAttempt::Password, 'multi_factor_method' => 'authenticator', 'is_success' => true]);
        $this->actingAs($user);
        session(['attempt_id' => $attempt->id]);

        $this->post(route('multi.auth'), ['auth_method' => 'authenticator', 'password' => 'Password123!'])
            ->assertSessionHasNoErrors();

        $this->assertSame($active->secret, $user->fresh()->multiFactorAuths()->first()->secret, 'a live secret is the one in the user\'s app');
    }
}
