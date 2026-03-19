<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Rules\PasswordHistory;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    protected function setUp(): void
    {
        parent::setUp();

        // Simplify password rules for reset tests
        config(['neev.password' => ['required', 'confirmed']]);
    }

    // -----------------------------------------------------------------
    // POST /neev/forgotPassword — send password reset link
    // -----------------------------------------------------------------

    public function test_forgot_password_sends_reset_link(): void
    {
        Mail::fake();

        $user = User::factory()->create();

        $response = $this->postJson('/neev/forgotPassword', [
            'email' => $user->email,
        ]);

        $response->assertOk();
        $response->assertJson([
            'message' => 'Password reset link has been sent to your email.',
        ]);
    }

    public function test_forgot_password_returns_error_for_non_existent_email(): void
    {
        $response = $this->postJson('/neev/forgotPassword', [
            'email' => 'nobody@example.com',
        ]);

        $response->assertStatus(404);
    }

    public function test_forgot_password_returns_error_for_unverified_user(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->postJson('/neev/forgotPassword', [
            'email' => $user->email,
        ]);

        $response->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // POST /neev/resetPassword — reset password via signed URL
    // -----------------------------------------------------------------

    public function test_successful_password_reset_with_valid_signed_url(): void
    {
        $user = User::factory()->create();

        $signedUrl = URL::temporarySignedRoute(
            'neev.resetPassword',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => hash('sha256', $user->email)]
        );

        $query = parse_url($signedUrl, PHP_URL_QUERY);

        $response = $this->postJson('/neev/resetPassword?' . $query, [
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertOk();
        $response->assertJson([
            'message' => 'Password has been updated.',
        ]);

        // Verify new password works
        $user->refresh();
        $this->assertTrue(Hash::check('newpassword123', $user->getRawOriginal('password')));
    }

    public function test_reset_password_rejects_invalid_signature(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/neev/resetPassword?id=' . $user->id . '&signature=invalidsig', [
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'Invalid or expired reset link.',
        ]);
    }

    public function test_reset_password_returns_validation_error_for_missing_password(): void
    {
        $user = User::factory()->create();

        $signedUrl = URL::temporarySignedRoute(
            'neev.resetPassword',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => hash('sha256', $user->email)]
        );

        $query = parse_url($signedUrl, PHP_URL_QUERY);

        $response = $this->postJson('/neev/resetPassword?' . $query, []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['password']);
    }

    public function test_reset_password_returns_validation_error_for_unconfirmed_password(): void
    {
        $user = User::factory()->create();

        $signedUrl = URL::temporarySignedRoute(
            'neev.resetPassword',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => hash('sha256', $user->email)]
        );

        $query = parse_url($signedUrl, PHP_URL_QUERY);

        $response = $this->postJson('/neev/resetPassword?' . $query, [
            'password' => 'newpassword123',
            'password_confirmation' => 'differentpassword',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['password']);
    }

    public function test_reset_password_populates_password_history(): void
    {
        $user = User::factory()->create(['password' => 'original-password']);
        $originalHash = $user->getRawOriginal('password');

        $signedUrl = URL::temporarySignedRoute(
            'neev.resetPassword',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => hash('sha256', $user->email)]
        );

        $query = parse_url($signedUrl, PHP_URL_QUERY);

        $response = $this->postJson('/neev/resetPassword?' . $query, [
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertOk();

        $user->refresh();
        $this->assertNotNull($user->password_history);
        $this->assertCount(1, $user->password_history);
        $this->assertSame($originalHash, $user->password_history[0]);
    }

    public function test_reset_password_rejects_reused_password(): void
    {
        config(['neev.password' => ['required', 'confirmed', PasswordHistory::notReused(3)]]);

        $user = User::factory()->create(['password' => 'original-password']);

        $signedUrl = URL::temporarySignedRoute(
            'neev.resetPassword',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => hash('sha256', $user->email)]
        );

        $query = parse_url($signedUrl, PHP_URL_QUERY);

        $response = $this->postJson('/neev/resetPassword?' . $query, [
            'password' => 'original-password',
            'password_confirmation' => 'original-password',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['password']);
    }
}
