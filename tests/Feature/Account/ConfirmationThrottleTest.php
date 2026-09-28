<?php

namespace Ssntpl\Neev\Tests\Feature\Account;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;
use Illuminate\Testing\TestResponse;
use Ssntpl\Neev\Database\Factories\MultiFactorAuthFactory;
use Ssntpl\Neev\Models\LoginAttempt;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\AuthService;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

/**
 * Every action that re-checks the account's password shares one budget of
 * wrong answers, kept in `AuthService`: five a minute, per account, cleared by
 * a right one.
 *
 * Wrong answers only. A confirmation is asked of a session that already holds
 * the account, so the limit exists to stop a *stolen* session using the check
 * as an oracle for the password it lacks — not to meter the owner, and not to
 * let the thief lock the owner out with cheap unconfirmed requests.
 */
class ConfirmationThrottleTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    private const PASSWORD = 'Password123!';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableMFA();
        Mail::fake();
    }

    /** An account with a password and an active factor, signed in on the web and holding a login token. */
    private function enrolledUser(): User
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'password' => bcrypt(self::PASSWORD)]);
        MultiFactorAuthFactory::new()->create(['user_id' => $user->id, 'method' => 'authenticator', 'preferred' => true]);

        $attempt = $user->loginAttempts()->create([
            'method' => LoginAttempt::Password,
            'multi_factor_method' => 'authenticator',
            'is_success' => true,
        ]);
        $this->actingAs($user);
        session(['attempt_id' => $attempt->id]);
        $this->token = $user->createLoginToken(1440)->plainTextToken;

        return $user;
    }

    /**
     * One request to each endpoint that checks the password.
     *
     * @return array<string, array{0: string}>
     */
    public static function endpoints(): array
    {
        return [
            'api add a factor' => ['api mfa add'],
            'api remove a factor' => ['api mfa delete'],
            'api passkey options' => ['api passkey options'],
            'api change password' => ['api change password'],
            'api delete account' => ['api delete account'],
            'api sign out other sessions' => ['api logout all'],
            'api recovery codes' => ['api recovery codes'],
            'api email change' => ['api email change'],
            'blade add or remove a factor' => ['blade mfa'],
            'blade passkey options' => ['blade passkey options'],
            'blade change password' => ['blade change password'],
            'blade delete account' => ['blade delete account'],
            'blade sign out other sessions' => ['blade logout sessions'],
            'blade recovery codes' => ['blade recovery codes'],
            'blade email change' => ['blade email change'],
        ];
    }

    private function attempt(string $endpoint, string $password): TestResponse
    {
        $api = fn () => $this->withHeader('Authorization', 'Bearer ' . $this->token);

        return match ($endpoint) {
            'api mfa add' => $api()->postJson('/neev/mfa/add', ['auth_method' => 'email', 'password' => $password]),
            'api mfa delete' => $api()->deleteJson('/neev/mfa/delete', ['auth_method' => 'authenticator', 'password' => $password]),
            'api passkey options' => $api()->postJson('/neev/passkeys/register/options', ['password' => $password]),
            'api change password' => $api()->putJson('/neev/changePassword', ['current_password' => $password, 'password' => 'Another-Pass-9!', 'password_confirmation' => 'Another-Pass-9!']),
            'api delete account' => $api()->deleteJson('/neev/users', ['password' => $password]),
            'api logout all' => $api()->postJson('/neev/logoutAll', ['password' => $password]),
            'api recovery codes' => $api()->postJson('/neev/recoveryCodes', ['password' => $password]),
            'api email change' => $api()->postJson('/neev/email/change', ['email' => 'moved@example.com', 'password' => $password]),
            'blade mfa' => $this->post(route('multi.auth'), ['auth_method' => 'authenticator', 'action' => 'delete', 'password' => $password]),
            'blade passkey options' => $this->postJson(route('passkeys.register.options'), ['password' => $password]),
            'blade change password' => $this->post(route('password.change'), ['current_password' => $password, 'password' => 'Another-Pass-9!', 'password_confirmation' => 'Another-Pass-9!']),
            'blade delete account' => $this->delete(route('account.delete'), ['password' => $password]),
            'blade logout sessions' => $this->post(route('logout.sessions'), ['password' => $password]),
            'blade recovery codes' => $this->post(route('recovery.generate'), ['password' => $password]),
            'blade email change' => $this->put(route('email.update'), ['email' => 'moved@example.com', 'password' => $password]),
        };
    }

    private function assertThrottled(TestResponse $response, string $endpoint): void
    {
        if (str_contains($endpoint, 'api') || str_contains($endpoint, 'passkey')) {
            $response->assertStatus(429)->assertHeader('Retry-After');

            // Recovery codes and email change also carry a per-minute mail
            // limit on the route, which answers first with Laravel's plain
            // body; every other 429 here is the confirmation limiter's.
            if (!in_array($endpoint, ['api recovery codes', 'api email change'], true)) {
                $response->assertJsonStructure(['message', 'retry_after']);
            }

            return;
        }

        // The two routes that also mail carry a per-minute route throttle,
        // which answers a sixth request first with Laravel's own 429 page, as
        // it did before the confirmation limit existed.
        if (in_array($endpoint, ['blade recovery codes', 'blade email change'], true)) {
            $response->assertStatus(429)->assertHeader('Retry-After');

            return;
        }

        // Everywhere else the Blade kit lands back on the form with the
        // message under the field it asked for, not on a bare 429 page.
        $response->assertRedirect();
        $this->assertStringContainsString('Too many attempts', implode(' ', session('errors')->all()));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('endpoints')]
    public function test_the_sixth_answer_within_a_minute_is_refused_even_when_right(string $endpoint): void
    {
        if ($endpoint === 'blade logout sessions') {
            // The action refuses on any other driver before it confirms. Name
            // the driver "database" but keep the store in memory: the store's
            // name is all the controller reads.
            config(['session.driver' => 'database']);
            Session::extend('database', fn () => new ArraySessionHandler(120));
        }

        $user = $this->enrolledUser();

        for ($i = 1; $i <= 5; $i++) {
            $response = $this->attempt($endpoint, "wrong-{$i}");
            $this->assertNotSame(429, $response->status(), "{$endpoint}: answer {$i} must still be graded");
        }

        $this->assertThrottled($this->attempt($endpoint, self::PASSWORD), $endpoint);

        // Nothing happened: the factor, the account and the password are as they were.
        $fresh = $user->fresh();
        $this->assertNotNull($fresh);
        $this->assertCount(1, $fresh->activeMultiFactorAuths);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check(self::PASSWORD, $fresh->password));
    }

    /** One budget: guesses spent on one action count against every other, on both surfaces. */
    public function test_guesses_at_one_action_spend_the_budget_of_the_others(): void
    {
        $this->enrolledUser();

        for ($i = 1; $i <= 5; $i++) {
            $this->attempt('api change password', "wrong-{$i}")->assertForbidden();
        }

        $this->attempt('api delete account', self::PASSWORD)->assertStatus(429);
        $this->assertThrottled($this->attempt('blade delete account', self::PASSWORD), 'blade delete account');
    }

    /** A right answer clears the count, so the owner is never locked out by their own use. */
    public function test_a_right_answer_clears_the_count(): void
    {
        $this->enrolledUser();

        for ($i = 1; $i <= 4; $i++) {
            $this->attempt('api mfa delete', "wrong-{$i}")->assertForbidden();
        }
        $this->attempt('api logout all', self::PASSWORD)->assertOk();

        for ($i = 1; $i <= 4; $i++) {
            $this->attempt('api mfa delete', "wrong-again-{$i}")->assertForbidden();
        }
    }

    /** Requests that confirm nothing spend nothing: a thief cannot lock the owner out with them. */
    public function test_unconfirmed_requests_do_not_spend_the_budget(): void
    {
        $user = $this->enrolledUser();

        // Dropping a single named device is deliberately unconfirmed.
        for ($i = 1; $i <= 6; $i++) {
            $this->post(route('logout.sessions'), ['session_id' => "unknown-{$i}"])->assertRedirect();
            $this->assertStringNotContainsString('Too many attempts', implode(' ', session('errors')?->all() ?? []));
        }

        // The owner's confirmed action still goes through.
        $this->attempt('api logout all', self::PASSWORD)->assertOk();

        // Starting the first setup on an account with no factor is unconfirmed too.
        $novice = User::factory()->create(['email_verified_at' => now(), 'password' => bcrypt(self::PASSWORD)]);
        $attempt = $novice->loginAttempts()->create(['method' => LoginAttempt::Password, 'is_success' => true]);
        $this->actingAs($novice);
        session(['attempt_id' => $attempt->id]);
        for ($i = 1; $i <= 6; $i++) {
            $this->post(route('multi.auth'), ['auth_method' => 'authenticator'])->assertSessionHasNoErrors();
        }
        $this->assertSame(0, \Illuminate\Support\Facades\RateLimiter::attempts('neev-confirmation:' . $novice->getMorphClass() . ':' . $novice->id));
    }

    /** The budget is per account, so one account's lockout is not another's. */
    public function test_the_budget_is_per_account(): void
    {
        $this->enrolledUser();
        for ($i = 1; $i <= 5; $i++) {
            $this->attempt('api mfa delete', "wrong-{$i}");
        }
        $this->attempt('api mfa delete', self::PASSWORD)->assertStatus(429);

        $other = User::factory()->create(['email_verified_at' => now(), 'password' => bcrypt(self::PASSWORD)]);
        MultiFactorAuthFactory::new()->create(['user_id' => $other->id, 'method' => 'authenticator', 'preferred' => true]);
        $this->withHeader('Authorization', 'Bearer ' . $other->createLoginToken(1440)->plainTextToken)
            ->deleteJson('/neev/mfa/delete', ['auth_method' => 'authenticator', 'password' => self::PASSWORD])
            ->assertOk();
    }

    /** Passwordless accounts confirm with a code; wrong codes spend the same budget. */
    public function test_wrong_confirmation_codes_spend_the_budget_too(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'password' => null]);
        MultiFactorAuthFactory::new()->create(['user_id' => $user->id, 'method' => 'authenticator', 'preferred' => true]);
        $token = $user->createLoginToken(1440)->plainTextToken;
        $this->withHeader('Authorization', 'Bearer ' . $token)->postJson('/neev/confirmation/otp')->assertOk();

        for ($i = 1; $i <= 5; $i++) {
            $this->withHeader('Authorization', 'Bearer ' . $token)
                ->deleteJson('/neev/mfa/delete', ['auth_method' => 'authenticator', 'otp' => "00000{$i}"])
                ->assertForbidden();
        }

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/mfa/delete', ['auth_method' => 'authenticator', 'otp' => '000000'])
            ->assertStatus(429);
    }

    public function test_the_limit_is_five_a_minute(): void
    {
        $this->assertSame(5, AuthService::CONFIRMATION_GUESS_LIMIT);
        $this->assertSame(60, AuthService::CONFIRMATION_GUESS_WINDOW);
    }
}
