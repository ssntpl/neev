<?php

namespace Ssntpl\Neev\Tests\Unit\Traits;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use OTPHP\TOTP;
use Ssntpl\Neev\Models\MultiFactorAuth;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;

class HasMultiAuthTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // multiFactorAuths()
    // -----------------------------------------------------------------

    public function test_multi_factor_auths_returns_all_mfa_records(): void
    {
        $user = User::factory()->create();

        $user->multiFactorAuths()->create([
            'method' => 'authenticator',
            'preferred' => true,
        ]);
        $user->multiFactorAuths()->create([
            'method' => 'email',
            'preferred' => false,
        ]);

        $this->assertCount(2, $user->multiFactorAuths);
    }

    public function test_multi_factor_auths_returns_empty_when_none_configured(): void
    {
        $user = User::factory()->create();

        $this->assertCount(0, $user->multiFactorAuths);
    }

    // -----------------------------------------------------------------
    // multiFactorAuth($method)
    // -----------------------------------------------------------------

    public function test_multi_factor_auth_finds_by_method(): void
    {
        $user = User::factory()->create();

        $user->multiFactorAuths()->create([
            'method' => 'authenticator',
            'preferred' => true,
        ]);
        $user->multiFactorAuths()->create([
            'method' => 'email',
            'preferred' => false,
        ]);

        // Load the relation so the method can filter from the collection
        $user->load('multiFactorAuths');

        $auth = $user->multiFactorAuth('authenticator');

        $this->assertNotNull($auth);
        $this->assertEquals('authenticator', $auth->method);
    }

    public function test_multi_factor_auth_returns_null_for_unknown_method(): void
    {
        $user = User::factory()->create();

        $user->multiFactorAuths()->create([
            'method' => 'authenticator',
            'preferred' => true,
        ]);

        $user->load('multiFactorAuths');

        $this->assertNull($user->multiFactorAuth('sms'));
    }

    // -----------------------------------------------------------------
    // preferredMultiFactorAuth()
    // -----------------------------------------------------------------

    public function test_preferred_multi_factor_auth_returns_preferred_one(): void
    {
        $user = User::factory()->create();

        $user->multiFactorAuths()->create([
            'method' => 'authenticator',
            'preferred' => false,
        ]);
        $user->multiFactorAuths()->create([
            'method' => 'email',
            'preferred' => true,
        ]);

        $preferred = $user->preferredMultiFactorAuth;

        $this->assertNotNull($preferred);
        $this->assertEquals('email', $preferred->method);
        $this->assertTrue($preferred->preferred);
    }

    public function test_preferred_multi_factor_auth_returns_null_when_none_preferred(): void
    {
        $user = User::factory()->create();

        $user->multiFactorAuths()->create([
            'method' => 'authenticator',
            'preferred' => false,
        ]);

        $this->assertNull($user->preferredMultiFactorAuth);
    }

    // -----------------------------------------------------------------
    // addMultiFactorAuth('authenticator')
    // -----------------------------------------------------------------

    public function test_add_multi_factor_auth_authenticator_creates_record_and_returns_qr_code(): void
    {
        $user = User::factory()->create();
        $user->load('multiFactorAuths', 'preferredMultiFactorAuth');

        $result = $user->addMultiFactorAuth('authenticator');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('qr_code', $result);
        $this->assertArrayHasKey('secret', $result);
        $this->assertArrayHasKey('method', $result);
        $this->assertEquals('authenticator', $result['method']);
        $this->assertNotEmpty($result['qr_code']);
        $this->assertNotEmpty($result['secret']);

        // Verify record was created in the database
        $this->assertDatabaseHas('multi_factor_auths', [
            'user_id' => $user->id,
            'method' => 'authenticator',
        ]);
    }

    public function test_add_multi_factor_auth_authenticator_reuses_existing_secret(): void
    {
        $user = User::factory()->create();

        // Create the first authenticator MFA
        $user->load('multiFactorAuths', 'preferredMultiFactorAuth');
        $firstResult = $user->addMultiFactorAuth('authenticator');
        $firstSecret = $firstResult['secret'];

        // Reload relations and call again
        $user->load('multiFactorAuths', 'preferredMultiFactorAuth');
        $secondResult = $user->addMultiFactorAuth('authenticator');

        // Should reuse the same secret
        $this->assertEquals($firstSecret, $secondResult['secret']);

        // Should not create a duplicate record
        $this->assertEquals(1, $user->multiFactorAuths()->where('method', 'authenticator')->count());
    }

    // -----------------------------------------------------------------
    // addMultiFactorAuth('email')
    // -----------------------------------------------------------------

    public function test_add_multi_factor_auth_email_creates_record(): void
    {
        $user = User::factory()->create();
        $user->load('multiFactorAuths', 'preferredMultiFactorAuth');

        $result = $user->addMultiFactorAuth('email');

        $this->assertIsArray($result);
        $this->assertEquals('Email Configured.', $result['message']);

        $this->assertDatabaseHas('multi_factor_auths', [
            'user_id' => $user->id,
            'method' => 'email',
        ]);
    }

    public function test_add_multi_factor_auth_email_returns_already_configured_if_exists(): void
    {
        $user = User::factory()->create();

        $user->multiFactorAuths()->create([
            'method' => 'email',
            'preferred' => true,
        ]);

        $user->load('multiFactorAuths', 'preferredMultiFactorAuth');
        $result = $user->addMultiFactorAuth('email');

        $this->assertIsArray($result);
        $this->assertEquals('Email already Configured.', $result['message']);

        // Should not create a duplicate record
        $this->assertEquals(1, $user->multiFactorAuths()->where('method', 'email')->count());
    }

    // -----------------------------------------------------------------
    // addMultiFactorAuth() with unknown method
    // -----------------------------------------------------------------

    public function test_add_multi_factor_auth_with_unknown_method_returns_null(): void
    {
        $user = User::factory()->create();
        $user->load('multiFactorAuths', 'preferredMultiFactorAuth');

        $result = $user->addMultiFactorAuth('sms');

        $this->assertNull($result);
    }

    // -----------------------------------------------------------------
    // verifyMFAOTP() - authenticator
    // -----------------------------------------------------------------

    public function test_verify_mfa_otp_authenticator_returns_true_for_valid_otp(): void
    {
        $user = User::factory()->create();
        $user->load('multiFactorAuths', 'preferredMultiFactorAuth');

        $result = $user->addMultiFactorAuth('authenticator');
        $secret = $result['secret'];

        // Generate a valid OTP from the secret
        $totp = TOTP::create($secret);
        $validOtp = $totp->now();

        // Reload the relation so multiFactorAuth() can find it
        $user->load('multiFactorAuths');

        $verified = $user->verifyMFAOTP('authenticator', $validOtp);

        $this->assertTrue($verified);
    }

    public function test_verify_mfa_otp_authenticator_returns_false_for_wrong_otp(): void
    {
        $user = User::factory()->create();
        $user->load('multiFactorAuths', 'preferredMultiFactorAuth');

        $user->addMultiFactorAuth('authenticator');
        $user->load('multiFactorAuths');

        $verified = $user->verifyMFAOTP('authenticator', '000000');

        $this->assertFalse($verified);
    }

    public function test_verify_mfa_otp_authenticator_updates_last_used_on_success(): void
    {
        $user = User::factory()->create();
        $user->load('multiFactorAuths', 'preferredMultiFactorAuth');

        $result = $user->addMultiFactorAuth('authenticator');
        $secret = $result['secret'];

        $totp = TOTP::create($secret);
        $validOtp = $totp->now();

        $user->load('multiFactorAuths');
        $user->verifyMFAOTP('authenticator', $validOtp);

        $auth = $user->multiFactorAuths()->where('method', 'authenticator')->first();
        $this->assertNotNull($auth->last_used);
    }

    // -----------------------------------------------------------------
    // verifyMFAOTP() - email
    // -----------------------------------------------------------------

    public function test_verify_mfa_otp_email_returns_true_for_valid_otp(): void
    {
        $user = User::factory()->create();

        $plainOtp = '123456';

        $user->multiFactorAuths()->create([
            'method' => 'email',
            'preferred' => true,
            'otp' => $plainOtp,
            'expires_at' => now()->addMinutes(10),
        ]);

        $user->load('multiFactorAuths');

        $verified = $user->verifyMFAOTP('email', $plainOtp);

        $this->assertTrue($verified);
    }

    public function test_verify_mfa_otp_email_returns_false_for_wrong_otp(): void
    {
        $user = User::factory()->create();

        $user->multiFactorAuths()->create([
            'method' => 'email',
            'preferred' => true,
            'otp' => '123456',
            'expires_at' => now()->addMinutes(10),
        ]);

        $user->load('multiFactorAuths');

        $verified = $user->verifyMFAOTP('email', '999999');

        $this->assertFalse($verified);
    }

    public function test_verify_mfa_otp_email_returns_false_when_expired(): void
    {
        $user = User::factory()->create();

        $plainOtp = '123456';

        $user->multiFactorAuths()->create([
            'method' => 'email',
            'preferred' => true,
            'otp' => $plainOtp,
            'expires_at' => now()->subMinutes(1),
        ]);

        $user->load('multiFactorAuths');

        $verified = $user->verifyMFAOTP('email', $plainOtp);

        $this->assertFalse($verified);
    }

    public function test_verify_mfa_otp_email_clears_otp_on_success(): void
    {
        $user = User::factory()->create();

        $plainOtp = '654321';

        $user->multiFactorAuths()->create([
            'method' => 'email',
            'preferred' => true,
            'otp' => $plainOtp,
            'expires_at' => now()->addMinutes(10),
        ]);

        $user->load('multiFactorAuths');
        $user->verifyMFAOTP('email', $plainOtp);

        $auth = $user->multiFactorAuths()->where('method', 'email')->first();
        $this->assertNull($auth->getAttributes()['otp']);
        $this->assertNull($auth->expires_at);
        $this->assertNotNull($auth->last_used);
    }

    // -----------------------------------------------------------------
    // verifyMFAOTP() - recovery
    // -----------------------------------------------------------------

    public function test_verify_mfa_otp_recovery_returns_true_for_valid_code(): void
    {
        $user = User::factory()->create();

        config(['neev.recovery_codes' => 8]);
        $codes = $user->generateRecoveryCodes();

        // Load recovery codes into the relation
        $user->load('recoveryCodes');

        $verified = $user->verifyMFAOTP('recovery', $codes[0]);

        $this->assertTrue($verified);
    }

    public function test_verify_mfa_otp_recovery_returns_false_for_invalid_code(): void
    {
        $user = User::factory()->create();

        config(['neev.recovery_codes' => 8]);
        $user->generateRecoveryCodes();

        $user->load('recoveryCodes');

        $verified = $user->verifyMFAOTP('recovery', 'invalidcode');

        $this->assertFalse($verified);
    }

    public function test_verify_mfa_otp_recovery_replaces_used_code(): void
    {
        $user = User::factory()->create();

        config(['neev.recovery_codes' => 8]);
        $codes = $user->generateRecoveryCodes();
        $usedCode = $codes[0];

        $user->load('recoveryCodes');
        $user->verifyMFAOTP('recovery', $usedCode);

        // The old code should no longer work
        $user->load('recoveryCodes');
        $verified = $user->verifyMFAOTP('recovery', $usedCode);

        $this->assertFalse($verified);
    }

    // -----------------------------------------------------------------
    // verifyMFAOTP() - no auth record
    // -----------------------------------------------------------------

    public function test_verify_mfa_otp_returns_false_for_unknown_method_with_no_auth_record(): void
    {
        $user = User::factory()->create();
        $user->load('multiFactorAuths');

        $verified = $user->verifyMFAOTP('authenticator', '123456');

        $this->assertFalse($verified);
    }

    // -----------------------------------------------------------------
    // generateRecoveryCodes()
    // -----------------------------------------------------------------

    public function test_generate_recovery_codes_generates_correct_count(): void
    {
        $user = User::factory()->create();

        config(['neev.recovery_codes' => 10]);

        $codes = $user->generateRecoveryCodes();

        $this->assertCount(10, $codes);
        $this->assertEquals(10, $user->recoveryCodes()->count());
    }

    public function test_generate_recovery_codes_returns_plain_text_codes(): void
    {
        $user = User::factory()->create();

        config(['neev.recovery_codes' => 4]);

        $codes = $user->generateRecoveryCodes();

        foreach ($codes as $code) {
            $this->assertIsString($code);
            $this->assertEquals(10, strlen($code));
        }
    }

    public function test_generate_recovery_codes_deletes_old_codes_first(): void
    {
        $user = User::factory()->create();

        config(['neev.recovery_codes' => 5]);

        $firstCodes = $user->generateRecoveryCodes();
        $this->assertEquals(5, $user->recoveryCodes()->count());

        $secondCodes = $user->generateRecoveryCodes();
        $this->assertEquals(5, $user->recoveryCodes()->count());

        // New codes should be different from old codes
        $this->assertNotEquals($firstCodes, $secondCodes);
    }

    public function test_generate_recovery_codes_stores_hashed_codes_in_db(): void
    {
        $user = User::factory()->create();

        config(['neev.recovery_codes' => 3]);

        $codes = $user->generateRecoveryCodes();

        // The stored codes should be hashed (not plain text)
        $storedCodes = $user->recoveryCodes()->pluck('code')->toArray();
        foreach ($storedCodes as $index => $storedCode) {
            // Hashed values should not match plain text
            $this->assertNotEquals($codes[$index], $storedCode);
            // But Hash::check should verify them
            $this->assertTrue(Hash::check($codes[$index], $storedCode));
        }
    }

    // -----------------------------------------------------------------
    // recoveryCodes()
    // -----------------------------------------------------------------

    public function test_recovery_codes_relationship_works(): void
    {
        $user = User::factory()->create();

        $user->recoveryCodes()->create(['code' => 'testcode01']);
        $user->recoveryCodes()->create(['code' => 'testcode02']);

        $this->assertCount(2, $user->recoveryCodes);
    }
}
