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

    // -----------------------------------------------------------------
    // The link is the credential
    // -----------------------------------------------------------------

    /**
     * A verification link is usually opened in whichever browser the mail
     * client hands it to, which is rarely the one holding the session. The
     * signature is the credential, so no login is required to spend it.
     */
    public function test_verify_email_works_without_being_logged_in(): void
    {
        $user = User::factory()->unverified()->create();

        $query = parse_url(URL::temporarySignedRoute(
            'mail.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => hash('sha256', $user->email)]
        ), PHP_URL_QUERY);

        $this->getJson('/neev/email/verify?' . $query)
            ->assertOk()
            ->assertJsonPath('message', 'Email verification done.');

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
    }

    /**
     * Which session happens to be open is irrelevant — the link verifies the
     * account it was minted for, not whoever is currently signed in.
     */
    public function test_verify_email_verifies_the_link_owner_not_the_logged_in_user(): void
    {
        $target = User::factory()->unverified()->create();
        [$other, $otherToken] = $this->authenticatedUser();

        $query = parse_url(URL::temporarySignedRoute(
            'mail.verify',
            now()->addMinutes(60),
            ['id' => $target->id, 'hash' => hash('sha256', $target->email)]
        ), PHP_URL_QUERY);

        $this->withHeader('Authorization', 'Bearer ' . $otherToken)
            ->getJson('/neev/email/verify?' . $query)
            ->assertOk()
            ->assertJsonPath('message', 'Email verification done.');

        $this->assertNotNull($target->refresh()->email_verified_at);
    }

    /**
     * The hash is bound to the address the link was mailed to, so a link
     * minted before an address change cannot verify the new one.
     */
    public function test_verify_email_rejects_a_hash_that_no_longer_matches_the_address(): void
    {
        $user = User::factory()->unverified()->create();

        $query = parse_url(URL::temporarySignedRoute(
            'mail.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => hash('sha256', 'someone-else@example.com')]
        ), PHP_URL_QUERY);

        $this->getJson('/neev/email/verify?' . $query)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Invalid or expired verification link.');

        $this->assertNull($user->refresh()->email_verified_at);
    }

    public function test_verify_email_rejects_an_expired_link(): void
    {
        $user = User::factory()->unverified()->create();

        $query = parse_url(URL::temporarySignedRoute(
            'mail.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => hash('sha256', $user->email)]
        ), PHP_URL_QUERY);

        $this->travel(61)->minutes();

        $this->getJson('/neev/email/verify?' . $query)->assertStatus(403);

        $this->assertNull($user->refresh()->email_verified_at);
    }

    public function test_verify_email_rejects_an_unknown_user(): void
    {
        $query = parse_url(URL::temporarySignedRoute(
            'mail.verify',
            now()->addMinutes(60),
            ['id' => 999999, 'hash' => hash('sha256', 'ghost@example.com')]
        ), PHP_URL_QUERY);

        $this->getJson('/neev/email/verify?' . $query)->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // GET /email/verify/{id}/{hash} — the Blade kit page route
    // -----------------------------------------------------------------

    public function test_blade_verify_link_works_without_being_logged_in(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => hash('sha256', $user->email)]
        );

        $this->get($url)->assertRedirect(config('neev.home'));

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_blade_verify_link_is_not_an_error_on_a_second_click(): void
    {
        $user = User::factory()->create();

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => hash('sha256', $user->email)]
        );

        $this->get($url)
            ->assertRedirect(config('neev.home'))
            ->assertSessionHasNoErrors();
    }

    public function test_blade_verify_link_rejects_a_tampered_signature(): void
    {
        $user = User::factory()->unverified()->create();

        $this->get('/email/verify/' . $user->id . '/' . hash('sha256', $user->email) . '?signature=nope')
            ->assertRedirect(config('neev.home'))
            ->assertSessionHasErrors('message');

        $this->assertNull($user->refresh()->email_verified_at);
    }
}
