<?php

namespace Ssntpl\Neev\Tests\Feature\Account;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    protected function setUp(): void
    {
        parent::setUp();

        // Simplify password rules for testing to avoid complex validation
        config(['neev.password' => ['required', 'confirmed']]);
    }

    /**
     * Create an authenticated user with a login token.
     *
     * @return array{0: \Ssntpl\Neev\Models\User, 1: string}
     */
    protected function authenticatedUser(): array
    {
        $user = User::factory()->create();
        $token = $user->createLoginToken(60);

        return [$user, $token->plainTextToken];
    }

    // -----------------------------------------------------------------
    // PUT /neev/changePassword — change password
    // -----------------------------------------------------------------

    public function test_can_change_password_with_valid_current_and_new_password(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/changePassword', [
                'current_password' => 'password',
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonPath('message', 'Password has been successfully updated.');
    }

    public function test_cannot_change_password_with_wrong_current_password(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/changePassword', [
                'current_password' => 'wrong-password',
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('status', 'Failed')
            ->assertJsonPath('message', 'Current Password is Wrong.');
    }

    public function test_new_password_is_saved_correctly(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/changePassword', [
                'current_password' => 'password',
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ]);

        // Reload user and check latest password
        $user->refresh();
        $latestPassword = $user->password;
        $this->assertTrue(Hash::check('brand-new-password', $latestPassword->password));
    }

    public function test_cannot_change_password_without_confirmation(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/changePassword', [
                'current_password' => 'password',
                'password' => 'new-password',
                // Missing password_confirmation
            ]);

        // Validation should fail due to 'confirmed' rule
        $response->assertStatus(500);
    }

    public function test_cannot_change_password_with_mismatched_confirmation(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/changePassword', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'different-password',
            ]);

        // Validation should fail due to 'confirmed' rule mismatch
        $response->assertStatus(500);
    }

    public function test_cannot_change_password_without_current_password(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/changePassword', [
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        // Validation requires current_password
        $response->assertStatus(500);
    }

    public function test_password_change_creates_new_password_record(): void
    {
        [$user, $token] = $this->authenticatedUser();

        // User factory creates 1 password record
        $initialCount = $user->passwords()->count();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/changePassword', [
                'current_password' => 'password',
                'password' => 'another-new-password',
                'password_confirmation' => 'another-new-password',
            ]);

        // A new password record should be created (not updating the old one)
        $this->assertEquals($initialCount + 1, $user->passwords()->count());
    }
}
