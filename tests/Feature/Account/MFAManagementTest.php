<?php

namespace Ssntpl\Neev\Tests\Feature\Account;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Mockery;
use Ssntpl\Neev\Database\Factories\MultiFactorAuthFactory;
use Ssntpl\Neev\Database\Factories\TeamAuthSettingsFactory;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Services\TenantSSOManager;
use Ssntpl\Neev\Mail\EmailOTP;
use Ssntpl\Neev\Models\MultiFactorAuth;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class MFAManagementTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    /** Removing a factor is confirmed, so the tests need a password to send. */
    private const PASSWORD = 'Password123!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableMFA();
    }

    protected function authenticatedUser(): array
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $token = $user->createLoginToken(60);

        return [$user, $token->plainTextToken];
    }

    // -----------------------------------------------------------------
    // POST /neev/mfa/add — add MFA method
    // -----------------------------------------------------------------

    public function test_add_authenticator_mfa(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/mfa/add', [
                'auth_method' => 'authenticator',
            ]);

        $response->assertOk();

        // Should return QR code and secret for authenticator method
        $this->assertNotNull($response->json('qr_code'));
        $this->assertNotNull($response->json('secret'));
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

    /**
     * Adding a factor that is already configured is a client error, and the
     * controller now reports every `status => Error` result as 422 rather
     * than a 200 carrying an error message.
     */
    public function test_add_duplicate_email_mfa_returns_already_configured(): void
    {
        [$user, $token] = $this->authenticatedUser();

        // Add email MFA first
        MultiFactorAuthFactory::new()->email()->create(['user_id' => $user->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/mfa/add', [
                'auth_method' => 'email',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Email already Configured.');
    }

    /**
     * An email OTP factor is only as trustworthy as the address it is sent to,
     * so an unverified address cannot become a second factor.
     */
    public function test_add_email_mfa_is_rejected_when_email_is_unverified(): void
    {
        $user = User::factory()->unverified()->create();
        $token = $user->createLoginToken(60)->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/mfa/add', [
                'auth_method' => 'email',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Email is not verified.');

        $this->assertSame(0, $user->multiFactorAuths()->where('method', 'email')->count());
    }

    public function test_add_email_mfa_succeeds_once_the_email_is_verified(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $this->assertNotNull($user->email_verified_at);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/mfa/add', [
                'auth_method' => 'email',
            ]);

        $response->assertOk();

        $this->assertSame(
            MultiFactorAuth::STATUS_ACTIVE,
            $user->multiFactorAuths()->where('method', 'email')->first()->status
        );
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
                'password' => self::PASSWORD,
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
                'password' => self::PASSWORD,
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
                'password' => self::PASSWORD,
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
                'password' => self::PASSWORD,
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

    // -----------------------------------------------------------------
    // Removing a factor is confirmed
    // -----------------------------------------------------------------

    /**
     * Whoever holds a stolen token must not be able to strip the factor that
     * would have stopped them using it. Removal used to ask for nothing but
     * the method name.
     */
    public function test_delete_mfa_without_confirmation_is_refused(): void
    {
        [$user, $token] = $this->authenticatedUser();

        MultiFactorAuthFactory::new()->create([
            'user_id' => $user->id,
            'method' => 'authenticator',
            'preferred' => true,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/mfa/delete', ['auth_method' => 'authenticator'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/mfa/delete', [
                'password' => 'not-the-password',
                'auth_method' => 'authenticator',
            ])
            ->assertStatus(403);

        $this->assertNotNull($user->fresh()->multiFactorAuth('authenticator'));
    }

    /**
     * An account with no password — every OAuth and SSO registration — proves
     * itself with a mailed code instead, as it does for account deletion.
     */
    public function test_a_passwordless_account_removes_a_factor_with_a_code(): void
    {
        Mail::fake();

        $user = User::factory()->create(['password' => null]);
        $token = $user->createLoginToken(60)->plainTextToken;

        MultiFactorAuthFactory::new()->create([
            'user_id' => $user->id,
            'method' => 'authenticator',
            'preferred' => true,
        ]);

        // A password is not what this account has, so asking for one is 422.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/mfa/delete', ['auth_method' => 'authenticator'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('otp');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/confirmation/otp')
            ->assertOk();

        $otp = null;
        Mail::assertSent(EmailOTP::class, function (EmailOTP $mail) use (&$otp) {
            $otp = $mail->otp;

            return true;
        });
        $this->assertNotNull($otp);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/mfa/delete', [
                'otp' => $otp,
                'auth_method' => 'authenticator',
            ])
            ->assertOk();

        $this->assertNull($user->fresh()->multiFactorAuth('authenticator'));
    }

    /**
     * An auto-provisioned SSO account used to be written with a random
     * password nobody could produce, so it could answer neither branch of the
     * confirmation: the password one asked for something it did not have, and
     * the code one was unreachable because the column was populated. Every
     * gated action was closed to exactly the accounts with no way in.
     */
    public function test_an_sso_provisioned_account_can_confirm_with_a_code(): void
    {
        Mail::fake();

        $team = TeamFactory::new()->create();
        TeamAuthSettingsFactory::new()->create([
            'team_id' => $team->id,
            'auto_provision' => true,
        ]);

        $socialite = Mockery::mock(SocialiteUser::class);
        $socialite->shouldReceive('getEmail')->andReturn('asha@partner.test');
        $socialite->shouldReceive('getName')->andReturn('Asha');

        $user = (new TenantSSOManager())->findOrCreateUser($team, $socialite);

        $this->assertNull($user->password, 'Provisioning must not invent a password.');

        MultiFactorAuthFactory::new()->create([
            'user_id' => $user->id,
            'method' => 'authenticator',
            'preferred' => true,
        ]);

        $token = $user->createLoginToken(60)->plainTextToken;

        // The code branch is the one that applies, and it works.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/mfa/delete', ['auth_method' => 'authenticator'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('otp');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/confirmation/otp')
            ->assertOk();

        $otp = null;
        Mail::assertSent(EmailOTP::class, function (EmailOTP $mail) use (&$otp) {
            $otp = $mail->otp;

            return true;
        });

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/mfa/delete', ['otp' => $otp, 'auth_method' => 'authenticator'])
            ->assertOk();

        $this->assertNull($user->fresh()->multiFactorAuth('authenticator'));
    }
}
