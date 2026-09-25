<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Ssntpl\Neev\Mail\VerifyUserEmail;
use Ssntpl\Neev\Models\OTP;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\AuthService;
use Ssntpl\Neev\Rules\PasswordHistory;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    protected function setUp(): void
    {
        parent::setUp();

        // Simplify password rules for reset tests
        config(['neev.password' => ['required', 'confirmed']]);
    }

    // -----------------------------------------------------------------
    // POST /neev/forgotPassword — send password reset link
    // -----------------------------------------------------------------

    public function test_forgot_password_sends_reset_link(): void
    {
        Mail::fake();

        $user = User::factory()->create();

        $response = $this->postJson('/neev/forgotPassword', [
            'email' => $user->email,
        ]);

        $response->assertOk();
        $response->assertJson([
            'message' => 'Password reset link has been sent to your email.',
        ]);
    }

    public function test_forgot_password_returns_error_for_non_existent_email(): void
    {
        $response = $this->postJson('/neev/forgotPassword', [
            'email' => 'nobody@example.com',
        ]);

        $response->assertStatus(404);
    }

    public function test_forgot_password_sends_link_to_unverified_user(): void
    {
        // A forgotten password is exactly the case where the user may never
        // have completed verification, so verification is not a precondition
        // for receiving a reset link.
        Mail::fake();

        $user = User::factory()->unverified()->create();

        $response = $this->postJson('/neev/forgotPassword', [
            'email' => $user->email,
        ]);

        $response->assertOk();

        Mail::assertSent(VerifyUserEmail::class, fn (VerifyUserEmail $mail) => $mail->purpose === 'Reset Password');
    }

    // -----------------------------------------------------------------
    // POST /neev/resetPassword — reset password via signed URL
    // -----------------------------------------------------------------

    public function test_successful_password_reset_with_valid_signed_url(): void
    {
        $user = User::factory()->create();

        $this->travel(1)->seconds();

        $signedUrl = URL::temporarySignedRoute(
            'neev.resetPassword',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => hash('sha256', $user->email)]
        );

        $query = parse_url($signedUrl, PHP_URL_QUERY);

        $response = $this->postJson('/neev/resetPassword?' . $query, [
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertOk();
        $response->assertJson([
            'message' => 'Password has been updated.',
        ]);

        // Verify new password works
        $user->refresh();
        $this->assertTrue(Hash::check('newpassword123', $user->getRawOriginal('password')));
    }

    public function test_reset_password_rejects_invalid_signature(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/neev/resetPassword?id=' . $user->id . '&signature=invalidsig', [
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'Invalid or expired reset link.',
        ]);
    }

    public function test_reset_password_returns_validation_error_for_missing_password(): void
    {
        $user = User::factory()->create();

        $this->travel(1)->seconds();

        $signedUrl = URL::temporarySignedRoute(
            'neev.resetPassword',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => hash('sha256', $user->email)]
        );

        $query = parse_url($signedUrl, PHP_URL_QUERY);

        $response = $this->postJson('/neev/resetPassword?' . $query, []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['password']);
    }

    public function test_reset_password_returns_validation_error_for_unconfirmed_password(): void
    {
        $user = User::factory()->create();

        $this->travel(1)->seconds();

        $signedUrl = URL::temporarySignedRoute(
            'neev.resetPassword',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => hash('sha256', $user->email)]
        );

        $query = parse_url($signedUrl, PHP_URL_QUERY);

        $response = $this->postJson('/neev/resetPassword?' . $query, [
            'password' => 'newpassword123',
            'password_confirmation' => 'differentpassword',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['password']);
    }

    public function test_reset_password_populates_password_history(): void
    {
        $user = User::factory()->create(['password' => 'original-password']);
        $originalHash = $user->getRawOriginal('password');

        $this->travel(1)->seconds();

        $signedUrl = URL::temporarySignedRoute(
            'neev.resetPassword',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => hash('sha256', $user->email)]
        );

        $query = parse_url($signedUrl, PHP_URL_QUERY);

        $response = $this->postJson('/neev/resetPassword?' . $query, [
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertOk();

        $user->refresh();
        $this->assertNotNull($user->password_history);
        $this->assertCount(1, $user->password_history);
        $this->assertSame($originalHash, $user->password_history[0]);
    }

    public function test_reset_password_rejects_reused_password(): void
    {
        config(['neev.password' => ['required', 'confirmed', PasswordHistory::notReused(3)]]);

        $user = User::factory()->create(['password' => 'original-password']);

        $this->travel(1)->seconds();

        $signedUrl = URL::temporarySignedRoute(
            'neev.resetPassword',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => hash('sha256', $user->email)]
        );

        $query = parse_url($signedUrl, PHP_URL_QUERY);

        $response = $this->postJson('/neev/resetPassword?' . $query, [
            'password' => 'original-password',
            'password_confirmation' => 'original-password',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['password']);
    }

    // -----------------------------------------------------------------
    // POST /neev/resetPassword — reset password via emailed code
    // -----------------------------------------------------------------

    private function requestResetCode(User $user): string
    {
        Mail::fake();

        $this->postJson('/neev/forgotPassword', ['email' => $user->email])->assertOk();

        $otp = null;
        Mail::assertSent(VerifyUserEmail::class, function (VerifyUserEmail $mail) use (&$otp) {
            $otp = $mail->otp;

            return $mail->purpose === 'Reset Password' && $mail->url !== null;
        });
        $this->assertNotNull($otp);

        return (string) $otp;
    }

    public function test_forgot_password_sends_link_and_code_in_one_email(): void
    {
        $user = User::factory()->create();

        $this->requestResetCode($user);

        Mail::assertSentCount(1);
        $this->assertSame(1, OTP::where('owner_id', $user->id)->count());
    }

    public function test_successful_password_reset_with_code(): void
    {
        $user = User::factory()->create();
        $otp = $this->requestResetCode($user);

        $this->postJson('/neev/resetPassword', [
            'email' => $user->email,
            'otp' => $otp,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertOk()->assertJson(['message' => 'Password has been updated.']);

        $this->assertTrue(Hash::check('newpassword123', $user->fresh()->getRawOriginal('password')));
        $this->assertSame(0, OTP::where('owner_id', $user->id)->count());
    }

    public function test_code_resets_the_password_only_once(): void
    {
        $user = User::factory()->create();
        $otp = $this->requestResetCode($user);

        $payload = [
            'email' => $user->email,
            'otp' => $otp,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ];

        $this->postJson('/neev/resetPassword', $payload)->assertOk();
        $this->postJson('/neev/resetPassword', ['password' => 'another123', 'password_confirmation' => 'another123'] + $payload)
            ->assertForbidden();
    }

    public function test_reset_with_wrong_code_is_rejected(): void
    {
        $user = User::factory()->create(['password' => 'original-password']);
        $this->requestResetCode($user);

        $this->postJson('/neev/resetPassword', [
            'email' => $user->email,
            'otp' => '000000',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertForbidden()->assertJson(['message' => 'Invalid or expired code.']);

        $this->assertTrue(Hash::check('original-password', $user->fresh()->getRawOriginal('password')));
    }

    public function test_reset_with_code_for_unknown_email_is_indistinguishable_from_wrong_code(): void
    {
        $this->postJson('/neev/resetPassword', [
            'email' => 'nobody@example.com',
            'otp' => '123456',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertForbidden()->assertJson(['message' => 'Invalid or expired code.']);
    }

    public function test_reset_without_link_or_code_is_a_validation_error(): void
    {
        $this->postJson('/neev/resetPassword', [
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email', 'otp']);
    }

    public function test_password_rules_do_not_run_before_the_code_is_proven(): void
    {
        // Run first, the history rule would answer "is this the account's
        // password?" for anyone who names the address.
        config(['neev.password' => ['required', 'confirmed', PasswordHistory::notReused(3)]]);

        $user = User::factory()->create(['password' => 'original-password']);
        $this->requestResetCode($user);

        $this->postJson('/neev/resetPassword', [
            'email' => $user->email,
            'otp' => '000000',
            'password' => 'original-password',
            'password_confirmation' => 'original-password',
        ])->assertForbidden();
    }

    public function test_rejected_password_does_not_spend_the_code(): void
    {
        config(['neev.password' => ['required', 'confirmed', PasswordHistory::notReused(3)]]);

        $user = User::factory()->create(['password' => 'original-password']);
        $otp = $this->requestResetCode($user);

        $this->postJson('/neev/resetPassword', [
            'email' => $user->email,
            'otp' => $otp,
            'password' => 'original-password',
            'password_confirmation' => 'original-password',
        ])->assertUnprocessable()->assertJsonValidationErrors(['password']);

        $this->postJson('/neev/resetPassword', [
            'email' => $user->email,
            'otp' => $otp,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertOk();
    }

    public function test_rejected_passwords_do_not_wear_down_the_code(): void
    {
        $user = User::factory()->create();
        $otp = $this->requestResetCode($user);

        // More rejections than the code has guesses: only wrong codes count.
        for ($i = 0; $i <= OTP::MAX_ATTEMPTS; $i++) {
            $this->postJson('/neev/resetPassword', [
                'email' => $user->email,
                'otp' => $otp,
                'password' => 'newpassword123',
                'password_confirmation' => 'mismatch',
            ])->assertUnprocessable()->assertJsonValidationErrors(['password']);
        }

        $this->postJson('/neev/resetPassword', [
            'email' => $user->email,
            'otp' => $otp,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertOk();
    }

    public function test_code_reset_verifies_an_unverified_address(): void
    {
        $user = User::factory()->unverified()->create();
        $otp = $this->requestResetCode($user);

        $this->postJson('/neev/resetPassword', [
            'email' => $user->email,
            'otp' => $otp,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertOk();

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_link_reset_discards_the_code_sent_with_it(): void
    {
        $user = User::factory()->create();
        $otp = $this->requestResetCode($user);

        $this->travel(1)->seconds();

        $signedUrl = URL::temporarySignedRoute(
            'neev.resetPassword',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => hash('sha256', $user->email)]
        );

        $this->postJson('/neev/resetPassword?' . parse_url($signedUrl, PHP_URL_QUERY), [
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertOk();

        $this->postJson('/neev/resetPassword', [
            'email' => $user->email,
            'otp' => $otp,
            'password' => 'another123',
            'password_confirmation' => 'another123',
        ])->assertForbidden();
    }

    // -----------------------------------------------------------------
    // Per-account limits
    // -----------------------------------------------------------------

    private function wrongGuesses(User $user, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->postJson('/neev/resetPassword', [
                'email' => $user->email,
                'otp' => 'wrong-' . $i,
                'password' => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ]);
        }
    }

    public function test_an_account_is_sent_only_so_many_resets_whoever_asks(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < AuthService::PASSWORD_RESET_SEND_LIMIT; $i++) {
            $otp = $this->requestResetCode($user);
        }

        Mail::fake();
        $this->postJson('/neev/forgotPassword', ['email' => $user->email])
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonStructure(['message', 'retry_after']);
        Mail::assertNothingSent();

        // The refusal replaced nothing: the last code still works.
        $this->postJson('/neev/resetPassword', [
            'email' => $user->email,
            'otp' => $otp,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertOk();
    }

    public function test_wrong_codes_count_against_the_account_across_codes(): void
    {
        // More requests than the per-IP route limit allows; the per-account
        // limits under test are separate from it.
        $this->withoutMiddleware(ThrottleRequests::class);

        // A new email brings a new code with a new allowance of 5; without an
        // account-wide count, asking again and again gave unlimited guesses.
        $user = User::factory()->create(['password' => 'original-password']);

        $this->requestResetCode($user);
        $this->wrongGuesses($user, 5);
        $this->requestResetCode($user);
        $this->wrongGuesses($user, AuthService::PASSWORD_RESET_GUESS_LIMIT - 5);

        $otp = $this->requestResetCode($user);

        $this->postJson('/neev/resetPassword', [
            'email' => $user->email,
            'otp' => $otp,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertStatus(429)->assertHeader('Retry-After');

        $this->assertTrue(Hash::check('original-password', $user->fresh()->getRawOriginal('password')));
    }

    public function test_the_link_still_works_once_codes_are_locked_out(): void
    {
        // More requests than the per-IP route limit allows; the per-account
        // limits under test are separate from it.
        $this->withoutMiddleware(ThrottleRequests::class);

        $user = User::factory()->create();
        $this->requestResetCode($user);
        $this->wrongGuesses($user, 5);
        $this->requestResetCode($user);
        $this->wrongGuesses($user, AuthService::PASSWORD_RESET_GUESS_LIMIT - 5);

        $this->postJson('/neev/resetPassword?' . $this->resetQuery($user), [
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertOk();
    }

    public function test_a_successful_reset_gives_the_account_its_allowances_back(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < AuthService::PASSWORD_RESET_SEND_LIMIT; $i++) {
            $otp = $this->requestResetCode($user);
        }

        $this->postJson('/neev/resetPassword', [
            'email' => $user->email,
            'otp' => $otp,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertOk();

        $this->requestResetCode($user);
    }

    // -----------------------------------------------------------------
    // A link dies with the password it was sent to replace
    // -----------------------------------------------------------------

    private function resetQuery(User $user): string
    {
        $this->travel(1)->seconds();
        $url = URL::temporarySignedRoute(
            'neev.resetPassword',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => hash('sha256', $user->email)]
        );

        return (string) parse_url($url, PHP_URL_QUERY);
    }

    public function test_a_link_resets_the_password_only_once(): void
    {
        $user = User::factory()->create();
        $query = $this->resetQuery($user);

        $this->postJson('/neev/resetPassword?' . $query, [
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertOk();

        $this->postJson('/neev/resetPassword?' . $query, [
            'password' => 'another123',
            'password_confirmation' => 'another123',
        ])->assertForbidden()->assertJson(['message' => 'Invalid or expired reset link.']);

        $this->assertTrue(Hash::check('newpassword123', $user->fresh()->getRawOriginal('password')));
    }

    public function test_a_code_reset_retires_the_link_sent_with_it(): void
    {
        $user = User::factory()->create();
        $otp = $this->requestResetCode($user);
        $query = $this->resetQuery($user);

        $this->postJson('/neev/resetPassword', [
            'email' => $user->email,
            'otp' => $otp,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertOk();

        $this->postJson('/neev/resetPassword?' . $query, [
            'password' => 'another123',
            'password_confirmation' => 'another123',
        ])->assertForbidden();
    }

    public function test_any_password_change_retires_outstanding_links(): void
    {
        $user = User::factory()->create();
        $query = $this->resetQuery($user);

        app(AuthService::class)->changePassword($user, 'changed-elsewhere');

        $this->postJson('/neev/resetPassword?' . $query, [
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertForbidden();
    }
}
