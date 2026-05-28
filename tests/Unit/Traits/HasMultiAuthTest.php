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
    // multiFactorAuths() — unfiltered, returns all statuses
    // -----------------------------------------------------------------

    public function test_multi_factor_auths_returns_all_records_including_pending(): void
    {
        $user = User::factory()->create();

        $user->multiFactorAuths()->create([
            'method' => 'authenticator',
            'preferred' => true,
            'status' => MultiFactorAuth::STATUS_PENDING,
        ]);
        $user->multiFactorAuths()->create([
            'method' => 'email',
            'preferred' => false,
            'status' => MultiFactorAuth::STATUS_ACTIVE,
        ]);

        $this->assertCount(2, $user->multiFactorAuths);
    }

    public function test_multi_factor_auths_returns_empty_when_none_configured(): void
    {
        $user = User::factory()->create();

        $this->assertCount(0, $user->multiFactorAuths);
    }

    // -----------------------------------------------------------------
    // activeMultiFactorAuths() — relation filters to active only
    // -----------------------------------------------------------------

    public function test_active_multi_factor_auths_returns_only_active(): void
    {
        $user = User::factory()->create();

        $user->multiFactorAuths()->create([
            'method' => 'authenticator',
            'status' => MultiFactorAuth::STATUS_ACTIVE,
        ]);
        $user->multiFactorAuths()->create([
            'method' => 'email',
            'status' => MultiFactorAuth::STATUS_ACTIVE,
        ]);

        $this->assertCount(2, $user->activeMultiFactorAuths);
    }

    public function test_active_multi_factor_auths_excludes_pending(): void
    {
        $user = User::factory()->create();

        $user->multiFactorAuths()->create([
            'method' => 'authenticator',
            'status' => MultiFactorAuth::STATUS_PENDING,
        ]);

        $this->assertCount(0, $user->activeMultiFactorAuths);
    }

    // -----------------------------------------------------------------
    // multiFactorAuth($method) — returns active row only
    // -----------------------------------------------------------------

    public function test_multi_factor_auth_finds_active_by_method(): void
    {
        $user = User::factory()->create();

        $user->multiFactorAuths()->create([
            'method' => 'authenticator',
            'preferred' => true,
            'status' => MultiFactorAuth::STATUS_ACTIVE,
        ]);

        $user->load('activeMultiFactorAuths');

        $auth = $user->multiFactorAuth('authenticator');

        $this->assertNotNull($auth);
        $this->assertEquals('authenticator', $auth->method);
    }

    public function test_multi_factor_auth_returns_null_for_unknown_method(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->multiFactorAuth('sms'));
    }

    public function test_multi_factor_auth_does_not_return_pending(): void
    {
        $user = User::factory()->create();

        $user->multiFactorAuths()->create([
            'method' => 'authenticator',
            'status' => MultiFactorAuth::STATUS_PENDING,
        ]);

        $user->load('activeMultiFactorAuths');

        $this->assertNull($user->multiFactorAuth('authenticator'));
    }

    // -----------------------------------------------------------------
    // pendingMultiFactorAuth($method)
    // -----------------------------------------------------------------

    public function test_pending_multi_factor_auth_finds_pending_row(): void
    {
        $user = User::factory()->create();

        MultiFactorAuth::create([
            'user_id' => $user->id,
            'method' => 'authenticator',
            'status' => MultiFactorAuth::STATUS_PENDING,
        ]);

        $pending = $user->pendingMultiFactorAuth('authenticator');

        $this->assertNotNull($pending);
        $this->assertEquals('authenticator', $pending->method);
        $this->assertEquals('pending', $pending->status);
    }

    public function test_pending_multi_factor_auth_does_not_return_active(): void
    {
        $user = User::factory()->create();

        $user->multiFactorAuths()->create([
            'method' => 'authenticator',
            'status' => MultiFactorAuth::STATUS_ACTIVE,
        ]);

        $this->assertNull($user->pendingMultiFactorAuth('authenticator'));
    }

    // -----------------------------------------------------------------
    // preferredMultiFactorAuth() — limited to active rows
    // -----------------------------------------------------------------

    public function test_preferred_multi_factor_auth_returns_preferred_active(): void
    {
        $user = User::factory()->create();

        $user->multiFactorAuths()->create([
            'method' => 'authenticator',
            'preferred' => false,
            'status' => MultiFactorAuth::STATUS_ACTIVE,
        ]);
        $user->multiFactorAuths()->create([
            'method' => 'email',
            'preferred' => true,
            'status' => MultiFactorAuth::STATUS_ACTIVE,
        ]);

        $preferred = $user->preferredMultiFactorAuth;

        $this->assertNotNull($preferred);
        $this->assertEquals('email', $preferred->method);
    }

    public function test_preferred_multi_factor_auth_returns_null_when_only_pending(): void
    {
        $user = User::factory()->create();

        MultiFactorAuth::create([
            'user_id' => $user->id,
            'method' => 'authenticator',
            'preferred' => true,
            'status' => MultiFactorAuth::STATUS_PENDING,
        ]);

        $this->assertNull($user->preferredMultiFactorAuth);
    }

    // -----------------------------------------------------------------
    // addMultiFactorAuth('authenticator') — creates a pending row
    // -----------------------------------------------------------------

    public function test_add_authenticator_creates_pending_row_and_returns_qr_code(): void
    {
        $user = User::factory()->create();
        $user->load('multiFactorAuths', 'preferredMultiFactorAuth');

        $result = $user->addMultiFactorAuth('authenticator');

        $this->assertIsArray($result);
        $this->assertEquals('Success', $result['status']);
        $this->assertArrayHasKey('qr_code', $result);
        $this->assertArrayHasKey('secret', $result);
        $this->assertEquals('authenticator', $result['method']);
        $this->assertNotEmpty($result['qr_code']);
        $this->assertNotEmpty($result['secret']);

        // Row exists in pending state; active relation excludes it
        $this->assertDatabaseHas('multi_factor_auths', [
            'user_id' => $user->id,
            'method' => 'authenticator',
            'status' => MultiFactorAuth::STATUS_PENDING,
        ]);
        $this->assertEquals(0, $user->activeMultiFactorAuths()->where('method', 'authenticator')->count());
        $this->assertNotNull($user->pendingMultiFactorAuth('authenticator'));
    }

    public function test_add_authenticator_replaces_existing_pending_row_with_fresh_secret(): void
    {
        $user = User::factory()->create();
        $user->load('multiFactorAuths', 'preferredMultiFactorAuth');

        $first = $user->addMultiFactorAuth('authenticator');
        $user->load('multiFactorAuths', 'preferredMultiFactorAuth');
        $second = $user->addMultiFactorAuth('authenticator');

        // Each call generates a new secret — re-clicking restarts setup.
        $this->assertNotEquals($first['secret'], $second['secret']);
        // Still only one row (updateOrCreate keyed on user_id+method).
        $this->assertEquals(1, MultiFactorAuth::where('user_id', $user->id)
            ->where('method', 'authenticator')
            ->count());
    }

    public function test_add_authenticator_rejects_when_already_active(): void
    {
        $user = User::factory()->create();

        $user->multiFactorAuths()->create([
            'method' => 'authenticator',
            'status' => MultiFactorAuth::STATUS_ACTIVE,
        ]);
        $user->load('multiFactorAuths', 'preferredMultiFactorAuth');

        $result = $user->addMultiFactorAuth('authenticator');

        $this->assertEquals('Error', $result['status']);
        $this->assertEquals('Authenticator already configured.', $result['message']);
    }

    // -----------------------------------------------------------------
    // addMultiFactorAuth('email') — creates an active row directly
    // -----------------------------------------------------------------

    public function test_add_email_creates_active_record(): void
    {
        $user = User::factory()->create();
        $user->load('multiFactorAuths', 'preferredMultiFactorAuth');

        $result = $user->addMultiFactorAuth('email');

        $this->assertEquals('Success', $result['status']);
        $this->assertEquals('Email Configured.', $result['message']);

        $this->assertDatabaseHas('multi_factor_auths', [
            'user_id' => $user->id,
            'method' => 'email',
            'status' => MultiFactorAuth::STATUS_ACTIVE,
        ]);
    }

    public function test_add_email_returns_already_configured_if_exists(): void
    {
        $user = User::factory()->create();

        $user->multiFactorAuths()->create([
            'method' => 'email',
            'preferred' => true,
            'status' => MultiFactorAuth::STATUS_ACTIVE,
        ]);
        $user->load('multiFactorAuths', 'preferredMultiFactorAuth');

        $result = $user->addMultiFactorAuth('email');

        $this->assertEquals('Email already Configured.', $result['message']);
        $this->assertEquals(1, $user->multiFactorAuths()->where('method', 'email')->count());
    }

    public function test_add_with_unknown_method_returns_null(): void
    {
        $user = User::factory()->create();
        $user->load('multiFactorAuths', 'preferredMultiFactorAuth');

        $this->assertNull($user->addMultiFactorAuth('sms'));
    }

    // -----------------------------------------------------------------
    // MultiFactorAuth::verifyOTP() — instance method, dispatches by method
    // -----------------------------------------------------------------

    public function test_verify_authenticator_otp_returns_true_for_valid_otp(): void
    {
        $user = User::factory()->create();
        $user->load('multiFactorAuths', 'preferredMultiFactorAuth');

        $result = $user->addMultiFactorAuth('authenticator');
        $secret = $result['secret'];
        $validOtp = TOTP::create($secret)->now();

        $pending = $user->pendingMultiFactorAuth('authenticator');
        $this->assertNotNull($pending);

        $this->assertTrue($pending->verifyOTP($validOtp));

        // After verify, row becomes active and last_used is set
        $pending->refresh();
        $this->assertEquals(MultiFactorAuth::STATUS_ACTIVE, $pending->status);
        $this->assertNotNull($pending->last_used);
    }

    public function test_verify_authenticator_otp_returns_false_for_wrong_otp(): void
    {
        $user = User::factory()->create();
        $user->load('multiFactorAuths', 'preferredMultiFactorAuth');

        $user->addMultiFactorAuth('authenticator');
        $pending = $user->pendingMultiFactorAuth('authenticator');

        $this->assertFalse($pending->verifyOTP('000000'));
        $pending->refresh();
        $this->assertEquals(MultiFactorAuth::STATUS_PENDING, $pending->status);
    }

    public function test_verify_email_otp_returns_true_for_valid_otp_and_consumes_it(): void
    {
        $user = User::factory()->create();

        $auth = $user->multiFactorAuths()->create([
            'method' => 'email',
            'preferred' => true,
            'status' => MultiFactorAuth::STATUS_ACTIVE,
            'otp' => '654321',
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->assertTrue($auth->verifyOTP('654321'));
        $auth->refresh();
        $this->assertNull($auth->getAttributes()['otp']);
        $this->assertNull($auth->expires_at);
        $this->assertNotNull($auth->last_used);
    }

    public function test_verify_email_otp_returns_false_when_expired(): void
    {
        $user = User::factory()->create();

        $auth = $user->multiFactorAuths()->create([
            'method' => 'email',
            'preferred' => true,
            'status' => MultiFactorAuth::STATUS_ACTIVE,
            'otp' => '123456',
            'expires_at' => now()->subMinutes(1),
        ]);

        $this->assertFalse($auth->verifyOTP('123456'));
    }

    public function test_verify_email_otp_returns_false_for_wrong_otp(): void
    {
        $user = User::factory()->create();

        $auth = $user->multiFactorAuths()->create([
            'method' => 'email',
            'preferred' => true,
            'status' => MultiFactorAuth::STATUS_ACTIVE,
            'otp' => '123456',
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->assertFalse($auth->verifyOTP('999999'));
    }

    // -----------------------------------------------------------------
    // MultiFactorAuth::verifyAuthenticatorOTP() — pure static helper
    // -----------------------------------------------------------------

    public function test_static_authenticator_verify_returns_true_for_valid_otp(): void
    {
        $totp = TOTP::create();
        $secret = $totp->getSecret();
        $validOtp = $totp->now();

        $this->assertTrue(MultiFactorAuth::verifyAuthenticatorOTP($secret, $validOtp));
    }

    public function test_static_authenticator_verify_returns_false_for_wrong_otp(): void
    {
        $totp = TOTP::create();

        $this->assertFalse(MultiFactorAuth::verifyAuthenticatorOTP($totp->getSecret(), '000000'));
    }

    // -----------------------------------------------------------------
    // RecoveryCode::verify() — instance method
    // -----------------------------------------------------------------

    public function test_recovery_code_verify_returns_true_for_correct_code(): void
    {
        $user = User::factory()->create();

        config(['neev.recovery_codes' => 4]);
        $codes = $user->generateRecoveryCodes();

        $stored = $user->recoveryCodes()->first();
        $this->assertTrue($stored->verify($codes[0]));
    }

    public function test_recovery_code_verify_returns_false_for_wrong_code(): void
    {
        $user = User::factory()->create();

        config(['neev.recovery_codes' => 4]);
        $user->generateRecoveryCodes();

        $stored = $user->recoveryCodes()->first();
        $this->assertFalse($stored->verify('not-the-code'));
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

        $this->assertNotEquals($firstCodes, $secondCodes);
    }

    public function test_generate_recovery_codes_stores_hashed_codes_in_db(): void
    {
        $user = User::factory()->create();

        config(['neev.recovery_codes' => 3]);

        $codes = $user->generateRecoveryCodes();

        $storedCodes = $user->recoveryCodes()->pluck('code')->toArray();
        foreach ($storedCodes as $index => $storedCode) {
            $this->assertNotEquals($codes[$index], $storedCode);
            $this->assertTrue(Hash::check($codes[$index], $storedCode));
        }
    }

    public function test_recovery_codes_relationship_works(): void
    {
        $user = User::factory()->create();

        $user->recoveryCodes()->create(['code' => 'testcode01']);
        $user->recoveryCodes()->create(['code' => 'testcode02']);

        $this->assertCount(2, $user->recoveryCodes);
    }
}
