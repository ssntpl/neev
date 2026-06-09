<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use OTPHP\TOTP;
use ParagonIE\ConstantTime\Base32;
use Ssntpl\Neev\Mail\EmailOTP;
use Ssntpl\Neev\Models\LoginAttempt;
use Ssntpl\Neev\Models\MultiFactorAuth;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithMfaJwtToken;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class MFATest extends TestCase
{
    use RefreshDatabase;
    use WithMfaJwtToken;
    use WithNeevConfig;

    /**
     * Create a user with an MFA JWT token (post-login pre-MFA state).
     */
    private function createUserWithMFAToken(string $mfaMethod = 'authenticator', ?string $secret = null): array
    {
        $user = User::factory()->create();

        $secret = $secret ?? Base32::encodeUpper(random_bytes(32));

        $user->multiFactorAuths()->create([
            'method' => $mfaMethod,
            'secret' => $secret,
            'status' => MultiFactorAuth::STATUS_ACTIVE,
        ]);

        $attempt = $user->loginAttempts()->create([
            'method' => LoginAttempt::Password,
            'multi_factor_method' => $mfaMethod,
            'is_success' => false,
        ]);

        $fullToken = $this->createMfaJwtToken($user->id, $attempt->id);

        return [
            'user' => $user,
            'plainTextToken' => $fullToken,
            'attempt' => $attempt,
            'secret' => $secret,
        ];
    }

    // -----------------------------------------------------------------
    // POST /neev/mfa/otp/verify (authenticator)
    // -----------------------------------------------------------------

    public function test_successful_authenticator_mfa_verification_returns_token(): void
    {
        $this->enableMFA();

        $data = $this->createUserWithMFAToken('authenticator');

        $totp = TOTP::create($data['secret']);
        $validOTP = $totp->now();

        $response = $this->withHeader('Authorization', 'Bearer ' . $data['plainTextToken'])
            ->postJson('/neev/mfa/otp/verify', [
                'auth_method' => 'authenticator',
                'otp' => $validOTP,
            ]);

        $response->assertOk();
        $response->assertJson([
            'auth_state' => 'authenticated',
            'expires_in' => config('neev.login_token_expiry_minutes', 1440),
            'email_verified' => true,
        ]);
        $this->assertNotEmpty($response->json('token'));

        $this->assertDatabaseHas('login_attempts', [
            'id' => $data['attempt']->id,
            'is_success' => true,
        ]);
    }

    public function test_failed_authenticator_mfa_returns_400(): void
    {
        $this->enableMFA();

        $data = $this->createUserWithMFAToken('authenticator');

        $response = $this->withHeader('Authorization', 'Bearer ' . $data['plainTextToken'])
            ->postJson('/neev/mfa/otp/verify', [
                'auth_method' => 'authenticator',
                'otp' => '000000',
            ]);

        $response->assertStatus(400);
        $response->assertJson([
            'message' => 'Code verification failed.',
        ]);

        $this->assertDatabaseHas('login_attempts', [
            'id' => $data['attempt']->id,
            'is_success' => false,
        ]);
    }

    public function test_mfa_verification_can_target_a_specific_authenticator_by_id(): void
    {
        $this->enableMFA();

        $data = $this->createUserWithMFAToken('authenticator');
        $user = $data['user'];

        // A second authenticator with its own secret.
        $secondSecret = Base32::encodeUpper(random_bytes(32));
        $second = $user->multiFactorAuths()->create([
            'method' => 'authenticator',
            'name' => 'Second phone',
            'secret' => $secondSecret,
            'status' => MultiFactorAuth::STATUS_ACTIVE,
        ]);

        $secondCode = TOTP::create($secondSecret)->now();

        // Pinned to the second instance — its code authenticates.
        $this->withHeader('Authorization', 'Bearer ' . $data['plainTextToken'])
            ->postJson('/neev/mfa/otp/verify', [
                'auth_method' => 'authenticator',
                'otp' => $secondCode,
                'id' => $second->id,
            ])
            ->assertOk()
            ->assertJsonPath('auth_state', 'authenticated');
    }

    public function test_mfa_verification_with_id_rejects_code_from_another_instance(): void
    {
        $this->enableMFA();

        $data = $this->createUserWithMFAToken('authenticator');
        $user = $data['user'];

        $secondSecret = Base32::encodeUpper(random_bytes(32));
        $second = $user->multiFactorAuths()->create([
            'method' => 'authenticator',
            'name' => 'Second phone',
            'secret' => $secondSecret,
            'status' => MultiFactorAuth::STATUS_ACTIVE,
        ]);

        // Code from the FIRST authenticator, but pinned to the second instance.
        $firstCode = TOTP::create($data['secret'])->now();

        $this->withHeader('Authorization', 'Bearer ' . $data['plainTextToken'])
            ->postJson('/neev/mfa/otp/verify', [
                'auth_method' => 'authenticator',
                'otp' => $firstCode,
                'id' => $second->id,
            ])
            ->assertStatus(400);
    }

    public function test_mfa_verification_treats_empty_id_as_unpinned(): void
    {
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class);

        $this->enableMFA();

        $data = $this->createUserWithMFAToken('authenticator');

        $code = TOTP::create($data['secret'])->now();

        $this->withHeader('Authorization', 'Bearer ' . $data['plainTextToken'])
            ->postJson('/neev/mfa/otp/verify', [
                'auth_method' => 'authenticator',
                'otp' => $code,
                'id' => '',
            ])
            ->assertOk()
            ->assertJsonPath('auth_state', 'authenticated');
    }

    // -----------------------------------------------------------------
    // POST /neev/mfa/otp/verify (email)
    // -----------------------------------------------------------------

    public function test_successful_email_mfa_verification(): void
    {
        $this->enableMFA();

        $user = User::factory()->create();

        $otpPlaintext = '654321';
        $user->multiFactorAuths()->create([
            'method' => 'email',
            'otp' => $otpPlaintext,
            'expires_at' => now()->addMinutes(15),
            'status' => MultiFactorAuth::STATUS_ACTIVE,
        ]);

        $attempt = $user->loginAttempts()->create([
            'method' => LoginAttempt::Password,
            'multi_factor_method' => 'email',
            'is_success' => false,
        ]);
        $fullToken = $this->createMfaJwtToken($user->id, $attempt->id);

        $response = $this->withHeader('Authorization', 'Bearer ' . $fullToken)
            ->postJson('/neev/mfa/otp/verify', [
                'auth_method' => 'email',
                'otp' => $otpPlaintext,
            ]);

        $response->assertOk();
        $response->assertJson([
            'auth_state' => 'authenticated',
            'expires_in' => config('neev.login_token_expiry_minutes', 1440),
            'email_verified' => true,
        ]);

        $this->assertDatabaseHas('login_attempts', [
            'id' => $attempt->id,
            'is_success' => true,
        ]);
    }

    public function test_expired_email_mfa_otp_returns_400(): void
    {
        $this->enableMFA();

        $user = User::factory()->create();

        $otpPlaintext = '654321';
        $user->multiFactorAuths()->create([
            'method' => 'email',
            'otp' => $otpPlaintext,
            'expires_at' => now()->subMinutes(5),
            'status' => MultiFactorAuth::STATUS_ACTIVE,
        ]);

        $attempt = $user->loginAttempts()->create([
            'method' => LoginAttempt::Password,
            'multi_factor_method' => 'email',
            'is_success' => false,
        ]);
        $fullToken = $this->createMfaJwtToken($user->id, $attempt->id);

        $response = $this->withHeader('Authorization', 'Bearer ' . $fullToken)
            ->postJson('/neev/mfa/otp/verify', [
                'auth_method' => 'email',
                'otp' => $otpPlaintext,
            ]);

        $response->assertStatus(400);
    }

    // -----------------------------------------------------------------
    // POST /neev/mfa/otp/verify (recovery code)
    // -----------------------------------------------------------------

    public function test_successful_recovery_code_verification(): void
    {
        $this->enableMFA();

        $user = User::factory()->create();

        $user->multiFactorAuths()->create([
            'method' => 'authenticator',
            'secret' => Base32::encodeUpper(random_bytes(32)),
            'status' => MultiFactorAuth::STATUS_ACTIVE,
        ]);

        config(['neev.recovery_codes' => 8]);
        $codes = $user->generateRecoveryCodes();
        $validCode = $codes[0];

        $attempt = $user->loginAttempts()->create([
            'method' => LoginAttempt::Password,
            'multi_factor_method' => 'recovery',
            'is_success' => false,
        ]);
        $fullToken = $this->createMfaJwtToken($user->id, $attempt->id);

        $response = $this->withHeader('Authorization', 'Bearer ' . $fullToken)
            ->postJson('/neev/mfa/otp/verify', [
                'auth_method' => 'recovery',
                'otp' => $validCode,
            ]);

        $response->assertOk();
        $response->assertJson([
            'auth_state' => 'authenticated',
            'expires_in' => config('neev.login_token_expiry_minutes', 1440),
            'email_verified' => true,
        ]);

        $this->assertDatabaseHas('login_attempts', [
            'id' => $attempt->id,
            'is_success' => true,
        ]);
    }

    public function test_invalid_recovery_code_returns_400(): void
    {
        $this->enableMFA();

        $user = User::factory()->create();

        $user->multiFactorAuths()->create([
            'method' => 'authenticator',
            'secret' => Base32::encodeUpper(random_bytes(32)),
            'status' => MultiFactorAuth::STATUS_ACTIVE,
        ]);

        config(['neev.recovery_codes' => 8]);
        $user->generateRecoveryCodes();

        $attempt = $user->loginAttempts()->create([
            'method' => LoginAttempt::Password,
            'multi_factor_method' => 'recovery',
            'is_success' => false,
        ]);
        $fullToken = $this->createMfaJwtToken($user->id, $attempt->id);

        $response = $this->withHeader('Authorization', 'Bearer ' . $fullToken)
            ->postJson('/neev/mfa/otp/verify', [
                'auth_method' => 'recovery',
                'otp' => 'invalidcode123',
            ]);

        $response->assertStatus(400);
        $response->assertJson([
            'message' => 'Code verification failed.',
        ]);
    }

    // -----------------------------------------------------------------
    // Edge cases
    // -----------------------------------------------------------------

    public function test_mfa_verify_returns_403_for_nonexistent_user(): void
    {
        $user = User::factory()->create();

        $attempt = $user->loginAttempts()->create([
            'method' => LoginAttempt::Password,
            'multi_factor_method' => 'authenticator',
            'is_success' => false,
        ]);
        $fullToken = $this->createMfaJwtToken($user->id, $attempt->id);

        $user->forceDelete();

        $response = $this->withHeader('Authorization', 'Bearer ' . $fullToken)
            ->postJson('/neev/mfa/otp/verify', [
                'auth_method' => 'authenticator',
                'otp' => '123456',
            ]);

        $response->assertStatus(403);
    }

    public function test_mfa_verify_without_token_returns_401(): void
    {
        $response = $this->postJson('/neev/mfa/otp/verify', [
            'auth_method' => 'authenticator',
            'otp' => '123456',
        ]);

        $response->assertStatus(401);
    }

    public function test_mfa_token_cannot_access_non_mfa_endpoints(): void
    {
        $user = User::factory()->create();

        $attempt = $user->loginAttempts()->create([
            'method' => LoginAttempt::Password,
            'multi_factor_method' => 'authenticator',
            'is_success' => false,
        ]);
        $fullToken = $this->createMfaJwtToken($user->id, $attempt->id);

        $response = $this->withHeader('Authorization', 'Bearer ' . $fullToken)
            ->postJson('/neev/logout');

        $response->assertStatus(401);
    }

    public function test_resend_email_otp_targets_chosen_email_instance(): void
    {
        Mail::fake();
        $this->enableMFA();

        $user = User::factory()->create();
        // A non-account email factor, already active.
        $auth = $user->multiFactorAuths()->create([
            'method' => 'email',
            'name' => 'Backup',
            'email' => 'backup@example.com',
            'status' => MultiFactorAuth::STATUS_ACTIVE,
        ]);
        $attempt = $user->loginAttempts()->create([
            'method' => LoginAttempt::Password,
            'multi_factor_method' => 'email',
            'is_success' => false,
        ]);
        $token = $this->createMfaJwtToken($user->id, $attempt->id);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/mfa/otp/send', ['id' => $auth->id])
            ->assertOk()
            ->assertJsonPath('status', 'Success');

        // The code went to the chosen instance's address, not the account email.
        Mail::assertSent(EmailOTP::class, fn ($mail) => $mail->hasTo('backup@example.com'));
    }
}
