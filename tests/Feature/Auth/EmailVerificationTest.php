<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Ssntpl\Neev\Models\Email;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    protected function authenticatedUser(): array
    {
        $user = User::factory()->create();
        $token = $user->createLoginToken(60);

        return [$user, $token->plainTextToken];
    }

    // -----------------------------------------------------------------
    // POST /neev/email/send — send mail verification link
    // -----------------------------------------------------------------

    public function test_send_verification_link_for_unverified_email(): void
    {
        Mail::fake();

        [$user, $token] = $this->authenticatedUser();

        // Make the user's email unverified
        $email = $user->email;
        $email->verified_at = null;
        $email->save();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/email/send', [
                'email' => $email->email,
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success');
    }

    public function test_send_verification_link_returns_error_for_unknown_email(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/email/send', [
                'email' => 'nonexistent@example.com',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Failed')
            ->assertJsonPath('message', 'Email not found.');
    }

    public function test_send_verification_link_returns_error_for_already_verified_email(): void
    {
        [$user, $token] = $this->authenticatedUser();

        // Factory creates verified emails by default
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/email/send', [
                'email' => $user->email->email,
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Failed')
            ->assertJsonPath('message', 'Email already verified.');
    }

    // -----------------------------------------------------------------
    // GET /neev/email/verify — verify email via signed URL
    // -----------------------------------------------------------------

    public function test_verify_email_with_valid_signed_url(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $email = $user->email;
        $email->verified_at = null;
        $email->save();

        $signedUrl = URL::temporarySignedRoute(
            'mail.verify',
            now()->addMinutes(60),
            ['id' => $email->id]
        );

        // Extract query parameters from the signed URL
        $query = parse_url($signedUrl, PHP_URL_QUERY);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/email/verify?' . $query);

        $response->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonPath('message', 'Email verification done.');

        $email->refresh();
        $this->assertNotNull($email->verified_at);
    }

    public function test_verify_email_rejects_invalid_signature(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $email = $user->email;
        $email->verified_at = null;
        $email->save();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/email/verify?id=' . $email->id . '&signature=invalidsig');

        $response->assertStatus(403)
            ->assertJsonPath('status', 'Failed');
    }

    public function test_verify_already_verified_email_returns_success(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $email = $user->email;

        $signedUrl = URL::temporarySignedRoute(
            'mail.verify',
            now()->addMinutes(60),
            ['id' => $email->id]
        );

        $query = parse_url($signedUrl, PHP_URL_QUERY);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/email/verify?' . $query);

        $response->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonPath('message', 'Email verification already done.');
    }

    // -----------------------------------------------------------------
    // POST /neev/email/otp/send — send OTP email (public endpoint)
    // -----------------------------------------------------------------

    public function test_send_email_otp_for_valid_email(): void
    {
        Mail::fake();

        $user = User::factory()->create();

        $response = $this->postJson('/neev/email/otp/send', [
            'email' => $user->email->email,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonPath('message', 'Verification code has been sent to your email.');
    }

    public function test_send_email_otp_returns_error_for_unknown_email(): void
    {
        $response = $this->postJson('/neev/email/otp/send', [
            'email' => 'nonexistent@example.com',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Failed')
            ->assertJsonPath('message', 'Email not found.');
    }

    // -----------------------------------------------------------------
    // POST /neev/email/otp/verify — verify email OTP (public endpoint)
    // -----------------------------------------------------------------

    public function test_verify_email_otp_with_correct_code(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $email = $user->email;

        // Create an OTP manually
        $otp = 123456;
        $email->otp()->create([
            'otp' => Hash::make((string) $otp),
            'expires_at' => now()->addMinutes(15),
        ]);

        $response = $this->postJson('/neev/email/otp/verify', [
            'email' => $email->email,
            'otp' => $otp,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success');
    }

    public function test_verify_email_otp_rejects_wrong_code(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $email = $user->email;

        // Create an OTP
        $email->otp()->create([
            'otp' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(15),
        ]);

        $response = $this->postJson('/neev/email/otp/verify', [
            'email' => $email->email,
            'otp' => '999999',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('status', 'Failed');
    }

    public function test_verify_email_otp_rejects_expired_code(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $email = $user->email;

        // Create an expired OTP
        $otp = 123456;
        $email->otp()->create([
            'otp' => Hash::make((string) $otp),
            'expires_at' => now()->subMinutes(1),
        ]);

        $response = $this->postJson('/neev/email/otp/verify', [
            'email' => $email->email,
            'otp' => $otp,
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('status', 'Failed');
    }
}
