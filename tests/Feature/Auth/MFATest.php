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
            'preferred' => true,
            'secret' => $secret,
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
            'preferred' => true,
            'otp' => $otpPlaintext,
            'expires_at' => now()->addMinutes(15),
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
            'preferred' => true,
            'otp' => $otpPlaintext,
            'expires_at' => now()->subMinutes(5),
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

    /**
     * The cap holds on the API path too: the last permitted wrong guess spends
     * the code, and the correct code arriving after it is refused.
     */
    public function test_email_mfa_otp_is_spent_after_max_attempts(): void
    {
        $this->enableMFA();

        $user = User::factory()->create();

        $otpPlaintext = '654321';
        $auth = $user->multiFactorAuths()->create([
            'method' => 'email',
            'preferred' => true,
            'otp' => $otpPlaintext,
            'expires_at' => now()->addMinutes(15),
            'attempts' => MultiFactorAuth::MAX_ATTEMPTS - 1,
        ]);

        $attempt = $user->loginAttempts()->create([
            'method' => LoginAttempt::Password,
            'multi_factor_method' => 'email',
            'is_success' => false,
        ]);
        $fullToken = $this->createMfaJwtToken($user->id, $attempt->id);

        $this->withHeader('Authorization', 'Bearer ' . $fullToken)
            ->postJson('/neev/mfa/otp/verify', ['auth_method' => 'email', 'otp' => '000000'])
            ->assertStatus(400);

        $this->assertNull($auth->fresh()->otp, 'The last permitted wrong guess spends the code.');

        $this->withHeader('Authorization', 'Bearer ' . $fullToken)
            ->postJson('/neev/mfa/otp/verify', ['auth_method' => 'email', 'otp' => $otpPlaintext])
            ->assertStatus(400);
    }

    /** An API password login issues a fresh code with a full budget. */
    public function test_api_login_issues_a_fresh_code_and_resets_attempts(): void
    {
        $this->enableMFA();

        $user = User::factory()->create(['password' => 'correct-password']);
        $auth = $user->multiFactorAuths()->create([
            'method' => 'email',
            'preferred' => true,
            'otp' => '654321',
            'expires_at' => now()->subMinute(),
            'attempts' => 3,
        ]);

        $this->postJson('/neev/login', ['email' => $user->email, 'password' => 'correct-password'])
            ->assertOk()
            ->assertJsonPath('auth_state', 'mfa_required');

        $fresh = $auth->fresh();
        $this->assertSame(0, $fresh->attempts);
        $this->assertTrue($fresh->expires_at->isFuture());
        $this->assertNotNull($fresh->otp);
    }

    // -----------------------------------------------------------------
    // POST /neev/mfa/otp/send
    // -----------------------------------------------------------------

    /** A resend mints a new code, restores the guess budget and mails it. */
    public function test_resend_issues_a_fresh_email_mfa_code(): void
    {
        $this->enableMFA();
        Mail::fake();

        $user = User::factory()->create();
        $auth = $user->multiFactorAuths()->create([
            'method' => 'email',
            'preferred' => true,
            'otp' => '654321',
            'expires_at' => now()->subMinute(),
            'attempts' => MultiFactorAuth::MAX_ATTEMPTS,
        ]);
        $stale = $auth->otp;

        $attempt = $user->loginAttempts()->create([
            'method' => LoginAttempt::Password,
            'multi_factor_method' => 'email',
            'is_success' => false,
        ]);
        $fullToken = $this->createMfaJwtToken($user->id, $attempt->id);

        $this->withHeader('Authorization', 'Bearer ' . $fullToken)
            ->postJson('/neev/mfa/otp/send')
            ->assertOk();

        $fresh = $auth->fresh();
        $this->assertSame(0, $fresh->attempts);
        $this->assertTrue($fresh->expires_at->isFuture());
        $this->assertNotSame($stale, $fresh->otp);

        Mail::assertSent(EmailOTP::class, fn ($mail) => $mail->hasTo($user->email));
    }

    /**
     * The shared helper leaves a live code alone so the several challenge
     * entry points do not each mail one. A resend is the deliberate exception:
     * the caller is saying the first mail never arrived, so answering "sent"
     * without mailing would strand exactly the client this endpoint is for.
     */
    public function test_resend_replaces_a_code_that_is_still_live(): void
    {
        $this->enableMFA();
        Mail::fake();

        $user = User::factory()->create();
        $auth = $user->multiFactorAuths()->create([
            'method' => 'email',
            'preferred' => true,
            'otp' => '654321',
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
        ]);
        $live = $auth->otp;

        $attempt = $user->loginAttempts()->create([
            'method' => LoginAttempt::Password,
            'multi_factor_method' => 'email',
            'is_success' => false,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->createMfaJwtToken($user->id, $attempt->id))
            ->postJson('/neev/mfa/otp/send')
            ->assertOk();

        $this->assertNotSame($live, $auth->fresh()->otp);
        Mail::assertSent(EmailOTP::class, fn ($mail) => $mail->hasTo($user->email));
    }

    /** Nothing is mailed for an account that has not enrolled email OTP. */
    public function test_resend_is_refused_without_an_email_method(): void
    {
        $this->enableMFA();
        Mail::fake();

        $data = $this->createUserWithMFAToken('authenticator');

        $this->withHeader('Authorization', 'Bearer ' . $data['plainTextToken'])
            ->postJson('/neev/mfa/otp/send')
            ->assertStatus(400);

        Mail::assertNothingSent();
    }

    /** The login token from a completed login is not a key to this endpoint. */
    public function test_resend_rejects_a_request_without_the_mfa_jwt(): void
    {
        $this->enableMFA();

        $this->postJson('/neev/mfa/otp/send')->assertStatus(401);
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
            'preferred' => true,
            'secret' => Base32::encodeUpper(random_bytes(32)),
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
            'preferred' => true,
            'secret' => Base32::encodeUpper(random_bytes(32)),
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

    /**
     * The step-up JWT is good for one step up. Its `jti` was recorded and
     * never read, so the same token traded for a second login token for as
     * long as it lived — one first factor, two sessions.
     */
    public function test_the_mfa_jwt_cannot_be_traded_twice(): void
    {
        $this->enableMFA();

        $data = $this->createUserWithMFAToken();
        $user = $data['user'];
        $totp = TOTP::create(secret: $data['secret']);

        $first = $this->withHeader('Authorization', 'Bearer ' . $data['plainTextToken'])
            ->postJson('/neev/mfa/otp/verify', [
                'auth_method' => 'authenticator',
                'otp' => $totp->now(),
            ]);

        $first->assertOk()->assertJsonPath('auth_state', 'authenticated');
        $this->assertSame(1, $user->loginTokens()->count());

        // Same JWT, a fresh and perfectly valid second factor.
        $this->travel(31)->seconds();

        $this->withHeader('Authorization', 'Bearer ' . $data['plainTextToken'])
            ->postJson('/neev/mfa/otp/verify', [
                'auth_method' => 'authenticator',
                'otp' => TOTP::create(secret: $data['secret'])->now(),
            ])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Invalid or expired token');

        $this->assertSame(1, $user->loginTokens()->count(), 'One first factor, one login token.');
    }

    /** A wrong code does not spend it — the user has to be able to retry. */
    public function test_a_failed_code_leaves_the_mfa_jwt_usable(): void
    {
        $this->enableMFA();

        $data = $this->createUserWithMFAToken();

        $this->withHeader('Authorization', 'Bearer ' . $data['plainTextToken'])
            ->postJson('/neev/mfa/otp/verify', ['auth_method' => 'authenticator', 'otp' => '000000'])
            ->assertStatus(400);

        $this->withHeader('Authorization', 'Bearer ' . $data['plainTextToken'])
            ->postJson('/neev/mfa/otp/verify', [
                'auth_method' => 'authenticator',
                'otp' => TOTP::create(secret: $data['secret'])->now(),
            ])
            ->assertOk();
    }
}
