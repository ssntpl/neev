<?php

namespace Ssntpl\Neev\Tests\Unit\Models;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Ssntpl\Neev\Database\Factories\DomainFactory;
use Ssntpl\Neev\Database\Factories\EmailFactory;
use Ssntpl\Neev\Database\Factories\MultiFactorAuthFactory;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Models\Domain;
use Ssntpl\Neev\Models\DomainRule;
use Ssntpl\Neev\Models\Email;
use Ssntpl\Neev\Models\Membership;
use Ssntpl\Neev\Models\MultiFactorAuth;
use Ssntpl\Neev\Models\OTP;
use Ssntpl\Neev\Models\Passkey;
use Ssntpl\Neev\Models\RecoveryCode;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\TeamInvitation;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;

class SimpleModelsTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Set a valid 32-byte key for the encrypted cast on MultiFactorAuth secret
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
    }

    // =================================================================
    // Email Model
    // =================================================================

    public function test_email_user_relationship(): void
    {
        $user = User::factory()->create();

        $email = EmailFactory::new()->create([
            'user_id' => $user->id,
            'email' => 'test@example.com',
        ]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class, $email->user());
        $this->assertInstanceOf(User::class, $email->user);
        $this->assertSame($user->id, $email->user->id);
    }

    public function test_email_otp_relationship(): void
    {
        $user = User::factory()->create();

        $email = EmailFactory::new()->create([
            'user_id' => $user->id,
            'email' => 'otp-test@example.com',
        ]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\MorphOne::class, $email->otp());

        // Create an OTP for this email
        OTP::create([
            'owner_id' => $email->id,
            'owner_type' => Email::class,
            'otp' => 123456,
            'expires_at' => now()->addMinutes(15),
        ]);

        $email->refresh();

        $this->assertNotNull($email->otp);
        $this->assertInstanceOf(OTP::class, $email->otp);
    }

    public function test_email_verified_at_is_cast_to_datetime(): void
    {
        $email = EmailFactory::new()->create();

        $email->refresh();

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $email->verified_at);
    }

    public function test_email_verified_at_null_for_unverified(): void
    {
        $email = EmailFactory::new()->unverified()->create();

        $email->refresh();

        $this->assertNull($email->verified_at);
    }

    public function test_email_is_primary_is_cast_to_boolean(): void
    {
        $email = EmailFactory::new()->primary()->create();

        $email->refresh();

        $this->assertIsBool($email->is_primary);
        $this->assertTrue($email->is_primary);
    }

    public function test_email_is_primary_false_cast(): void
    {
        $email = EmailFactory::new()->create(['is_primary' => false]);

        $email->refresh();

        $this->assertIsBool($email->is_primary);
        $this->assertFalse($email->is_primary);
    }

    // =================================================================
    // OTP Model
    // =================================================================

    public function test_otp_table_name(): void
    {
        $otp = new OTP();

        $this->assertSame('otp', $otp->getTable());
    }

    public function test_otp_expires_at_is_cast_to_datetime(): void
    {
        $user = User::factory()->create();
        $email = EmailFactory::new()->create(['user_id' => $user->id]);

        $otp = OTP::create([
            'owner_id' => $email->id,
            'owner_type' => Email::class,
            'otp' => 654321,
            'expires_at' => now()->addMinutes(15),
        ]);

        $otp->refresh();

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $otp->expires_at);
    }

    public function test_otp_value_is_hashed_in_database(): void
    {
        $user = User::factory()->create();
        $email = EmailFactory::new()->create(['user_id' => $user->id]);

        $plainOtp = 123456;

        $otp = OTP::create([
            'owner_id' => $email->id,
            'owner_type' => Email::class,
            'otp' => $plainOtp,
            'expires_at' => now()->addMinutes(15),
        ]);

        $rawValue = DB::table('otp')
            ->where('id', $otp->id)
            ->value('otp');

        // Hashed value should not match the plain integer cast to string
        $this->assertNotSame((string) $plainOtp, (string) $rawValue);
        $this->assertTrue(Hash::check($plainOtp, $rawValue));
    }

    public function test_otp_owner_morph_to_relationship(): void
    {
        $user = User::factory()->create();
        $email = EmailFactory::new()->create(['user_id' => $user->id]);

        $otp = OTP::create([
            'owner_id' => $email->id,
            'owner_type' => Email::class,
            'otp' => 111222,
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\MorphTo::class, $otp->owner());
        $this->assertInstanceOf(Email::class, $otp->owner);
        $this->assertSame($email->id, $otp->owner->id);
    }

    // =================================================================
    // MultiFactorAuth Model
    // =================================================================

    public function test_mfa_secret_is_encrypted_cast(): void
    {
        $plainSecret = 'JBSWY3DPEHPK3PXP';

        $mfa = MultiFactorAuthFactory::new()->create([
            'secret' => $plainSecret,
        ]);

        $rawValue = DB::table('multi_factor_auths')
            ->where('id', $mfa->id)
            ->value('secret');

        $this->assertNotSame($plainSecret, $rawValue);

        $mfa->refresh();
        $this->assertSame($plainSecret, $mfa->secret);
    }

    public function test_mfa_otp_is_hashed_cast(): void
    {
        $plainOtp = 123456;

        $mfa = MultiFactorAuthFactory::new()->create([
            'otp' => $plainOtp,
        ]);

        $rawValue = DB::table('multi_factor_auths')
            ->where('id', $mfa->id)
            ->value('otp');

        $this->assertNotSame((string) $plainOtp, (string) $rawValue);
        $this->assertTrue(Hash::check($plainOtp, $rawValue));
    }

    public function test_mfa_preferred_is_cast_to_boolean(): void
    {
        $mfa = MultiFactorAuthFactory::new()->create(['preferred' => true]);

        $mfa->refresh();

        $this->assertIsBool($mfa->preferred);
        $this->assertTrue($mfa->preferred);
    }

    public function test_mfa_expires_at_is_cast_to_datetime(): void
    {
        $mfa = MultiFactorAuthFactory::new()->create([
            'expires_at' => now()->addMinutes(10),
        ]);

        $mfa->refresh();

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $mfa->expires_at);
    }

    public function test_mfa_last_used_is_cast_to_datetime(): void
    {
        $mfa = MultiFactorAuthFactory::new()->create([
            'last_used' => now(),
        ]);

        $mfa->refresh();

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $mfa->last_used);
    }

    public function test_mfa_user_relationship(): void
    {
        $user = User::factory()->create();
        $mfa = MultiFactorAuthFactory::new()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class, $mfa->user());
        $this->assertInstanceOf(User::class, $mfa->user);
        $this->assertSame($user->id, $mfa->user->id);
    }

    // =================================================================
    // RecoveryCode Model
    // =================================================================

    public function test_recovery_code_is_hashed_in_database(): void
    {
        $user = User::factory()->create();
        $plainCode = 'ABCD-1234-EFGH';

        $code = RecoveryCode::create([
            'user_id' => $user->id,
            'code' => $plainCode,
        ]);

        $rawValue = DB::table('recovery_codes')
            ->where('id', $code->id)
            ->value('code');

        $this->assertNotSame($plainCode, $rawValue);
        $this->assertTrue(Hash::check($plainCode, $rawValue));
    }

    public function test_recovery_code_user_relationship(): void
    {
        $user = User::factory()->create();

        $code = RecoveryCode::create([
            'user_id' => $user->id,
            'code' => 'recovery-code-1',
        ]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class, $code->user());
        $this->assertInstanceOf(User::class, $code->user);
        $this->assertSame($user->id, $code->user->id);
    }

    // =================================================================
    // Passkey Model
    // =================================================================

    public function test_passkey_transports_is_cast_to_array(): void
    {
        $user = User::factory()->create();

        $passkey = Passkey::create([
            'user_id' => $user->id,
            'credential_id' => 'test-cred-id',
            'public_key' => 'test-public-key',
            'aaguid' => 'test-aaguid',
            'transports' => ['usb', 'nfc', 'ble'],
        ]);

        $passkey->refresh();

        $this->assertIsArray($passkey->transports);
        $this->assertEquals(['usb', 'nfc', 'ble'], $passkey->transports);
    }

    public function test_passkey_location_is_cast_to_array(): void
    {
        $user = User::factory()->create();

        $passkey = Passkey::create([
            'user_id' => $user->id,
            'credential_id' => 'test-cred-id-2',
            'public_key' => 'test-public-key',
            'aaguid' => 'test-aaguid',
            'location' => ['city' => 'London', 'country' => 'UK'],
        ]);

        $passkey->refresh();

        $this->assertIsArray($passkey->location);
        $this->assertSame('London', $passkey->location['city']);
    }

    public function test_passkey_last_used_is_cast_to_datetime(): void
    {
        $user = User::factory()->create();

        $passkey = Passkey::create([
            'user_id' => $user->id,
            'credential_id' => 'test-cred-id-3',
            'public_key' => 'test-public-key',
            'aaguid' => 'test-aaguid',
            'last_used' => now(),
        ]);

        $passkey->refresh();

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $passkey->last_used);
    }

    public function test_passkey_user_relationship(): void
    {
        $user = User::factory()->create();

        $passkey = Passkey::create([
            'user_id' => $user->id,
            'credential_id' => 'test-cred-id-4',
            'public_key' => 'test-public-key',
            'aaguid' => 'test-aaguid',
        ]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class, $passkey->user());
        $this->assertInstanceOf(User::class, $passkey->user);
        $this->assertSame($user->id, $passkey->user->id);
    }

    // =================================================================
    // TeamInvitation Model
    // =================================================================

    public function test_team_invitation_profile_photo_url_returns_first_letter_uppercase(): void
    {
        $team = TeamFactory::new()->create();

        $invitation = TeamInvitation::create([
            'team_id' => $team->id,
            'email' => 'john@example.com',
            'role' => 'member',
        ]);

        $this->assertSame('J', $invitation->profile_photo_url);
    }

    public function test_team_invitation_profile_photo_url_handles_lowercase_email(): void
    {
        $team = TeamFactory::new()->create();

        $invitation = TeamInvitation::create([
            'team_id' => $team->id,
            'email' => 'alice@example.com',
            'role' => 'member',
        ]);

        $this->assertSame('A', $invitation->profile_photo_url);
    }

    public function test_team_invitation_profile_photo_url_handles_uppercase_email(): void
    {
        $team = TeamFactory::new()->create();

        $invitation = TeamInvitation::create([
            'team_id' => $team->id,
            'email' => 'Bob@example.com',
            'role' => 'member',
        ]);

        $this->assertSame('B', $invitation->profile_photo_url);
    }

    public function test_team_invitation_expires_at_is_cast_to_datetime(): void
    {
        $team = TeamFactory::new()->create();

        $invitation = TeamInvitation::create([
            'team_id' => $team->id,
            'email' => 'test@example.com',
            'role' => 'member',
            'expires_at' => now()->addDays(7),
        ]);

        $invitation->refresh();

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $invitation->expires_at);
    }

    public function test_team_invitation_team_relationship(): void
    {
        $team = TeamFactory::new()->create();

        $invitation = TeamInvitation::create([
            'team_id' => $team->id,
            'email' => 'test@example.com',
            'role' => 'member',
        ]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class, $invitation->team());
        $this->assertInstanceOf(Team::class, $invitation->team);
        $this->assertSame($team->id, $invitation->team->id);
    }

    // =================================================================
    // DomainRule Model
    // =================================================================

    public function test_domain_rule_domain_relationship(): void
    {
        $domain = DomainFactory::new()->create();

        $rule = DomainRule::create([
            'domain_id' => $domain->id,
            'name' => 'mfa_required',
            'value' => 'true',
        ]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class, $rule->domain());
        $this->assertInstanceOf(Domain::class, $rule->domain);
        $this->assertSame($domain->id, $rule->domain->id);
    }

    public function test_domain_rule_fillable_fields(): void
    {
        $domain = DomainFactory::new()->create();

        $rule = DomainRule::create([
            'domain_id' => $domain->id,
            'name' => 'password_policy',
            'value' => 'strict',
        ]);

        $this->assertSame('password_policy', $rule->name);
        $this->assertSame('strict', $rule->value);
    }

    // =================================================================
    // Membership Model
    // =================================================================

    public function test_membership_joined_is_cast_to_boolean(): void
    {
        $user = User::factory()->create();
        $team = TeamFactory::new()->create();

        $team->allUsers()->attach($user->id, [
            'role' => 'member',
            'joined' => true,
            'action' => 'request_to_user',
        ]);

        $membership = Membership::where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->first();

        $this->assertIsBool($membership->joined);
        $this->assertTrue($membership->joined);
    }

    public function test_membership_joined_false_is_cast_to_boolean(): void
    {
        $user = User::factory()->create();
        $team = TeamFactory::new()->create();

        $team->allUsers()->attach($user->id, [
            'role' => 'member',
            'joined' => false,
            'action' => 'request_to_user',
        ]);

        $membership = Membership::where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->first();

        $this->assertIsBool($membership->joined);
        $this->assertFalse($membership->joined);
    }

    public function test_membership_incrementing_is_true(): void
    {
        $membership = new Membership();

        $this->assertTrue($membership->incrementing);
    }

    public function test_membership_uses_team_user_table(): void
    {
        $membership = new Membership();

        $this->assertSame('team_user', $membership->getTable());
    }
}
