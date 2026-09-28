<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Ssntpl\Neev\Models\LoginAttempt;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Rules\PasswordHistory;
use Ssntpl\Neev\Rules\PasswordUserData;
use Ssntpl\Neev\Tests\TestCase;

/**
 * The password rules must never answer "is this the account's password?" for
 * an account the request has not proven.
 *
 * `PasswordHistory` used to resolve the account from the request body's
 * `email`, then `id`, ahead of the signed-in user, and `PasswordUserData` from
 * `email`. Anyone could post a victim's address with a guess and read from the
 * validation error whether it was that account's current or past password —
 * through registration with no sign-in at all, through their own account's
 * change-password form, or through their own reset link — at the IP
 * throttle's pace, or with none at all.
 */
class PasswordOracleTest extends TestCase
{
    use RefreshDatabase;

    private const VICTIM_PASSWORD = 'Victim-Secret-9!';
    private const VICTIM_OLD_PASSWORD = 'Victim-Old-Secret-7!';

    private User $victim;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        config(['neev.password' => [
            'required',
            'confirmed',
            PasswordHistory::notReused(5),
            PasswordUserData::notContain(['name']),
        ]]);

        $this->victim = User::factory()->create([
            'name' => 'Victoria Victimson',
            'email' => 'victim@example.com',
            'password' => bcrypt(self::VICTIM_PASSWORD),
            'password_history' => [bcrypt(self::VICTIM_OLD_PASSWORD)],
        ]);
    }

    /** Every message the two rules can produce about an account. */
    private function assertSaysNothingAboutTheVictim(array $errors): void
    {
        $text = json_encode($errors);
        $this->assertStringNotContainsString('cannot be the same as your last', $text);
        $this->assertStringNotContainsString('should not contain your', $text);
    }

    // -----------------------------------------------------------------
    // Registration — no sign-in needed
    // -----------------------------------------------------------------

    public function test_api_registration_with_the_victims_email_does_not_grade_the_guess(): void
    {
        foreach ([self::VICTIM_PASSWORD, self::VICTIM_OLD_PASSWORD, 'Victoria-Victimson-1!'] as $guess) {
            $response = $this->postJson('/neev/register', [
                'name' => 'Anyone',
                'email' => $this->victim->email,
                'password' => $guess,
                'password_confirmation' => $guess,
            ])->assertUnprocessable();

            $this->assertSaysNothingAboutTheVictim($response->json('errors'));
        }
    }

    public function test_api_registration_with_the_victims_id_does_not_grade_the_guess(): void
    {
        $response = $this->postJson('/neev/register', [
            'name' => 'Anyone',
            'email' => 'fresh@example.com',
            'id' => $this->victim->id,
            'password' => self::VICTIM_PASSWORD,
            'password_confirmation' => 'mismatch',
        ])->assertUnprocessable();

        $this->assertSaysNothingAboutTheVictim($response->json('errors'));
    }

    public function test_blade_registration_with_the_victims_email_does_not_grade_the_guess(): void
    {
        $this->post('/register', [
            'name' => 'Anyone',
            'email' => $this->victim->email,
            'password' => self::VICTIM_PASSWORD,
            'password_confirmation' => self::VICTIM_PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->assertSaysNothingAboutTheVictim(session('errors')->toArray());
    }

    // -----------------------------------------------------------------
    // A signed-in attacker's own change-password form
    // -----------------------------------------------------------------

    public function test_api_change_password_compares_the_caller_not_the_email_in_the_body(): void
    {
        $attacker = User::factory()->create(['password' => bcrypt('Attacker-Pass-1!')]);
        $token = $attacker->createLoginToken(1440)->plainTextToken;

        // With the caller's own current password proven, the rules run — on
        // the caller. A mismatched confirmation keeps their password as it is.
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)->putJson('/neev/changePassword', [
            'current_password' => 'Attacker-Pass-1!',
            'email' => $this->victim->email,
            'password' => self::VICTIM_PASSWORD,
            'password_confirmation' => 'mismatch',
        ])->assertUnprocessable();

        $this->assertSaysNothingAboutTheVictim($response->json('errors'));

        // The caller's own history is still enforced.
        $this->withHeader('Authorization', 'Bearer ' . $token)->putJson('/neev/changePassword', [
            'current_password' => 'Attacker-Pass-1!',
            'email' => $this->victim->email,
            'password' => 'Attacker-Pass-1!',
            'password_confirmation' => 'Attacker-Pass-1!',
        ])->assertUnprocessable()->assertJsonValidationErrors(['password']);
    }

    public function test_blade_change_password_compares_the_caller_not_the_email_in_the_body(): void
    {
        $attacker = User::factory()->create(['password' => bcrypt('Attacker-Pass-1!')]);
        $attempt = $attacker->loginAttempts()->create([
            'method' => LoginAttempt::Password,
            'is_success' => true,
        ]);
        $this->actingAs($attacker);
        session(['attempt_id' => $attempt->id]);

        $this->post(route('password.change'), [
            'current_password' => 'Attacker-Pass-1!',
            'email' => $this->victim->email,
            'password' => self::VICTIM_PASSWORD,
            'password_confirmation' => 'mismatch',
        ])->assertSessionHasErrors('password');

        $this->assertSaysNothingAboutTheVictim(session('errors')->toArray());
    }

    /** A registration posted from a stolen session must not grade the session owner's passwords. */
    public function test_blade_registration_from_a_signed_in_session_does_not_grade_the_sessions_owner(): void
    {
        $attempt = $this->victim->loginAttempts()->create(['method' => LoginAttempt::Password, 'is_success' => true]);
        $this->actingAs($this->victim);
        session(['attempt_id' => $attempt->id]);

        $this->post('/register', [
            'name' => 'Anyone',
            'email' => 'not-an-address',
            'password' => self::VICTIM_PASSWORD,
            'password_confirmation' => self::VICTIM_PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->assertSaysNothingAboutTheVictim(session('errors')->toArray());
    }

    /** The new password is graded only after the current one is proven, so a wrong current password reveals nothing. */
    public function test_change_password_does_not_grade_the_new_password_before_the_current_one_is_right(): void
    {
        $token = $this->victim->createLoginToken(1440)->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)->putJson('/neev/changePassword', [
            'current_password' => 'not-it',
            'password' => self::VICTIM_OLD_PASSWORD,
            'password_confirmation' => self::VICTIM_OLD_PASSWORD,
        ])->assertForbidden();
        $this->assertSaysNothingAboutTheVictim((array) $response->json());

        $attempt = $this->victim->loginAttempts()->create(['method' => LoginAttempt::Password, 'is_success' => true]);
        $this->actingAs($this->victim);
        session(['attempt_id' => $attempt->id]);
        $this->post(route('password.change'), [
            'current_password' => 'not-it',
            'password' => self::VICTIM_OLD_PASSWORD,
            'password_confirmation' => self::VICTIM_OLD_PASSWORD,
        ])->assertSessionHasErrors('message');
        $this->assertSaysNothingAboutTheVictim(session('errors')->toArray());

        // With the current password proven, the history rule still applies.
        $this->withHeader('Authorization', 'Bearer ' . $token)->putJson('/neev/changePassword', [
            'current_password' => self::VICTIM_PASSWORD,
            'password' => self::VICTIM_OLD_PASSWORD,
            'password_confirmation' => self::VICTIM_OLD_PASSWORD,
        ])->assertUnprocessable()->assertJsonValidationErrors(['password']);
    }

    // -----------------------------------------------------------------
    // The attacker's own reset link, aimed at someone else
    // -----------------------------------------------------------------

    public function test_api_reset_link_compares_the_account_it_was_sent_to_not_the_email_in_the_body(): void
    {
        config(['neev.ui' => null]);
        $attacker = User::factory()->create(['password' => bcrypt('Attacker-Pass-1!')]);
        $this->travel(1)->seconds();

        $query = parse_url(URL::temporarySignedRoute(
            'neev.resetPassword',
            now()->addMinutes(60),
            ['id' => $attacker->id, 'hash' => hash('sha256', $attacker->email)],
        ), PHP_URL_QUERY);

        $response = $this->postJson('/neev/resetPassword?' . $query, [
            'email' => $this->victim->email,
            'password' => self::VICTIM_PASSWORD,
            'password_confirmation' => 'mismatch',
        ])->assertUnprocessable();

        $this->assertSaysNothingAboutTheVictim($response->json('errors'));
        $this->assertTrue(Hash::check(self::VICTIM_PASSWORD, $this->victim->fresh()->password));

        // The link's own account is still held to its history.
        $this->postJson('/neev/resetPassword?' . $query, [
            'email' => $this->victim->email,
            'password' => 'Attacker-Pass-1!',
            'password_confirmation' => 'Attacker-Pass-1!',
        ])->assertUnprocessable()->assertJsonValidationErrors(['password']);
    }

    /** The rules still protect the account a reset has actually proven. */
    public function test_a_reset_by_code_still_refuses_that_accounts_own_old_password(): void
    {
        $this->postJson('/neev/forgotPassword', ['email' => $this->victim->email])->assertOk();
        $otp = null;
        Mail::assertSent(\Ssntpl\Neev\Mail\VerifyUserEmail::class, function ($mail) use (&$otp) {
            $otp = $mail->otp;

            return true;
        });

        $this->postJson('/neev/resetPassword', [
            'email' => $this->victim->email,
            'otp' => $otp,
            'password' => self::VICTIM_OLD_PASSWORD,
            'password_confirmation' => self::VICTIM_OLD_PASSWORD,
        ])->assertUnprocessable()->assertJsonValidationErrors(['password']);

        // And the personal-data rule sees the same proven account.
        $this->postJson('/neev/resetPassword', [
            'email' => $this->victim->email,
            'otp' => $otp,
            'password' => 'Victoria Victimson 1!',
            'password_confirmation' => 'Victoria Victimson 1!',
        ])->assertUnprocessable()->assertJsonValidationErrors(['password' => 'should not contain your name']);
    }
}
