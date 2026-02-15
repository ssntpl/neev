<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use OTPHP\TOTP;
use ParagonIE\ConstantTime\Base32;
use Ssntpl\Neev\Models\AccessToken;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class MFATest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    /**
     * Create a user with an MFA-token (simulating post-login pre-MFA state).
     *
     * We manually create the access token as an mfa_token type, which is
     * the state the system puts you in after password login when MFA is enabled.
     * The NeevAPIMiddleware allows mfa_token only on the /neev/mfa/otp/verify path.
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

        // Create an mfa_token (the middleware allows this only for /neev/mfa/otp/verify)
        $plainText = Str::random(40);
        $accessToken = $user->accessTokens()->create([
            'name' => 'mfa_token',
            'token' => $plainText,
            'token_type' => AccessToken::mfa_token,
            'expires_at' => now()->addMinutes(60),
        ]);

        $fullToken = $accessToken->id . '|' . $plainText;

        return [
            'user' => $user,
            'plainTextToken' => $fullToken,
            'accessToken' => $accessToken,
            'secret' => $secret,
        ];
    }

    // -----------------------------------------------------------------
    // POST /neev/mfa/otp/verify (authenticator)
    // -----------------------------------------------------------------

    public function test_successful_authenticator_mfa_verification_promotes_token(): void
    {
        $this->enableMFA();

        $data = $this->createUserWithMFAToken('authenticator');

        // Generate a valid TOTP from the same secret
        $totp = TOTP::create($data['secret']);
        $validOTP = $totp->now();

        $response = $this->withHeader('Authorization', 'Bearer ' . $data['plainTextToken'])
            ->postJson('/neev/mfa/otp/verify', [
                'auth_method' => 'authenticator',
                'otp' => $validOTP,
            ]);

        $response->assertOk();
        $response->assertJson(['status' => 'Success']);
        $response->assertJsonStructure(['status', 'token', 'email_verified']);

        // Token should be promoted from mfa_token to login
        $this->assertDatabaseHas('access_tokens', [
            'id' => $data['accessToken']->id,
            'token_type' => AccessToken::login,
        ]);
    }

    public function test_failed_authenticator_mfa_returns_401(): void
    {
        $this->enableMFA();

        $data = $this->createUserWithMFAToken('authenticator');

        $response = $this->withHeader('Authorization', 'Bearer ' . $data['plainTextToken'])
            ->postJson('/neev/mfa/otp/verify', [
                'auth_method' => 'authenticator',
                'otp' => '000000', // Wrong OTP
            ]);

        $response->assertStatus(401);
        $response->assertJson([
            'status' => 'Failed',
            'message' => 'Code verification was failed.',
        ]);

        // Token should remain as mfa_token
        $this->assertDatabaseHas('access_tokens', [
            'id' => $data['accessToken']->id,
            'token_type' => AccessToken::mfa_token,
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

        // Create mfa_token
        $plainText = Str::random(40);
        $accessToken = $user->accessTokens()->create([
            'name' => 'mfa_token',
            'token' => $plainText,
            'token_type' => AccessToken::mfa_token,
            'expires_at' => now()->addMinutes(60),
        ]);

        $fullToken = $accessToken->id . '|' . $plainText;

        $response = $this->withHeader('Authorization', 'Bearer ' . $fullToken)
            ->postJson('/neev/mfa/otp/verify', [
                'auth_method' => 'email',
                'otp' => $otpPlaintext,
            ]);

        $response->assertOk();
        $response->assertJson(['status' => 'Success']);

        // Token should be promoted
        $this->assertDatabaseHas('access_tokens', [
            'id' => $accessToken->id,
            'token_type' => AccessToken::login,
        ]);
    }

    public function test_expired_email_mfa_otp_returns_401(): void
    {
        $this->enableMFA();

        $user = User::factory()->create();

        $otpPlaintext = '654321';
        $user->multiFactorAuths()->create([
            'method' => 'email',
            'preferred' => true,
            'otp' => $otpPlaintext,
            'expires_at' => now()->subMinutes(5), // Expired
        ]);

        $plainText = Str::random(40);
        $accessToken = $user->accessTokens()->create([
            'name' => 'mfa_token',
            'token' => $plainText,
            'token_type' => AccessToken::mfa_token,
            'expires_at' => now()->addMinutes(60),
        ]);

        $fullToken = $accessToken->id . '|' . $plainText;

        $response = $this->withHeader('Authorization', 'Bearer ' . $fullToken)
            ->postJson('/neev/mfa/otp/verify', [
                'auth_method' => 'email',
                'otp' => $otpPlaintext,
            ]);

        $response->assertStatus(401);
    }

    // -----------------------------------------------------------------
    // POST /neev/mfa/otp/verify (recovery code)
    // -----------------------------------------------------------------

    public function test_successful_recovery_code_verification(): void
    {
        $this->enableMFA();

        $user = User::factory()->create();

        // Add an authenticator MFA (required to have some MFA set up)
        $user->multiFactorAuths()->create([
            'method' => 'authenticator',
            'preferred' => true,
            'secret' => Base32::encodeUpper(random_bytes(32)),
        ]);

        // Generate recovery codes
        config(['neev.recovery_codes' => 8]);
        $codes = $user->generateRecoveryCodes();
        $validCode = $codes[0];

        // Create mfa_token
        $plainText = Str::random(40);
        $accessToken = $user->accessTokens()->create([
            'name' => 'mfa_token',
            'token' => $plainText,
            'token_type' => AccessToken::mfa_token,
            'expires_at' => now()->addMinutes(60),
        ]);

        $fullToken = $accessToken->id . '|' . $plainText;

        $response = $this->withHeader('Authorization', 'Bearer ' . $fullToken)
            ->postJson('/neev/mfa/otp/verify', [
                'auth_method' => 'recovery',
                'otp' => $validCode,
            ]);

        $response->assertOk();
        $response->assertJson(['status' => 'Success']);

        // Token should be promoted
        $this->assertDatabaseHas('access_tokens', [
            'id' => $accessToken->id,
            'token_type' => AccessToken::login,
        ]);
    }

    public function test_invalid_recovery_code_returns_401(): void
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

        // Create mfa_token
        $plainText = Str::random(40);
        $accessToken = $user->accessTokens()->create([
            'name' => 'mfa_token',
            'token' => $plainText,
            'token_type' => AccessToken::mfa_token,
            'expires_at' => now()->addMinutes(60),
        ]);

        $fullToken = $accessToken->id . '|' . $plainText;

        $response = $this->withHeader('Authorization', 'Bearer ' . $fullToken)
            ->postJson('/neev/mfa/otp/verify', [
                'auth_method' => 'recovery',
                'otp' => 'invalidcode123',
            ]);

        $response->assertStatus(401);
        $response->assertJson([
            'status' => 'Failed',
            'message' => 'Code verification was failed.',
        ]);
    }

    // -----------------------------------------------------------------
    // Edge cases
    // -----------------------------------------------------------------

    public function test_mfa_verify_returns_403_for_nonexistent_user(): void
    {
        // Create a token for a user, then delete the user
        $user = User::factory()->create();

        $plainText = Str::random(40);
        $accessToken = $user->accessTokens()->create([
            'name' => 'mfa_token',
            'token' => $plainText,
            'token_type' => AccessToken::mfa_token,
            'expires_at' => now()->addMinutes(60),
        ]);

        $fullToken = $accessToken->id . '|' . $plainText;

        // Delete user data to simulate orphaned token
        $user->emails()->delete();
        $user->passwords()->delete();
        $user->forceDelete();

        $response = $this->withHeader('Authorization', 'Bearer ' . $fullToken)
            ->postJson('/neev/mfa/otp/verify', [
                'auth_method' => 'authenticator',
                'otp' => '123456',
            ]);

        // Middleware returns 403 for missing user
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

        $plainText = Str::random(40);
        $accessToken = $user->accessTokens()->create([
            'name' => 'mfa_token',
            'token' => $plainText,
            'token_type' => AccessToken::mfa_token,
            'expires_at' => now()->addMinutes(60),
        ]);

        $fullToken = $accessToken->id . '|' . $plainText;

        // Try accessing a non-MFA endpoint
        $response = $this->withHeader('Authorization', 'Bearer ' . $fullToken)
            ->postJson('/neev/logout');

        $response->assertStatus(401);
    }
}
