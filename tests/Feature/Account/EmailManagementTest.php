<?php

namespace Ssntpl\Neev\Tests\Feature\Account;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Ssntpl\Neev\Models\Email;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class EmailManagementTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

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
    // POST /neev/emails — add email
    // -----------------------------------------------------------------

    public function test_add_new_email_to_account(): void
    {
        Mail::fake();

        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/emails', [
                'email' => 'newemail@example.com',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success');

        $this->assertDatabaseHas('emails', [
            'user_id' => $user->id,
            'email' => 'newemail@example.com',
        ]);
    }

    public function test_added_email_is_not_primary(): void
    {
        Mail::fake();

        [$user, $token] = $this->authenticatedUser();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/emails', [
                'email' => 'secondary@example.com',
            ]);

        $email = Email::where('email', 'secondary@example.com')->first();
        $this->assertNotNull($email);
        $this->assertFalse($email->is_primary);
    }

    public function test_cannot_add_duplicate_email(): void
    {
        Mail::fake();

        [$user, $token] = $this->authenticatedUser();

        // First, the user already has a primary email from the factory.
        $existingEmail = $user->email->email;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/emails', [
                'email' => $existingEmail,
            ]);

        // The controller returns Success with "Email already exist." message
        $response->assertOk()
            ->assertJsonPath('message', 'Email already exist.');

        // Should still only have one record of this email
        $this->assertEquals(1, Email::where('email', $existingEmail)->count());
    }

    public function test_cannot_add_email_belonging_to_another_user(): void
    {
        Mail::fake();

        [$user, $token] = $this->authenticatedUser();

        $otherUser = User::factory()->create();
        $otherEmail = $otherUser->email->email;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/emails', [
                'email' => $otherEmail,
            ]);

        // Controller checks if email exists globally and returns early
        $response->assertOk()
            ->assertJsonPath('message', 'Email already exist.');
    }

    // -----------------------------------------------------------------
    // DELETE /neev/emails — delete email
    // -----------------------------------------------------------------

    public function test_can_delete_non_primary_email(): void
    {
        [$user, $token] = $this->authenticatedUser();

        // Add a secondary email
        $secondaryEmail = $user->emails()->create([
            'email' => 'secondary@example.com',
            'is_primary' => false,
            'verified_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/emails', [
                'email' => 'secondary@example.com',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success');

        $this->assertDatabaseMissing('emails', [
            'id' => $secondaryEmail->id,
        ]);
    }

    public function test_cannot_delete_primary_email(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $primaryEmail = $user->email->email;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/emails', [
                'email' => $primaryEmail,
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('status', 'Failed')
            ->assertJsonPath('message', 'Cannot delete primary email.');

        // Primary email should still exist
        $this->assertDatabaseHas('emails', [
            'user_id' => $user->id,
            'email' => $primaryEmail,
            'is_primary' => true,
        ]);
    }

    public function test_cannot_delete_nonexistent_email(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/emails', [
                'email' => 'nonexistent@example.com',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('status', 'Failed');
    }

    // -----------------------------------------------------------------
    // PUT /neev/emails — set primary email
    // -----------------------------------------------------------------

    public function test_can_set_verified_email_as_primary(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $originalPrimary = $user->email->email;

        // Add a verified secondary email
        $user->emails()->create([
            'email' => 'newprimary@example.com',
            'is_primary' => false,
            'verified_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/emails', [
                'email' => 'newprimary@example.com',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success');

        // New email should be primary
        $this->assertTrue(
            Email::where('email', 'newprimary@example.com')->first()->is_primary
        );

        // Old email should no longer be primary
        $this->assertFalse(
            Email::where('email', $originalPrimary)->first()->is_primary
        );
    }

    public function test_cannot_set_unverified_email_as_primary(): void
    {
        [$user, $token] = $this->authenticatedUser();

        // Add an unverified secondary email
        $user->emails()->create([
            'email' => 'unverified@example.com',
            'is_primary' => false,
            'verified_at' => null,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/emails', [
                'email' => 'unverified@example.com',
            ]);

        // Controller returns a "not changed" response (not an error status)
        $response->assertOk()
            ->assertJsonPath('status', 'Failed');
    }

    public function test_setting_already_primary_email_returns_success(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $primaryEmail = $user->email->email;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/emails', [
                'email' => $primaryEmail,
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success');
    }

    // -----------------------------------------------------------------
    // POST /neev/email/update — update email address
    // -----------------------------------------------------------------

    public function test_email_update_changes_email_address(): void
    {
        Mail::fake();

        [$user, $token] = $this->authenticatedUser();

        $email = $user->email;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/email/update', [
                'email_id' => $email->id,
                'email' => 'updated@example.com',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonPath('message', 'Email has been updated.');

        $email->refresh();
        $this->assertEquals('updated@example.com', $email->email);
    }

    public function test_email_update_returns_error_for_invalid_email_id(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/email/update', [
                'email_id' => 99999,
                'email' => 'updated@example.com',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Failed');
    }

    public function test_email_update_rejects_other_users_email(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $otherUser = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/email/update', [
                'email_id' => $otherUser->email->id,
                'email' => 'hacked@example.com',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Failed');
    }
}
