<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithMfaJwtToken;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;
    use WithMfaJwtToken;
    use WithNeevConfig;

    protected function authenticatedUser(): array
    {
        $user = User::factory()->create();
        $token = $user->createLoginToken(60);

        return [$user, $token->plainTextToken];
    }

    // -----------------------------------------------------------------
    // POST /neev/email/send — send mail verification link
    // -----------------------------------------------------------------

    public function test_send_verification_link_for_unverified_email(): void
    {
        Mail::fake();

        [$user, $token] = $this->authenticatedUser();

        // Make the user's email unverified
        $user->forceFill(['email_verified_at' => null])->save();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/email/send', [
                'email' => $user->email,
            ]);

        $response->assertOk();
    }

    public function test_send_verification_link_returns_error_for_already_verified_user(): void
    {
        [$user, $token] = $this->authenticatedUser();

        // Factory creates verified emails by default
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/email/send');

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Email already verified.');
    }

    public function test_send_verification_link_ignores_request_email_and_uses_authenticated_user(): void
    {
        Mail::fake();

        [$user, $token] = $this->authenticatedUser();

        $user->forceFill(['email_verified_at' => null])->save();

        // Even passing a different email, the endpoint should use the authenticated user's email
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/email/send', [
                'email' => 'someone-else@example.com',
            ]);

        $response->assertOk();
    }

    // -----------------------------------------------------------------
    // GET /neev/email/verify — verify email via signed URL
    // -----------------------------------------------------------------

    public function test_verify_email_with_valid_signed_url(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $user->forceFill(['email_verified_at' => null])->save();

        $signedUrl = URL::temporarySignedRoute(
            'mail.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => hash('sha256', $user->email)]
        );

        // Extract query parameters from the signed URL
        $query = parse_url($signedUrl, PHP_URL_QUERY);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/email/verify?' . $query);

        $response->assertOk()
            ->assertJsonPath('message', 'Email verification done.');

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_verify_email_rejects_invalid_signature(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $user->forceFill(['email_verified_at' => null])->save();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/email/verify?id=' . $user->id . '&signature=invalidsig');

        $response->assertStatus(403);
    }

    public function test_verify_already_verified_email_returns_success(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $signedUrl = URL::temporarySignedRoute(
            'mail.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => hash('sha256', $user->email)]
        );

        $query = parse_url($signedUrl, PHP_URL_QUERY);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/email/verify?' . $query);

        $response->assertOk()
            ->assertJsonPath('message', 'Email verification already done.');
    }
}
