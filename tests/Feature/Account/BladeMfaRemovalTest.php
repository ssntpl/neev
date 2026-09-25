<?php

namespace Ssntpl\Neev\Tests\Feature\Account;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Ssntpl\Neev\Database\Factories\MultiFactorAuthFactory;
use Ssntpl\Neev\Mail\EmailOTP;
use Ssntpl\Neev\Models\LoginAttempt;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

/**
 * Removing a factor through the Blade kit.
 *
 * The kit's views are app-owned once ejected, so a server-side rule the
 * shipped form cannot answer is a dead control: the page renders, the button
 * posts, and the user gets a validation error with no field to fill. These
 * pin the form and the rule together.
 */
class BladeMfaRemovalTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    private const PASSWORD = 'Password123!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableMFA();
    }

    private function userWithAuthenticator(array $state = []): User
    {
        $user = User::factory()->create($state);

        MultiFactorAuthFactory::new()->create([
            'user_id' => $user->id,
            'method' => 'authenticator',
            'preferred' => true,
        ]);

        return $user;
    }

    /**
     * Act as a session that has answered its MFA challenge. NeevMiddleware
     * reads `attempt_id` from the session and turns an enrolled account back
     * to the challenge without it.
     */
    private function signedIn(User $user): self
    {
        $attempt = $user->loginAttempts()->create([
            'method' => LoginAttempt::Password,
            'multi_factor_method' => 'authenticator',
            'is_success' => true,
        ]);

        $this->actingAs($user)->withSession(['attempt_id' => $attempt->id]);

        return $this;
    }

    public function test_the_security_page_offers_a_field_to_confirm_removal_with(): void
    {
        $user = $this->userWithAuthenticator(['password' => self::PASSWORD]);

        $this->signedIn($user)
            ->get(route('account.security'))
            ->assertOk()
            ->assertSee('name="action" value="delete"', false)
            ->assertSee('name="password"', false);
    }

    /** An account with no password is offered the code, and a way to get one. */
    public function test_the_security_page_offers_a_code_to_an_account_without_a_password(): void
    {
        $user = $this->userWithAuthenticator(['password' => null]);

        $this->signedIn($user)
            ->get(route('account.security'))
            ->assertOk()
            ->assertSee('name="otp"', false)
            ->assertSee(route('account.confirmation'), false);
    }

    public function test_removal_goes_through_with_the_password(): void
    {
        $user = $this->userWithAuthenticator(['password' => self::PASSWORD]);

        $this->signedIn($user)
            ->post(route('multi.auth'), [
                'auth_method' => 'authenticator',
                'action' => 'delete',
                'password' => self::PASSWORD,
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertNull($user->fresh()->multiFactorAuth('authenticator'));
    }

    public function test_removal_without_the_password_is_refused(): void
    {
        $user = $this->userWithAuthenticator(['password' => self::PASSWORD]);

        $this->signedIn($user)
            ->post(route('multi.auth'), ['auth_method' => 'authenticator', 'action' => 'delete'])
            ->assertSessionHasErrors('password');

        $this->signedIn($user)
            ->post(route('multi.auth'), [
                'auth_method' => 'authenticator',
                'action' => 'delete',
                'password' => 'not-the-password',
            ])
            ->assertSessionHasErrors('password');

        $this->assertNotNull($user->fresh()->multiFactorAuth('authenticator'));
    }

    public function test_an_account_without_a_password_removes_a_factor_with_a_code(): void
    {
        Mail::fake();

        $user = $this->userWithAuthenticator(['password' => null]);

        // Exactly as the stub asks for it: fetch with a JSON Accept header, so
        // the redirect that would reset the dialog never happens.
        $this->signedIn($user)
            ->postJson(route('account.confirmation'))
            ->assertOk();

        $otp = null;
        Mail::assertSent(EmailOTP::class, function (EmailOTP $mail) use (&$otp) {
            $otp = $mail->otp;

            return true;
        });
        $this->assertNotNull($otp);

        $this->signedIn($user)
            ->post(route('multi.auth'), [
                'auth_method' => 'authenticator',
                'action' => 'delete',
                'otp' => $otp,
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull($user->fresh()->multiFactorAuth('authenticator'));
    }

    // -----------------------------------------------------------------
    // Enrolling another factor, and minting recovery codes
    // -----------------------------------------------------------------

    public function test_adding_a_second_factor_is_confirmed(): void
    {
        $user = $this->userWithAuthenticator(['password' => self::PASSWORD]);

        $this->signedIn($user)
            ->post(route('multi.auth'), ['auth_method' => 'email'])
            ->assertSessionHasErrors('password');

        $this->assertNull($user->fresh()->multiFactorAuth('email'));
    }

    /** Onboarding is untouched: the first factor asks for nothing. */
    public function test_the_first_factor_is_not_confirmed(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        $this->actingAs($user)
            ->post(route('multi.auth'), ['auth_method' => 'authenticator'])
            ->assertSessionHasNoErrors();
    }

    /** The page renders the field the confirmed Add now needs. */
    public function test_the_security_page_offers_a_field_to_confirm_enrolment_with(): void
    {
        $user = $this->userWithAuthenticator(['password' => self::PASSWORD]);

        $this->signedIn($user)
            ->get(route('account.security'))
            ->assertOk()
            ->assertSee('x-ref="addForm"', false);
    }

    /**
     * Reading a page must not mint credentials. This used to generate a set
     * whenever the account held none, so a stolen session could open the page
     * and read a complete second factor off it.
     */
    public function test_opening_the_recovery_codes_page_does_not_mint_any(): void
    {
        $user = $this->userWithAuthenticator(['password' => self::PASSWORD]);

        $this->signedIn($user)
            ->get(route('recovery.codes'))
            ->assertOk();

        $this->assertCount(0, $user->fresh()->recoveryCodes);
    }

    public function test_minting_recovery_codes_is_confirmed(): void
    {
        $user = $this->userWithAuthenticator(['password' => self::PASSWORD]);

        $this->signedIn($user)
            ->post(route('recovery.generate'))
            ->assertSessionHasErrors('password');

        $this->assertCount(0, $user->fresh()->recoveryCodes);

        $this->signedIn($user)
            ->post(route('recovery.generate'), ['password' => self::PASSWORD])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('recovery.codes'));

        $this->assertGreaterThan(0, $user->fresh()->recoveryCodes->count());
    }

    /** The plaintext reaches the page once, on the redirect that made them. */
    public function test_the_codes_are_shown_once_after_they_are_made(): void
    {
        $user = $this->userWithAuthenticator(['password' => self::PASSWORD]);

        $attempt = $user->loginAttempts()->create([
            'method' => LoginAttempt::Password,
            'multi_factor_method' => 'authenticator',
            'is_success' => true,
        ]);

        $this->actingAs($user)->withSession(['attempt_id' => $attempt->id]);

        // The redirect carries them, so the page that follows shows them.
        $this->post(route('recovery.generate'), ['password' => self::PASSWORD])
            ->assertRedirect(route('recovery.codes'));

        // Pinned on a code that was actually returned, not on the page title
        // — "Multi-factor Recovery Codes" renders in every state.
        $this->followingRedirects()
            ->post(route('recovery.generate'), ['password' => self::PASSWORD])
            ->assertOk()
            ->assertViewHas('codes', fn ($codes) => count($codes) === config('neev.recovery_codes'))
            ->assertSee($user->fresh()->recoveryCodes->count() . ' ', false);

        // The next visit has nothing to show: only the hashes are kept.
        $this->get(route('recovery.codes'))
            ->assertOk()
            ->assertViewHas('codes', []);
    }

    /** The dialogs carry the field the server now asks for, not just a form. */
    public function test_the_add_and_generate_dialogs_carry_a_confirmation_field(): void
    {
        $user = $this->userWithAuthenticator(['password' => self::PASSWORD]);

        $this->signedIn($user)
            ->get(route('account.security'))
            ->assertOk()
            ->assertSee('x-ref="addForm"', false)
            ->assertSee('name="password"', false);

        $this->signedIn($user)
            ->get(route('recovery.codes'))
            ->assertOk()
            ->assertSee('x-ref="generateForm"', false)
            ->assertSee('name="password"', false);
    }

    /**
     * Re-issuing a setup hands back the secret, so Edit is confirmed too —
     * and it used to bare-submit into the newly gated add branch, which left
     * a pending setup impossible to finish from the shipped kit.
     */
    public function test_re_issuing_a_setup_is_confirmed(): void
    {
        $user = $this->userWithAuthenticator(['password' => self::PASSWORD]);

        $this->signedIn($user)
            ->get(route('account.security'))
            ->assertOk()
            ->assertSee('x-ref="editForm"', false);

        $this->signedIn($user)
            ->post(route('multi.auth'), ['auth_method' => 'authenticator'])
            ->assertSessionHasErrors('password');

        $this->signedIn($user)
            ->post(route('multi.auth'), ['auth_method' => 'authenticator', 'password' => self::PASSWORD])
            ->assertSessionHasNoErrors();
    }

    /** An account with no password confirms enrolment with a mailed code. */
    public function test_a_passwordless_account_adds_a_factor_with_a_code(): void
    {
        Mail::fake();

        $user = $this->userWithAuthenticator(['password' => null]);

        $this->signedIn($user)
            ->postJson(route('account.confirmation'))
            ->assertOk();

        $otp = null;
        Mail::assertSent(EmailOTP::class, function (EmailOTP $mail) use (&$otp) {
            $otp = $mail->otp;

            return true;
        });

        $this->signedIn($user)
            ->post(route('multi.auth'), ['auth_method' => 'email', 'otp' => $otp])
            ->assertSessionHasNoErrors();
    }

    /** And mints recovery codes with one. */
    public function test_a_passwordless_account_mints_recovery_codes_with_a_code(): void
    {
        Mail::fake();

        $user = $this->userWithAuthenticator(['password' => null]);

        $this->signedIn($user)->postJson(route('account.confirmation'))->assertOk();

        $otp = null;
        Mail::assertSent(EmailOTP::class, function (EmailOTP $mail) use (&$otp) {
            $otp = $mail->otp;

            return true;
        });

        $this->signedIn($user)
            ->post(route('recovery.generate'), ['otp' => $otp])
            ->assertSessionHasNoErrors();

        $this->assertGreaterThan(0, $user->fresh()->recoveryCodes->count());
    }

    /**
     * An enrolment that cannot happen is refused before the confirmation is
     * checked, so the single-use code is still good for the next action.
     */
    public function test_a_refused_enrolment_does_not_spend_the_confirmation_code(): void
    {
        Mail::fake();

        $user = $this->userWithAuthenticator(['password' => null, 'email_verified_at' => null]);

        $this->signedIn($user)->postJson(route('account.confirmation'))->assertOk();

        $otp = null;
        Mail::assertSent(EmailOTP::class, function (EmailOTP $mail) use (&$otp) {
            $otp = $mail->otp;

            return true;
        });

        $this->signedIn($user)
            ->post(route('multi.auth'), ['auth_method' => 'email', 'otp' => $otp])
            ->assertSessionHasErrors(['message' => 'Email is not verified.']);

        $this->signedIn($user)
            ->post(route('recovery.generate'), ['otp' => $otp])
            ->assertSessionHasNoErrors();

        $this->assertGreaterThan(0, $user->fresh()->recoveryCodes->count());
    }
}
