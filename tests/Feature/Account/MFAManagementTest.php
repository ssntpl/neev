<?php

namespace Ssntpl\Neev\Tests\Feature\Account;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use OTPHP\TOTP;
use Ssntpl\Neev\Database\Factories\MultiFactorAuthFactory;
use Ssntpl\Neev\Models\MultiFactorAuth;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class MFAManagementTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableMFA();
    }

    protected function authenticatedUser(): array
    {
        $user = User::factory()->create();
        $token = $user->createLoginToken(60);

        return [$user, $token->plainTextToken];
    }

    // -----------------------------------------------------------------
    // POST /neev/mfa/add — add MFA method
    // -----------------------------------------------------------------

    public function test_add_authenticator_mfa_creates_pending_row_and_returns_qr(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/mfa/add', [
                'auth_method' => 'authenticator',
            ]);

        $response->assertOk();
        $this->assertNotNull($response->json('qr_code'));
        $this->assertNotNull($response->json('secret'));

        // Row exists in pending state, excluded from active relation.
        $this->assertDatabaseHas('multi_factor_auths', [
            'user_id' => $user->id,
            'method' => 'authenticator',
            'status' => MultiFactorAuth::STATUS_PENDING,
        ]);
        $this->assertEquals(0, $user->activeMultiFactorAuths()->where('method', 'authenticator')->count());
    }

    // -----------------------------------------------------------------
    // POST /neev/mfa/setup/otp/verify — finalize authenticator setup
    // -----------------------------------------------------------------

    public function test_setup_verify_marks_pending_authenticator_active(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $setup = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/mfa/add', ['auth_method' => 'authenticator'])
            ->assertOk();

        $secret = $setup->json('secret');
        $validOtp = TOTP::create($secret)->now();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/mfa/setup/otp/verify', [
                'auth_method' => 'authenticator',
                'otp' => $validOtp,
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonPath('method', 'authenticator');

        $this->assertDatabaseHas('multi_factor_auths', [
            'user_id' => $user->id,
            'method' => 'authenticator',
            'status' => MultiFactorAuth::STATUS_ACTIVE,
        ]);
    }

    public function test_setup_verify_rejects_wrong_otp_and_keeps_row_pending(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/mfa/add', ['auth_method' => 'authenticator'])
            ->assertOk();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/mfa/setup/otp/verify', [
                'auth_method' => 'authenticator',
                'otp' => '000000',
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Code verification failed.');

        $this->assertDatabaseHas('multi_factor_auths', [
            'user_id' => $user->id,
            'method' => 'authenticator',
            'status' => MultiFactorAuth::STATUS_PENDING,
        ]);
    }

    public function test_setup_verify_returns_400_when_no_pending_row(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/mfa/setup/otp/verify', [
                'auth_method' => 'authenticator',
                'otp' => '123456',
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'No pending setup found. Please start setup again.');
    }

    public function test_setup_verify_requires_otp_and_auth_method(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/mfa/setup/otp/verify', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['otp', 'auth_method']);
    }

    public function test_add_email_mfa(): void
    {
        Mail::fake();

        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/mfa/add', [
                'auth_method' => 'email',
            ]);

        $response->assertOk();
    }

    public function test_add_mfa_requires_auth_method(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/mfa/add', []);

        $response->assertStatus(422);
    }

    public function test_add_duplicate_email_mfa_returns_already_configured(): void
    {
        [$user, $token] = $this->authenticatedUser();

        // Add email MFA first
        MultiFactorAuthFactory::new()->email()->create(['user_id' => $user->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/mfa/add', [
                'auth_method' => 'email',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Email already Configured.');
    }

    public function test_add_unsupported_mfa_method_returns_error(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/mfa/add', [
                'auth_method' => 'sms',
            ]);

        $response->assertStatus(400);
    }

    // -----------------------------------------------------------------
    // DELETE /neev/mfa/delete — delete MFA method
    // -----------------------------------------------------------------

    public function test_delete_mfa_method(): void
    {
        [$user, $token] = $this->authenticatedUser();

        MultiFactorAuthFactory::new()->create([
            'user_id' => $user->id,
            'method' => 'authenticator',
            'preferred' => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/mfa/delete', [
                'auth_method' => 'authenticator',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Auth has been deleted.');

        $this->assertNull($user->multiFactorAuth('authenticator'));
    }

    public function test_delete_preferred_mfa_reassigns_preferred(): void
    {
        [$user, $token] = $this->authenticatedUser();

        // Add two MFA methods, authenticator is preferred
        MultiFactorAuthFactory::new()->create([
            'user_id' => $user->id,
            'method' => 'authenticator',
            'preferred' => true,
        ]);
        MultiFactorAuthFactory::new()->create([
            'user_id' => $user->id,
            'method' => 'email',
            'preferred' => false,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/mfa/delete', [
                'auth_method' => 'authenticator',
            ]);

        $response->assertOk();

        // Email should now be preferred
        $user->refresh();
        $emailAuth = $user->multiFactorAuth('email');
        $this->assertTrue($emailAuth->preferred);
    }

    public function test_delete_last_mfa_cleans_up_recovery_codes(): void
    {
        [$user, $token] = $this->authenticatedUser();

        MultiFactorAuthFactory::new()->create([
            'user_id' => $user->id,
            'method' => 'authenticator',
            'preferred' => true,
        ]);

        // Generate recovery codes
        $user->generateRecoveryCodes();
        $this->assertGreaterThan(0, $user->recoveryCodes()->count());

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/mfa/delete', [
                'auth_method' => 'authenticator',
            ]);

        $response->assertOk();

        // Recovery codes should be cleaned up
        $this->assertEquals(0, $user->recoveryCodes()->count());
    }

    public function test_delete_nonexistent_mfa_returns_error(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/mfa/delete', [
                'auth_method' => 'authenticator',
            ]);

        $response->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // POST /neev/recoveryCodes — generate recovery codes
    // -----------------------------------------------------------------

    public function test_generate_recovery_codes(): void
    {
        [$user, $token] = $this->authenticatedUser();

        MultiFactorAuthFactory::new()->create([
            'user_id' => $user->id,
            'method' => 'authenticator',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/recoveryCodes');

        $response->assertOk()
            ->assertJsonStructure(['data']);

        $this->assertNotEmpty($response->json('data'));
    }

    public function test_generate_recovery_codes_requires_mfa_enabled(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/recoveryCodes');

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Enable MFA first.');
    }
}
