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

    public function test_add_authenticator_ignores_status_in_request_body(): void
    {
        [$user, $token] = $this->authenticatedUser();

        // A malicious client tries to skip verification by forcing status=active.
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/mfa/add', [
                'auth_method' => 'authenticator',
                'status' => MultiFactorAuth::STATUS_ACTIVE,
            ]);

        $response->assertOk();

        // The row is still pending — the body-provided status was ignored.
        $auth = $user->multiFactorAuths()->where('method', 'authenticator')->first();
        $this->assertEquals(MultiFactorAuth::STATUS_PENDING, $auth->status);
    }

    public function test_add_custom_email_creates_pending_and_sends_otp(): void
    {
        Mail::fake();

        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/mfa/add', [
                'auth_method' => 'email',
                'name' => 'Backup',
                'email' => 'backup@example.com',
            ]);

        $response->assertOk()
            ->assertJsonPath('email', 'backup@example.com');

        // Stored, pending, and a code was emailed to the new address.
        $auth = $user->multiFactorAuths()->where('method', 'email')->first();
        $this->assertEquals('backup@example.com', $auth->email);
        $this->assertEquals(MultiFactorAuth::STATUS_PENDING, $auth->status);

        Mail::assertSent(\Ssntpl\Neev\Mail\EmailOTP::class, fn ($mail) => $mail->hasTo('backup@example.com'));
    }

    public function test_add_duplicate_email_is_rejected(): void
    {
        Mail::fake();

        [$user, $token] = $this->authenticatedUser();

        // An email instance already registered for backup@example.com.
        $user->multiFactorAuths()->create([
            'method' => 'email',
            'email' => 'backup@example.com',
            'status' => MultiFactorAuth::STATUS_PENDING,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/mfa/add', [
                'auth_method' => 'email',
                'name' => 'Backup again',
                'email' => 'backup@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'Error')
            ->assertJsonPath('message', 'This email is already configured.');

        // The duplicate address was not added.
        $this->assertEquals(1, $user->multiFactorAuths()->where('method', 'email')->count());
    }

    public function test_add_multiple_distinct_emails_is_allowed(): void
    {
        Mail::fake();

        [$user, $token] = $this->authenticatedUser();

        // An existing distinct email instance.
        $user->multiFactorAuths()->create([
            'method' => 'email',
            'email' => 'work@example.com',
            'status' => MultiFactorAuth::STATUS_ACTIVE,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/mfa/add', [
                'auth_method' => 'email',
                'name' => 'Backup',
                'email' => 'backup@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonPath('email', 'backup@example.com');

        $this->assertEquals(2, $user->multiFactorAuths()->where('method', 'email')->count());
    }

    public function test_setup_verify_activates_pending_custom_email(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $auth = $user->multiFactorAuths()->create([
            'method' => 'email',
            'name' => 'Backup',
            'email' => 'backup@example.com',
            'status' => MultiFactorAuth::STATUS_PENDING,
            'otp' => '135790',
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/mfa/setup/otp/verify', [
                'auth_method' => 'email',
                'id' => $auth->id,
                'otp' => '135790',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'Success');

        $auth->refresh();
        $this->assertEquals(MultiFactorAuth::STATUS_ACTIVE, $auth->status);
    }

    public function test_add_named_authenticators_creates_multiple_instances(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/mfa/add', ['auth_method' => 'authenticator', 'name' => 'Work phone'])
            ->assertOk()
            ->assertJsonPath('name', 'Work phone');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/mfa/add', ['auth_method' => 'authenticator', 'name' => 'Personal phone'])
            ->assertOk()
            ->assertJsonPath('name', 'Personal phone');

        $this->assertEquals(2, $user->multiFactorAuths()->where('method', 'authenticator')->count());
    }

    public function test_delete_specific_instance_by_id(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $keep = MultiFactorAuthFactory::new()->create([
            'user_id' => $user->id,
            'method' => 'authenticator',
            'name' => 'Keep',
        ]);
        $remove = MultiFactorAuthFactory::new()->create([
            'user_id' => $user->id,
            'method' => 'authenticator',
            'name' => 'Remove',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/mfa/delete', ['id' => $remove->id])
            ->assertOk();

        $this->assertNull(MultiFactorAuth::find($remove->id));
        $this->assertNotNull(MultiFactorAuth::find($keep->id));
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

        $auth = MultiFactorAuthFactory::new()->create([
            'user_id' => $user->id,
            'method' => 'authenticator',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/mfa/delete', [
                'id' => $auth->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Auth has been deleted.');

        $this->assertNull($user->multiFactorAuth('authenticator'));
    }

    public function test_delete_preferred_mfa_falls_back_to_remaining_method(): void
    {
        [$user, $token] = $this->authenticatedUser();

        // Two active methods; authenticator was used most recently, so it is
        // the preferred one.
        $authenticator = MultiFactorAuthFactory::new()->create([
            'user_id' => $user->id,
            'method' => 'authenticator',
            'last_used' => now(),
        ]);
        MultiFactorAuthFactory::new()->create([
            'user_id' => $user->id,
            'method' => 'email',
            'last_used' => now()->subDay(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/mfa/delete', [
                'id' => $authenticator->id,
            ]);

        $response->assertOk();

        // With the preferred method gone, email becomes the preferred one.
        $user->refresh();
        $this->assertEquals('email', $user->preferredMultiFactorAuth?->method);
    }

    public function test_delete_last_mfa_cleans_up_recovery_codes(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $auth = MultiFactorAuthFactory::new()->create([
            'user_id' => $user->id,
            'method' => 'authenticator',
        ]);

        // Generate recovery codes
        $user->generateRecoveryCodes();
        $this->assertGreaterThan(0, $user->recoveryCodes()->count());

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/mfa/delete', [
                'id' => $auth->id,
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
                'id' => 999999,
            ]);

        $response->assertStatus(403);
    }

    public function test_delete_requires_id(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/mfa/delete', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['id']);
    }

    public function test_cannot_delete_another_users_mfa(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $other = User::factory()->create();
        $otherAuth = MultiFactorAuthFactory::new()->create([
            'user_id' => $other->id,
            'method' => 'authenticator',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/mfa/delete', ['id' => $otherAuth->id])
            ->assertStatus(403);

        $this->assertNotNull(MultiFactorAuth::find($otherAuth->id));
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
