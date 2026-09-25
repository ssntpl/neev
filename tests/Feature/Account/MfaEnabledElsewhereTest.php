<?php

namespace Ssntpl\Neev\Tests\Feature\Account;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Database\Factories\MultiFactorAuthFactory;
use Ssntpl\Neev\Models\LoginAttempt;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

/**
 * A second factor enrolled from another session — an API token, another
 * browser — while a web session is already open.
 *
 * That web session's login attempt completed without ever naming a factor,
 * so the gate no longer considers it answered. It cannot be challenged in
 * place either: the challenge page reads the account from `session('email')`,
 * which only a login parked at the challenge writes. Redirecting it there
 * used to produce protected page -> challenge -> /login -> protected page,
 * around forever. The session is simply unauthenticated now, and the login
 * that follows parks at the challenge properly.
 */
class MfaEnabledElsewhereTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableMFA();
    }

    /** A web session opened before the account had any second factor. */
    private function sessionPredatingEnrolment(User $user): void
    {
        $attempt = $user->loginAttempts()->create([
            'method' => LoginAttempt::Password,
            'multi_factor_method' => null,
            'is_success' => true,
        ]);

        $this->actingAs($user);
        session(['attempt_id' => $attempt->id]);
    }

    private function enrolElsewhere(User $user): void
    {
        MultiFactorAuthFactory::new()->create([
            'user_id' => $user->id,
            'method' => 'authenticator',
            'preferred' => true,
        ]);
    }

    public function test_session_open_before_enrolment_is_unauthenticated_not_bounced(): void
    {
        $user = User::factory()->create();
        $this->sessionPredatingEnrolment($user);
        $this->enrolElsewhere($user);

        $this->get('/account/profile')
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    /**
     * The loop itself: /login used to send the still-authenticated user back
     * to a page the gate refuses. Signed out, it renders.
     */
    public function test_the_login_page_it_lands_on_does_not_bounce_back(): void
    {
        $user = User::factory()->create();
        $this->sessionPredatingEnrolment($user);
        $this->enrolElsewhere($user);

        $this->get('/account/profile');

        $this->get('/login')->assertOk();
    }

    /**
     * Signing in again parks at the challenge properly — with the account in
     * the session, which is what the challenge page needs.
     */
    public function test_signing_in_again_reaches_a_working_challenge(): void
    {
        $user = User::factory()->create(['password' => bcrypt('Password123!')]);
        $this->sessionPredatingEnrolment($user);
        $this->enrolElsewhere($user);

        $this->get('/account/profile');
        $this->assertGuest();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'Password123!',
        ])->assertRedirect('/otp/mfa/authenticator');

        $this->get('/otp/mfa/authenticator')->assertOk();
    }

    /** A login still parked mid-challenge is not signed out — it is challenged. */
    public function test_a_login_parked_mid_challenge_is_left_alone(): void
    {
        $user = User::factory()->create();
        $this->enrolElsewhere($user);

        $attempt = $user->loginAttempts()->create([
            'method' => LoginAttempt::Password,
            'multi_factor_method' => 'authenticator',
            'is_success' => false,
        ]);

        $this->actingAs($user);
        session(['attempt_id' => $attempt->id, 'email' => $user->email]);

        $this->get('/account/profile')
            ->assertRedirect('/otp/mfa/authenticator');

        $this->assertAuthenticated();
    }
}
