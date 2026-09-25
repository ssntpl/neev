<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Ssntpl\Neev\Enums\OtpPurpose;
use Ssntpl\Neev\Mail\EmailOTP;
use Ssntpl\Neev\Mail\VerifyUserEmail;
use Ssntpl\Neev\Models\OTP;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\AuthService;
use Ssntpl\Neev\Tests\TestCase;

/**
 * A code is issued for one purpose and checked only against it, and a user
 * holds one live code per purpose — so asking for one never cancels another.
 */
class OtpPurposeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['neev.password' => ['required', 'confirmed']]);
        Mail::fake();
    }

    private function auth(): AuthService
    {
        return app(AuthService::class);
    }

    private function verificationCode(User $user): string
    {
        $this->auth()->sendEmailVerification($user);

        return $this->lastCode(VerifyUserEmail::class);
    }

    private function resetCode(User $user): string
    {
        $this->auth()->sendPasswordReset($user);

        return $this->lastCode(VerifyUserEmail::class);
    }

    private function confirmationCode(User $user): string
    {
        $this->auth()->sendConfirmationOtp($user);

        return $this->lastCode(EmailOTP::class);
    }

    /** @param class-string $mailable */
    private function lastCode(string $mailable): string
    {
        return (string) Mail::sent($mailable)->last()->otp;
    }

    private function resetWith(User $user, string $otp)
    {
        return $this->postJson('/neev/resetPassword', [
            'email' => $user->email,
            'otp' => $otp,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);
    }

    public function test_each_code_is_stored_under_its_purpose(): void
    {
        $user = User::factory()->unverified()->create();

        $this->verificationCode($user);
        $this->resetCode($user);
        $this->confirmationCode($user);

        $this->assertEqualsCanonicalizing(
            [OtpPurpose::EmailVerification, OtpPurpose::PasswordReset, OtpPurpose::Confirmation],
            OTP::where('owner_id', $user->id)->get()->pluck('purpose')->all(),
        );
    }

    public function test_a_verification_code_cannot_reset_the_password(): void
    {
        $user = User::factory()->unverified()->create(['password' => 'original-password']);
        $otp = $this->verificationCode($user);

        $this->resetWith($user, $otp)->assertForbidden();

        $this->assertTrue(Hash::check('original-password', $user->fresh()->getRawOriginal('password')));
    }

    public function test_a_confirmation_code_cannot_reset_the_password(): void
    {
        $user = User::factory()->create(['password' => 'original-password']);
        $otp = $this->confirmationCode($user);

        $this->resetWith($user, $otp)->assertForbidden();

        $this->assertTrue(Hash::check('original-password', $user->fresh()->getRawOriginal('password')));
    }

    public function test_a_reset_code_neither_verifies_nor_confirms(): void
    {
        $user = User::factory()->create();
        $otp = $this->resetCode($user);

        $this->assertFalse($this->auth()->verifyEmailOtp($user, $otp, OtpPurpose::EmailVerification));
        $this->assertFalse($this->auth()->verifyEmailOtp($user, $otp, OtpPurpose::Confirmation));
        $this->assertTrue($this->auth()->verifyEmailOtp($user, $otp, OtpPurpose::PasswordReset));
    }

    public function test_asking_for_a_code_leaves_other_purposes_working(): void
    {
        $user = User::factory()->unverified()->create();
        $reset = $this->resetCode($user);
        $verification = $this->verificationCode($user);
        $this->confirmationCode($user);

        $this->assertTrue($this->auth()->verifyEmailOtp($user, $verification, OtpPurpose::EmailVerification));
        $this->resetWith($user->fresh(), $reset)->assertOk();
    }

    public function test_verifying_the_email_keeps_a_pending_reset_code(): void
    {
        $user = User::factory()->unverified()->create();
        $reset = $this->resetCode($user);

        $user->markEmailAsVerified();

        $this->assertSame(1, OTP::query()->forPurpose($user, OtpPurpose::PasswordReset)->count());
        $this->resetWith($user->fresh(), $reset)->assertOk();
    }

    public function test_verifying_the_email_discards_the_verification_code(): void
    {
        $user = User::factory()->unverified()->create();
        $this->verificationCode($user);

        $user->markEmailAsVerified();

        $this->assertSame(0, OTP::query()->forPurpose($user, OtpPurpose::EmailVerification)->count());
    }

    public function test_completing_a_reset_keeps_a_pending_confirmation_code(): void
    {
        $user = User::factory()->create();
        $confirmation = $this->confirmationCode($user);
        $reset = $this->resetCode($user);

        $this->resetWith($user, $reset)->assertOk();

        $this->assertSame(0, OTP::query()->forPurpose($user, OtpPurpose::PasswordReset)->count());
        $this->assertTrue($this->auth()->verifyEmailOtp($user->fresh(), $confirmation, OtpPurpose::Confirmation));
    }

    public function test_wrong_guesses_wear_down_only_their_own_purpose(): void
    {
        $user = User::factory()->create();
        $confirmation = $this->confirmationCode($user);
        $this->verificationCode($user);

        for ($i = 0; $i < OTP::MAX_ATTEMPTS; $i++) {
            $this->auth()->verifyEmailOtp($user, '000000', OtpPurpose::EmailVerification);
        }

        $this->assertSame(0, OTP::query()->forPurpose($user, OtpPurpose::EmailVerification)->count());
        $this->assertTrue($this->auth()->verifyEmailOtp($user, $confirmation, OtpPurpose::Confirmation));
    }

    public function test_changing_the_email_discards_codes_sent_to_the_old_address(): void
    {
        $user = User::factory()->create(['password' => 'original-password']);
        $reset = $this->resetCode($user);
        $this->confirmationCode($user);

        $this->assertTrue($this->auth()->applyEmailChange($user, 'moved@example.com'));

        $this->assertSame(0, OTP::query()->forOwner($user)->count());
        $this->resetWith($user->fresh(), $reset)->assertForbidden();
        $this->assertTrue(Hash::check('original-password', $user->fresh()->getRawOriginal('password')));
    }

    public function test_the_api_email_change_link_discards_outstanding_codes(): void
    {
        $user = User::factory()->create();
        $this->resetCode($user);
        $this->confirmationCode($user);

        $query = (string) parse_url(URL::temporarySignedRoute(
            'neev.email.change.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'email' => 'moved@example.com'],
        ), PHP_URL_QUERY);

        $this->getJson('/neev/email/change/verify?' . $query)->assertOk();

        $this->assertSame('moved@example.com', $user->fresh()->email);
        $this->assertSame(0, OTP::query()->forOwner($user)->count());
    }

    public function test_the_blade_email_change_link_discards_outstanding_codes(): void
    {
        $user = User::factory()->create();
        $this->resetCode($user);
        $this->confirmationCode($user);

        $this->get(URL::temporarySignedRoute(
            'email.change.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'email' => 'moved@example.com'],
        ))->assertSessionHasNoErrors();

        $this->assertSame('moved@example.com', $user->fresh()->email);
        $this->assertSame(0, OTP::query()->forOwner($user)->count());
    }

    public function test_the_password_change_route_discards_a_pending_reset_code(): void
    {
        $user = User::factory()->create(['password' => 'original-password']);
        $reset = $this->resetCode($user);

        $this->withHeader('Authorization', 'Bearer ' . $user->createLoginToken(60)->plainTextToken)
            ->putJson('/neev/changePassword', [
                'current_password' => 'original-password',
                'password' => 'changed-in-settings',
                'password_confirmation' => 'changed-in-settings',
            ])->assertOk();

        $this->assertSame(0, OTP::query()->forPurpose($user, OtpPurpose::PasswordReset)->count());
        $this->resetWith($user->fresh(), $reset)->assertForbidden();
    }

    public function test_deleting_the_account_deletes_its_codes(): void
    {
        $user = User::factory()->create(['password' => 'original-password']);
        $this->resetCode($user);
        $this->verificationCode($user);

        $this->withHeader('Authorization', 'Bearer ' . $user->createLoginToken(60)->plainTextToken)
            ->deleteJson('/neev/users', ['password' => 'original-password'])
            ->assertOk();

        $this->assertNull(User::model()->find($user->id));
        $this->assertSame(0, OTP::where('owner_id', $user->id)->count());
    }

    public function test_changing_the_password_discards_a_pending_reset_code(): void
    {
        $user = User::factory()->create();
        $reset = $this->resetCode($user);
        $confirmation = $this->confirmationCode($user);

        $this->auth()->changePassword($user, 'changed-in-settings');

        $this->resetWith($user->fresh(), $reset)->assertForbidden();
        $this->assertTrue(Hash::check('changed-in-settings', $user->fresh()->getRawOriginal('password')));
        // Only the reset code goes; a confirmation in flight is not a reset.
        $this->assertTrue($this->auth()->verifyEmailOtp($user->fresh(), $confirmation, OtpPurpose::Confirmation));
    }

    public function test_an_owner_holds_one_row_per_purpose(): void
    {
        $user = User::factory()->create();
        $row = [
            'owner_id' => $user->id,
            'owner_type' => $user->getMorphClass(),
            'purpose' => OtpPurpose::Confirmation,
            'otp' => '123456',
            'expires_at' => now()->addMinutes(15),
        ];

        OTP::create($row);
        OTP::create(['purpose' => OtpPurpose::PasswordReset] + $row);

        $this->expectException(QueryException::class);
        OTP::create($row);
    }
}
