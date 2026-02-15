<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Ssntpl\Neev\Models\Email;
use Ssntpl\Neev\Models\User;
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

    /**
     * Create a user and set up an OTP for their primary email.
     *
     * The OTP model has a 'hashed' cast on the otp field, so we store
     * the plaintext and it gets hashed automatically. We return the
     * plaintext for use in assertions.
     */
    private function createUserWithOTP(string $otpPlaintext = '123456', int $expiresInMinutes = 15): array
    {
        $user = User::factory()->create();
        $email = $user->email;

        $email->otp()->create([
            'otp' => $otpPlaintext,
            'expires_at' => now()->addMinutes($expiresInMinutes),
        ]);

        return [
            'user' => $user,
            'email' => $email,
            'otp' => $otpPlaintext,
        ];
    }

    // -----------------------------------------------------------------
    // POST /neev/forgotPassword
    // -----------------------------------------------------------------

    public function test_successful_password_reset_with_valid_otp(): void
    {
        $data = $this->createUserWithOTP();

        $response = $this->postJson('/neev/forgotPassword', [
            'email' => $data['email']->email,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
            'otp' => $data['otp'],
        ]);

        $response->assertOk();
        $response->assertJson([
            'status' => 'Success',
            'message' => 'Password has been updated.',
        ]);

        // New password record should exist
        $user = $data['user']->fresh();
        $this->assertTrue(Hash::check('newpassword123', $user->password->password));

        // OTP should be cleaned up
        $this->assertNull($data['email']->fresh()->otp);
    }

    public function test_returns_error_for_non_existent_email(): void
    {
        $response = $this->postJson('/neev/forgotPassword', [
            'email' => 'nobody@example.com',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
            'otp' => '123456',
        ]);

        $response->assertOk(); // Controller returns 200 with status Failed
        $response->assertJson([
            'status' => 'Failed',
            'message' => 'Email not found',
        ]);
    }

    public function test_returns_error_for_invalid_otp(): void
    {
        $data = $this->createUserWithOTP('654321');

        $response = $this->postJson('/neev/forgotPassword', [
            'email' => $data['email']->email,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
            'otp' => '000000', // Wrong OTP
        ]);

        $response->assertOk();
        $response->assertJson([
            'status' => 'Failed',
            'message' => 'Code verification was failed.',
        ]);
    }

    public function test_returns_error_for_expired_otp(): void
    {
        $data = $this->createUserWithOTP('123456', -1); // Already expired

        $response = $this->postJson('/neev/forgotPassword', [
            'email' => $data['email']->email,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
            'otp' => $data['otp'],
        ]);

        $response->assertOk();
        $response->assertJson([
            'status' => 'Failed',
            'message' => 'Code verification was failed.',
        ]);
    }

    public function test_returns_validation_error_for_missing_otp(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/neev/forgotPassword', [
            'email' => $user->email->email,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['otp']);
    }

    public function test_returns_validation_error_for_missing_password(): void
    {
        $data = $this->createUserWithOTP();

        $response = $this->postJson('/neev/forgotPassword', [
            'email' => $data['email']->email,
            'otp' => $data['otp'],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['password']);
    }

    public function test_returns_validation_error_for_unconfirmed_password(): void
    {
        $data = $this->createUserWithOTP();

        $response = $this->postJson('/neev/forgotPassword', [
            'email' => $data['email']->email,
            'password' => 'newpassword123',
            'password_confirmation' => 'differentpassword',
            'otp' => $data['otp'],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['password']);
    }

    public function test_password_reset_creates_new_password_record(): void
    {
        $data = $this->createUserWithOTP();
        $initialPasswordCount = $data['user']->passwords()->count();

        $this->postJson('/neev/forgotPassword', [
            'email' => $data['email']->email,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
            'otp' => $data['otp'],
        ]);

        // A new password record is created (not updated in place)
        $this->assertEquals($initialPasswordCount + 1, $data['user']->passwords()->count());
    }

    public function test_otp_is_deleted_after_successful_reset(): void
    {
        $data = $this->createUserWithOTP();

        $this->assertNotNull($data['email']->otp);

        $this->postJson('/neev/forgotPassword', [
            'email' => $data['email']->email,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
            'otp' => $data['otp'],
        ]);

        $this->assertNull($data['email']->fresh()->otp);
    }
}
