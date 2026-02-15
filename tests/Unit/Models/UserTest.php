<?php

namespace Ssntpl\Neev\Tests\Unit\Models;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Models\Email;
use Ssntpl\Neev\Models\LoginAttempt;
use Ssntpl\Neev\Models\Passkey;
use Ssntpl\Neev\Models\Password;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // model() & getClass()
    // -----------------------------------------------------------------

    public function test_model_returns_configured_model_instance(): void
    {
        $instance = User::model();

        $this->assertInstanceOf(User::class, $instance);
    }

    public function test_get_class_returns_configured_class_string(): void
    {
        $class = User::getClass();

        $this->assertSame(User::class, $class);
    }

    public function test_model_respects_config_override(): void
    {
        config(['neev.user_model' => User::class]);

        $this->assertSame(User::class, User::getClass());
        $this->assertInstanceOf(User::class, User::model());
    }

    // -----------------------------------------------------------------
    // Fillable fields
    // -----------------------------------------------------------------

    public function test_fillable_fields(): void
    {
        $user = new User();

        $this->assertEquals(['name', 'username', 'active'], $user->getFillable());
    }

    // -----------------------------------------------------------------
    // Casts
    // -----------------------------------------------------------------

    public function test_active_is_cast_to_boolean(): void
    {
        $user = User::factory()->create(['active' => 1]);

        $this->assertIsBool($user->active);
        $this->assertTrue($user->active);
    }

    public function test_active_false_is_cast_to_boolean(): void
    {
        $user = User::factory()->create(['active' => 0]);

        $this->assertIsBool($user->active);
        $this->assertFalse($user->active);
    }

    // -----------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------

    public function test_emails_returns_has_many_relationship(): void
    {
        $user = User::factory()->create();

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $user->emails());
        // UserFactory afterCreating creates one primary email
        $this->assertCount(1, $user->emails);
    }

    public function test_emails_returns_all_emails_for_user(): void
    {
        $user = User::factory()->create();

        // Add a secondary email
        Email::create([
            'user_id' => $user->id,
            'email' => 'secondary@example.com',
            'is_primary' => false,
            'verified_at' => now(),
        ]);

        $user->refresh();

        $this->assertCount(2, $user->emails);
    }

    public function test_email_returns_primary_email(): void
    {
        $user = User::factory()->create();

        $primaryEmail = $user->email;

        $this->assertNotNull($primaryEmail);
        $this->assertInstanceOf(Email::class, $primaryEmail);
        $this->assertTrue($primaryEmail->is_primary);
    }

    public function test_email_returns_has_one_relationship(): void
    {
        $user = User::factory()->create();

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasOne::class, $user->email());
    }

    public function test_passwords_returns_has_many_relationship(): void
    {
        $user = User::factory()->create();

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $user->passwords());
        // UserFactory afterCreating creates one password
        $this->assertCount(1, $user->passwords);
    }

    public function test_password_returns_latest_password(): void
    {
        $user = User::factory()->create();

        // Add another password
        Password::create([
            'user_id' => $user->id,
            'password' => 'new-password-123',
        ]);

        $user->refresh();

        $latestPassword = $user->password;

        $this->assertNotNull($latestPassword);
        $this->assertInstanceOf(Password::class, $latestPassword);
        $this->assertCount(2, $user->passwords);
    }

    public function test_login_attempts_returns_has_many_relationship(): void
    {
        $user = User::factory()->create();

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $user->loginAttempts());

        LoginAttempt::create([
            'user_id' => $user->id,
            'method' => LoginAttempt::Password,
            'platform' => 'Windows',
            'browser' => 'Chrome',
            'device' => 'Desktop',
            'ip_address' => '127.0.0.1',
            'is_success' => true,
            'is_suspicious' => false,
        ]);

        $user->refresh();

        $this->assertCount(1, $user->loginAttempts);
        $this->assertInstanceOf(LoginAttempt::class, $user->loginAttempts->first());
    }

    public function test_passkeys_returns_has_many_relationship(): void
    {
        $user = User::factory()->create();

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $user->passkeys());

        Passkey::create([
            'user_id' => $user->id,
            'credential_id' => 'test-credential-id',
            'public_key' => 'test-public-key',
            'name' => 'My Passkey',
            'aaguid' => 'test-aaguid',
        ]);

        $user->refresh();

        $this->assertCount(1, $user->passkeys);
        $this->assertInstanceOf(Passkey::class, $user->passkeys->first());
    }

    // -----------------------------------------------------------------
    // activate() / deactivate()
    // -----------------------------------------------------------------

    public function test_activate_sets_active_to_true_and_saves(): void
    {
        $user = User::factory()->create(['active' => false]);

        $this->assertFalse($user->active);

        $user->activate();

        $this->assertTrue($user->active);

        // Verify persisted
        $user->refresh();
        $this->assertTrue($user->active);
    }

    public function test_deactivate_sets_active_to_false_and_saves(): void
    {
        $user = User::factory()->create(['active' => true]);

        $this->assertTrue($user->active);

        $user->deactivate();

        $this->assertFalse($user->active);

        // Verify persisted
        $user->refresh();
        $this->assertFalse($user->active);
    }

    // -----------------------------------------------------------------
    // getProfilePhotoUrlAttribute()
    // -----------------------------------------------------------------

    public function test_profile_photo_url_returns_initials_when_no_photo_set(): void
    {
        $user = User::factory()->create(['name' => 'John Doe']);

        $this->assertSame('JD', $user->profile_photo_url);
    }

    public function test_profile_photo_url_returns_single_initial_for_single_name(): void
    {
        $user = User::factory()->create(['name' => 'Alice']);

        $this->assertSame('A', $user->profile_photo_url);
    }

    public function test_profile_photo_url_returns_three_initials_for_three_part_name(): void
    {
        $user = User::factory()->create(['name' => 'John Michael Doe']);

        $this->assertSame('JMD', $user->profile_photo_url);
    }

    public function test_profile_photo_url_returns_uppercase_initials(): void
    {
        $user = User::factory()->create(['name' => 'jane smith']);

        $this->assertSame('JS', $user->profile_photo_url);
    }
}
