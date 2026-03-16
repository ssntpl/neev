<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Ssntpl\Neev\Models\User;
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

        // Build a signed URL for the resetPassword endpoint.
        // We construct it the same way Laravel's signedRoute does internally.
        $parameters = [
            'id' => $user->id,
            'purpose' => 'password-reset',
            'expires' => now()->addMinutes(60)->getTimestamp(),
        ];
        ksort($parameters);

        $baseUrl = URL::to('/neev/resetPassword');
        $urlWithParams = $baseUrl . '?' . http_build_query($parameters);

        // Get the signing key via the URL generator's keyResolver
        $urlGenerator = app(\Illuminate\Routing\UrlGenerator::class);
        $keyResolverProp = new \ReflectionProperty($urlGenerator, 'keyResolver');
        $keyResolverProp->setAccessible(true);
        $keyResolver = $keyResolverProp->getValue($urlGenerator);
        $key = call_user_func($keyResolver);
        if (is_array($key)) {
            $key = $key[0];
        }

        $signature = hash_hmac('sha256', $urlWithParams, $key);
        $signedUrl = $urlWithParams . '&signature=' . $signature;

        $response = $this->postJson($signedUrl, [
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
            'mail.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'purpose' => 'password-reset']
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
            'mail.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'purpose' => 'password-reset']
        );

        $query = parse_url($signedUrl, PHP_URL_QUERY);

        $response = $this->postJson('/neev/resetPassword?' . $query, [
            'password' => 'newpassword123',
            'password_confirmation' => 'differentpassword',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['password']);
    }
}
