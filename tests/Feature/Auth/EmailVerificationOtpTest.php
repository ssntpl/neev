<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Ssntpl\Neev\Mail\VerifyUserEmail;
use Ssntpl\Neev\Models\OTP;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\AuthService;
use Ssntpl\Neev\Tests\TestCase;

class EmailVerificationOtpTest extends TestCase
{
    use RefreshDatabase;

    private function unverifiedUserWithToken(): array
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $token = $user->createLoginToken(1440)->plainTextToken;

        return ['user' => $user, 'token' => $token];
    }

    /**
     * Send the verification mail and capture the plaintext OTP from it.
     */
    private function sendAndCaptureOtp(User $user): string
    {
        Mail::fake();
        app(AuthService::class)->sendEmailVerification($user);

        $otp = null;
        Mail::assertSent(VerifyUserEmail::class, function (VerifyUserEmail $mail) use (&$otp) {
            $otp = $mail->otp;
            return true;
        });

        $this->assertNotNull($otp);

        return $otp;
    }

    // -----------------------------------------------------------------
    // The email carries both proofs
    // -----------------------------------------------------------------

    public function test_verification_email_contains_link_and_code(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email_verified_at' => null]);

        app(AuthService::class)->sendEmailVerification($user);

        Mail::assertSent(VerifyUserEmail::class, function (VerifyUserEmail $mail) {
            return !empty($mail->url)
                && is_string($mail->otp)
                && strlen($mail->otp) === (int) config('neev.otp_length', 6);
        });

        $this->assertDatabaseHas('otp', [
            'owner_id' => $user->id,
            'owner_type' => $user->getMorphClass(),
        ]);
    }

    public function test_resend_replaces_the_code_and_resets_attempts(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $first = $this->sendAndCaptureOtp($user);
        OTP::query()->update(['attempts' => 3]);

        $second = $this->sendAndCaptureOtp($user);

        $this->assertSame(1, OTP::count());
        $this->assertSame(0, OTP::first()->attempts);
        // Verification only accepts the latest code.
        $this->assertFalse(app(AuthService::class)->verifyEmailOtp($user->fresh(), $first === $second ? '000000' : $first));
    }

    // -----------------------------------------------------------------
    // API endpoint
    // -----------------------------------------------------------------

    public function test_valid_code_verifies_the_email(): void
    {
        $data = $this->unverifiedUserWithToken();
        $otp = $this->sendAndCaptureOtp($data['user']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $data['token'])
            ->postJson('/neev/email/verify-otp', ['otp' => $otp]);

        $response->assertOk();
        $this->assertTrue($data['user']->fresh()->hasVerifiedEmail());
        $this->assertSame(0, OTP::count());
    }

    public function test_wrong_code_fails_and_counts_an_attempt(): void
    {
        $data = $this->unverifiedUserWithToken();
        $this->sendAndCaptureOtp($data['user']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $data['token'])
            ->postJson('/neev/email/verify-otp', ['otp' => '000000']);

        $response->assertStatus(400);
        $this->assertFalse($data['user']->fresh()->hasVerifiedEmail());
        $this->assertSame(1, OTP::first()->attempts);
    }

    public function test_code_is_invalidated_after_max_attempts(): void
    {
        $data = $this->unverifiedUserWithToken();
        $otp = $this->sendAndCaptureOtp($data['user']);
        OTP::query()->update(['attempts' => OTP::MAX_ATTEMPTS - 1]);

        // One more wrong attempt exhausts the cap and deletes the code.
        $this->withHeader('Authorization', 'Bearer ' . $data['token'])
            ->postJson('/neev/email/verify-otp', ['otp' => '000000'])
            ->assertStatus(400);
        $this->assertSame(0, OTP::count());

        // Even the correct code is dead now.
        $this->withHeader('Authorization', 'Bearer ' . $data['token'])
            ->postJson('/neev/email/verify-otp', ['otp' => $otp])
            ->assertStatus(400);
        $this->assertFalse($data['user']->fresh()->hasVerifiedEmail());
    }

    public function test_expired_code_fails(): void
    {
        $data = $this->unverifiedUserWithToken();
        $otp = $this->sendAndCaptureOtp($data['user']);
        OTP::query()->update(['expires_at' => now()->subMinute()]);

        $this->withHeader('Authorization', 'Bearer ' . $data['token'])
            ->postJson('/neev/email/verify-otp', ['otp' => $otp])
            ->assertStatus(400);

        $this->assertSame(0, OTP::count());
    }

    public function test_already_verified_email_returns_400(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $token = $user->createLoginToken(1440)->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/email/verify-otp', ['otp' => '123456'])
            ->assertStatus(400);
    }

    // -----------------------------------------------------------------
    // Mutual invalidation: the signed link kills the code
    // -----------------------------------------------------------------

    public function test_link_verification_invalidates_the_outstanding_code(): void
    {
        $data = $this->unverifiedUserWithToken();
        $this->sendAndCaptureOtp($data['user']);
        $this->assertSame(1, OTP::count());

        $data['user']->fresh()->markEmailAsVerified();

        $this->assertSame(0, OTP::count());
    }

    // -----------------------------------------------------------------
    // Reset / email-change mails carry no code
    // -----------------------------------------------------------------

    public function test_password_reset_mail_carries_no_otp(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->postJson('/neev/forgotPassword', ['email' => $user->email])->assertOk();

        Mail::assertSent(VerifyUserEmail::class, function (VerifyUserEmail $mail) {
            return $mail->otp === null;
        });
    }
}
