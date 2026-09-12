<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use OTPHP\TOTP;
use ParagonIE\ConstantTime\Base32;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

/**
 * The Blade login challenge at POST /otp/mfa.
 *
 * The route is deliberately outside the authenticated group — the challenge
 * happens before the session is trusted — so what it accepts from the request
 * is the whole of its security. Two things must hold: the account it acts on
 * comes from the password step, not from the request; and nothing about the
 * session changes unless the code actually verifies.
 */
class MfaWebChallengeTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    protected string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableMFA();

        Route::middleware(['web', 'neev:web'])
            ->get('/mfa-protected', fn () => response('PROTECTED-PAYLOAD'));
    }

    protected function userWithTotp(): User
    {
        $user = User::factory()->create(['password' => bcrypt('correct-password')]);
        $this->secret = Base32::encodeUpper(random_bytes(32));

        $user->multiFactorAuths()->create([
            'method' => 'authenticator',
            'preferred' => true,
            'secret' => $this->secret,
            'verified_at' => now(),
        ]);

        return $user;
    }

    protected function currentTotp(): string
    {
        return TOTP::create(secret: $this->secret)->now();
    }

    /** The password step redirects to the challenge and stops there. */
    public function test_a_password_alone_does_not_reach_a_protected_route(): void
    {
        $user = $this->userWithTotp();

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-password'])
            ->assertRedirect(route('otp.mfa.create', 'authenticator'));

        $this->get('/mfa-protected')
            ->assertRedirect(route('otp.mfa.create', 'authenticator'));
    }

    /**
     * The gate NeevMiddleware reads is the attempt's multi_factor_method. If a
     * rejected code still stamps it, one wrong guess retires the second factor.
     */
    public function test_a_rejected_code_leaves_the_gate_closed(): void
    {
        $user = $this->userWithTotp();

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-password']);

        $this->post('/otp/mfa', [
            'email' => $user->email,
            'auth_method' => 'authenticator',
            'otp' => '000000',
            'attempt_id' => session('attempt_id'),
        ])->assertSessionHasErrors('message');

        $this->get('/mfa-protected')
            ->assertRedirect(route('otp.mfa.create', 'authenticator'));
    }

    /** The ordinary path still works end to end. */
    public function test_a_valid_code_completes_the_login(): void
    {
        $user = $this->userWithTotp();

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-password']);

        $this->post('/otp/mfa', [
            'email' => $user->email,
            'auth_method' => 'authenticator',
            'otp' => $this->currentTotp(),
        ])->assertSessionHasNoErrors();

        $this->get('/mfa-protected')
            ->assertOk()
            ->assertSee('PROTECTED-PAYLOAD');
    }

    /**
     * Without a password step there is no challenge to answer. The endpoint
     * used to take the account straight from the request body, which turned a
     * second factor into a standalone credential.
     */
    public function test_the_challenge_will_not_authenticate_an_account_named_by_the_request(): void
    {
        $user = $this->userWithTotp();

        // A fresh visitor who has never submitted a password.
        $this->post('/otp/mfa', [
            'email' => $user->email,
            'auth_method' => 'authenticator',
            'otp' => $this->currentTotp(),
        ]);

        $this->assertFalse(auth()->check(), 'A valid second factor alone must not start a session.');
        $this->get('/mfa-protected')->assertRedirect(route('login'));
    }

    /** The same holds for recovery codes, which are backup factors, not credentials. */
    public function test_a_recovery_code_alone_does_not_authenticate(): void
    {
        $user = $this->userWithTotp();
        $codes = $user->generateRecoveryCodes();
        $code = is_array($codes) ? (string) ($codes[0] ?? '') : (string) $codes;

        $this->post('/otp/mfa', [
            'email' => $user->email,
            'auth_method' => 'recovery',
            'otp' => $code,
        ]);

        $this->assertFalse(auth()->check(), 'A recovery code alone must not start a session.');
    }

    /** A recovery code is still a valid answer to a challenge that was opened properly. */
    public function test_a_recovery_code_completes_a_started_login(): void
    {
        $user = $this->userWithTotp();
        $codes = $user->generateRecoveryCodes();
        $code = is_array($codes) ? (string) ($codes[0] ?? '') : (string) $codes;

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-password']);

        $this->post('/otp/mfa', [
            'email' => $user->email,
            'auth_method' => 'recovery',
            'otp' => $code,
        ])->assertSessionHasNoErrors();

        $this->get('/mfa-protected')->assertOk();
    }

    /**
     * Reopening the challenge page must not buy the live code more time.
     * Refreshing expires_at without minting a new code kept one six-digit
     * secret alive indefinitely, which is what made grinding it worthwhile.
     */
    public function test_reopening_the_challenge_page_does_not_extend_a_live_code(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-password')]);
        $auth = $user->multiFactorAuths()->create([
            'method' => 'email',
            'preferred' => true,
            'verified_at' => now(),
            'otp' => '123456',
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-password']);

        $before = $auth->fresh()->expires_at;
        $this->travel(2)->minutes();
        $this->get('/otp/mfa/email')->assertOk();

        $this->assertEquals(
            $before->timestamp,
            $auth->fresh()->expires_at->timestamp,
            'A live code must keep its original deadline.',
        );
    }

    /** An expired code is replaced, and the replacement starts with a full budget. */
    public function test_an_expired_code_is_replaced_with_a_fresh_budget(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-password')]);
        $auth = $user->multiFactorAuths()->create([
            'method' => 'email',
            'preferred' => true,
            'verified_at' => now(),
            'otp' => '123456',
            'expires_at' => now()->subMinute(),
            'attempts' => 4,
        ]);

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-password']);
        $this->get('/otp/mfa/email')->assertOk();

        $fresh = $auth->fresh();
        $this->assertTrue($fresh->expires_at->isFuture());
        $this->assertSame(0, $fresh->attempts);
    }

    /**
     * The challenge page is reachable directly — a bookmark, a back button, or
     * a session that lapsed while the code was being fetched. Without a
     * challenge in progress there is no account to look up, and
     * User::findByEmail() takes a non-nullable string.
     */
    public function test_the_challenge_page_survives_a_lapsed_session(): void
    {
        $this->get('/otp/mfa/email')->assertRedirect(route('login'));
        $this->get('/otp/mfa/authenticator')->assertRedirect(route('login'));
    }
}
