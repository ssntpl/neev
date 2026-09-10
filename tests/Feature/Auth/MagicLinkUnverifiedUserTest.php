<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Ssntpl\Neev\Events\EmailVerified;
use Ssntpl\Neev\Mail\LoginUsingLink;
use Ssntpl\Neev\Models\MagicLinkToken;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\MagicLink\MagicLinkManager;
use Ssntpl\Neev\Tests\TestCase;

/**
 * Magic links and unverified email addresses.
 *
 * An unverified address is not a reason to refuse a magic link. The link is
 * mailed to that address, so following it proves control of the inbox exactly
 * as the verification mail would — redemption therefore marks the address
 * verified and signs the user in.
 *
 * This is deliberate, and predates the stateful rewrite: gating sends on
 * verification would remove the very path an unverified user has to become
 * verified, leaving anyone who never received their verification mail with no
 * way in at all.
 */
class MagicLinkUnverifiedUserTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): MagicLinkManager
    {
        return app(MagicLinkManager::class);
    }

    private function unverifiedUser(): User
    {
        return User::factory()->create(['email_verified_at' => null]);
    }

    // -----------------------------------------------------------------
    // Sending
    // -----------------------------------------------------------------

    public function test_api_send_to_an_unverified_user_is_allowed(): void
    {
        Mail::fake();

        $user = $this->unverifiedUser();

        $this->postJson('/neev/sendLoginLink', ['email' => $user->email])
            ->assertOk()
            ->assertJson(['message' => 'Login link has been sent.']);

        Mail::assertSent(LoginUsingLink::class, fn (LoginUsingLink $mail) => $mail->hasTo($user->email));
        $this->assertDatabaseCount('magic_link_tokens', 1);
    }

    public function test_blade_send_to_an_unverified_user_is_allowed(): void
    {
        Mail::fake();

        $user = $this->unverifiedUser();

        $this->post('/login/link', ['email' => $user->email])
            ->assertSessionHas('status', 'Login link has been sent.');

        Mail::assertSent(LoginUsingLink::class);
        $this->assertDatabaseCount('magic_link_tokens', 1);
    }

    public function test_generate_for_an_unverified_user_does_not_throw(): void
    {
        $user = $this->unverifiedUser();

        $link = $this->manager()->forWeb($user);

        $this->assertNotEmpty($link['token']);
        $this->assertNotNull(MagicLinkToken::findByToken($link['token']));
    }

    // -----------------------------------------------------------------
    // Redeeming verifies the address
    // -----------------------------------------------------------------

    public function test_redeeming_marks_the_address_verified_and_signs_in(): void
    {
        config(['neev.magic_link.require_confirmation' => true]);

        $user = $this->unverifiedUser();
        $link = $this->manager()->forWeb($user);

        $this->assertFalse($user->hasVerifiedEmail());

        $this->post('/login-link/verify', ['token' => $link['token']])
            ->assertRedirect();

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_redeeming_fires_email_verified(): void
    {
        config(['neev.magic_link.require_confirmation' => true]);
        Event::fake([EmailVerified::class]);

        $user = $this->unverifiedUser();
        $link = $this->manager()->forWeb($user);

        $this->post('/login-link/verify', ['token' => $link['token']]);

        Event::assertDispatched(EmailVerified::class);
    }

    public function test_api_redemption_reports_the_address_as_verified(): void
    {
        config(['neev.magic_link.require_confirmation' => true]);

        $user = $this->unverifiedUser();
        $link = $this->manager()->forWeb($user);

        $this->postJson('/neev/loginUsingLink', ['token' => $link['token']])
            ->assertOk()
            ->assertJson([
                'auth_state' => 'authenticated',
                'email_verified' => true,
            ]);

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    /**
     * An already-verified address must not be re-stamped, or the verification
     * timestamp would move on every login.
     */
    public function test_redeeming_does_not_restamp_a_verified_address(): void
    {
        config(['neev.magic_link.require_confirmation' => true]);

        $user = User::factory()->create();
        $verifiedAt = $user->email_verified_at;

        $link = $this->manager()->forWeb($user);
        $this->post('/login-link/verify', ['token' => $link['token']]);

        $this->assertEquals($verifiedAt, $user->fresh()->email_verified_at);
    }

    // -----------------------------------------------------------------
    // The token still has to be good
    // -----------------------------------------------------------------

    public function test_an_unverified_user_still_cannot_redeem_a_bad_token(): void
    {
        $this->postJson('/neev/loginUsingLink', ['token' => 'not-a-real-token'])
            ->assertStatus(403);

        $this->assertGuest();
    }

    public function test_an_inactive_unverified_user_is_still_refused(): void
    {
        config(['neev.magic_link.require_confirmation' => true]);

        $user = $this->unverifiedUser();
        $link = $this->manager()->forWeb($user);

        $user->forceFill(['active' => false])->save();

        $this->post('/login-link/verify', ['token' => $link['token']]);

        $this->assertGuest();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }
}
