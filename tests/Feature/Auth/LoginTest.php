<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Models\LoginAttempt;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class LoginTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    /**
     * Create a user with a known email and plaintext password.
     *
     * The User factory's afterCreating hook creates an Email (with a random
     * safeEmail) and a Password record with plaintext 'password'. The
     * Password model's 'hashed' cast automatically hashes it on storage.
     */
    private function createUser(array $userState = []): User
    {
        return User::factory()->create($userState);
    }

    public function test_successful_login_returns_token(): void
    {
        $user = $this->createUser();
        $email = $user->email->email;

        $response = $this->postJson('/neev/login', [
            'email' => $email,
            'password' => 'password',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['status', 'token', 'email_verified']);
        $response->assertJson(['status' => 'Success']);
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_login_returns_email_verified_true_for_verified_user(): void
    {
        $user = $this->createUser();
        $email = $user->email;

        // Factory creates emails as verified by default
        $this->assertNotNull($email->verified_at);

        $response = $this->postJson('/neev/login', [
            'email' => $email->email,
            'password' => 'password',
        ]);

        $response->assertOk();
        $response->assertJson(['email_verified' => true]);
    }

    public function test_login_returns_email_verified_false_for_unverified_user(): void
    {
        $user = $this->createUser();
        $email = $user->email;
        $email->update(['verified_at' => null]);

        $response = $this->postJson('/neev/login', [
            'email' => $email->email,
            'password' => 'password',
        ]);

        $response->assertOk();
        $response->assertJson(['email_verified' => false]);
    }

    public function test_wrong_password_returns_401(): void
    {
        $user = $this->createUser();

        $response = $this->postJson('/neev/login', [
            'email' => $user->email->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'status' => 'Failed',
            'message' => 'Credentials are wrong.',
        ]);
    }

    public function test_non_existent_email_returns_401(): void
    {
        $response = $this->postJson('/neev/login', [
            'email' => 'nobody@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'status' => 'Failed',
            'message' => 'Credentials are wrong.',
        ]);
    }

    public function test_inactive_user_returns_validation_error(): void
    {
        $user = $this->createUser(['active' => false]);

        $response = $this->postJson('/neev/login', [
            'email' => $user->email->email,
            'password' => 'password',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_login_records_failed_attempt_when_enabled(): void
    {
        config(['neev.record_failed_login_attempts' => true]);

        $user = $this->createUser();

        $this->postJson('/neev/login', [
            'email' => $user->email->email,
            'password' => 'wrong-password',
        ]);

        $this->assertDatabaseHas('login_attempts', [
            'user_id' => $user->id,
            'method' => LoginAttempt::Password,
            'is_success' => false,
        ]);
    }

    public function test_login_does_not_record_failed_attempt_when_disabled(): void
    {
        config(['neev.record_failed_login_attempts' => false]);

        $user = $this->createUser();

        $this->postJson('/neev/login', [
            'email' => $user->email->email,
            'password' => 'wrong-password',
        ]);

        $this->assertDatabaseMissing('login_attempts', [
            'user_id' => $user->id,
            'is_success' => false,
        ]);
    }

    public function test_successful_login_creates_login_attempt(): void
    {
        $user = $this->createUser();

        $this->postJson('/neev/login', [
            'email' => $user->email->email,
            'password' => 'password',
        ]);

        $this->assertDatabaseHas('login_attempts', [
            'user_id' => $user->id,
            'method' => LoginAttempt::Password,
            'is_success' => true,
        ]);
    }

    public function test_successful_login_creates_access_token(): void
    {
        $user = $this->createUser();

        $response = $this->postJson('/neev/login', [
            'email' => $user->email->email,
            'password' => 'password',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('access_tokens', [
            'user_id' => $user->id,
            'token_type' => 'login',
        ]);
    }

    public function test_mfa_user_gets_mfa_token_and_preferred_mfa_in_response(): void
    {
        $this->enableMFA();

        $user = $this->createUser();
        $user->multiFactorAuths()->create([
            'method' => 'authenticator',
            'preferred' => true,
            'secret' => 'testsecret',
        ]);

        $response = $this->postJson('/neev/login', [
            'email' => $user->email->email,
            'password' => 'password',
        ]);

        $response->assertOk();
        $response->assertJson(['preferred_mfa' => 'authenticator']);

        // The token should be stored as mfa_token type
        $this->assertDatabaseHas('access_tokens', [
            'user_id' => $user->id,
            'token_type' => 'mfa_token',
        ]);
    }

    public function test_non_mfa_user_gets_null_preferred_mfa(): void
    {
        $user = $this->createUser();

        $response = $this->postJson('/neev/login', [
            'email' => $user->email->email,
            'password' => 'password',
        ]);

        $response->assertOk();
        $response->assertJson(['preferred_mfa' => null]);
    }

    // -----------------------------------------------------------------
    // Login with username
    // -----------------------------------------------------------------

    public function test_login_via_username(): void
    {
        $this->enableUsernameSupport();

        $user = $this->createUser(['username' => 'testloginuser']);

        $response = $this->postJson('/neev/login', [
            'email' => 'testloginuser', // Using username instead of email
            'password' => 'password',
        ]);

        $response->assertOk();
        $response->assertJson(['status' => 'Success']);
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_login_via_username_with_wrong_password(): void
    {
        $this->enableUsernameSupport();

        $user = $this->createUser(['username' => 'testuser2']);

        $response = $this->postJson('/neev/login', [
            'email' => 'testuser2',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401);
    }

    public function test_login_via_nonexistent_username(): void
    {
        $this->enableUsernameSupport();

        $response = $this->postJson('/neev/login', [
            'email' => 'nonexistentuser',
            'password' => 'password',
        ]);

        $response->assertStatus(401);
    }
}
