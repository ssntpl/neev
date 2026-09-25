<?php

namespace Ssntpl\Neev\Tests\Feature\Account;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Ssntpl\Neev\Enums\OtpPurpose;
use Ssntpl\Neev\Mail\EmailOTP;
use Ssntpl\Neev\Mail\VerifyUserEmail;
use Ssntpl\Neev\Models\OTP;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;

/**
 * Accounts created through OAuth, a magic link or a passkey hold no password.
 * Every flow that used to prove ownership with one has to cope with that:
 * Hash::check() against a null hash can never succeed, so demanding a password
 * would lock those accounts out of the action for good.
 */
class PasswordlessAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['neev.password' => ['required', 'confirmed']]);
    }

    /** An account with no password, as OAuth registration leaves one. */
    protected function passwordlessUser(): User
    {
        return User::factory()->create(['password' => null]);
    }

    protected function apiToken(User $user): string
    {
        return $user->createLoginToken(60)->plainTextToken;
    }

    /** The code POST /neev/email/send would have mailed. */
    protected function issueConfirmationCode(User $user, string $code = '123456'): string
    {
        OTP::updateOrCreate(
            ['owner_id' => $user->id, 'owner_type' => $user->getMorphClass(), 'purpose' => OtpPurpose::Confirmation],
            ['otp' => $code, 'attempts' => 0, 'expires_at' => now()->addMinutes(15)],
        );

        return $code;
    }

    // -----------------------------------------------------------------
    // Deleting the account — web
    // -----------------------------------------------------------------

    public function test_passwordless_account_is_deleted_with_the_emailed_code(): void
    {
        $user = $this->passwordlessUser();
        $otp = $this->issueConfirmationCode($user);

        $this->actingAs($user)
            ->delete(route('account.delete'), ['otp' => $otp])
            ->assertRedirect();

        $this->assertNull(User::model()->find($user->id));
    }

    public function test_passwordless_deletion_needs_a_code(): void
    {
        $user = $this->passwordlessUser();

        $this->actingAs($user)
            ->from(route('account.security'))
            ->delete(route('account.delete'))
            ->assertSessionHasErrors('otp');

        $this->assertNotNull(User::model()->find($user->id));
    }

    public function test_passwordless_deletion_is_refused_with_a_wrong_code(): void
    {
        $user = $this->passwordlessUser();
        $this->issueConfirmationCode($user);

        $this->actingAs($user)
            ->from(route('account.security'))
            ->delete(route('account.delete'), ['otp' => '000000'])
            ->assertRedirect(route('account.security'))
            // Keyed by the field that was asked for, so the input carries it.
            ->assertSessionHasErrors('otp');

        $this->assertNotNull(User::model()->find($user->id));
    }

    public function test_account_with_a_password_still_has_to_supply_it_to_delete(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        $this->actingAs($user)
            ->from(route('account.security'))
            ->delete(route('account.delete'), ['password' => 'wrong-password'])
            ->assertRedirect(route('account.security'))
            ->assertSessionHasErrors('password');

        $this->assertNotNull(User::model()->find($user->id));
    }

    public function test_account_with_a_password_is_deleted_when_it_matches(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        $this->actingAs($user)
            ->delete(route('account.delete'), ['password' => 'password'])
            ->assertRedirect();

        $this->assertNull(User::model()->find($user->id));
    }

    // -----------------------------------------------------------------
    // Deleting the account — API
    // -----------------------------------------------------------------

    public function test_api_deletes_a_passwordless_account_with_the_emailed_code(): void
    {
        $user = $this->passwordlessUser();
        $otp = $this->issueConfirmationCode($user);

        $this->withHeader('Authorization', 'Bearer ' . $this->apiToken($user))
            ->deleteJson('/neev/users', ['otp' => $otp])
            ->assertOk()
            ->assertJsonPath('message', 'Account has been deleted.');

        $this->assertNull(User::model()->find($user->id));
    }

    public function test_api_passwordless_deletion_needs_a_code(): void
    {
        $user = $this->passwordlessUser();

        $this->withHeader('Authorization', 'Bearer ' . $this->apiToken($user))
            ->deleteJson('/neev/users')
            ->assertStatus(422);

        $this->assertNotNull(User::model()->find($user->id));
    }

    /**
     * The column records when the address was proven, not the last action
     * confirmed with a code — so confirming leaves it where it is.
     */
    public function test_confirming_an_action_does_not_restamp_email_verified_at(): void
    {
        $verifiedAt = now()->subDays(30);
        $user = $this->passwordlessUser();
        $user->forceFill(['email_verified_at' => $verifiedAt])->save();

        $otp = $this->issueConfirmationCode($user);

        $this->withHeader('Authorization', 'Bearer ' . $this->apiToken($user))
            ->postJson('/neev/logoutAll', ['otp' => $otp])
            ->assertOk();

        $this->assertSame(
            $verifiedAt->toDateTimeString(),
            $user->fresh()->email_verified_at->toDateTimeString(),
        );
    }

    /**
     * A correct guess spends the code. Nothing binds a code to the action it
     * was read for, so single use is what stops one overheard code
     * confirming every gated action until it expires.
     */
    public function test_a_confirmation_code_works_only_once(): void
    {
        $user = $this->passwordlessUser();
        $otp = $this->issueConfirmationCode($user);

        $this->withHeader('Authorization', 'Bearer ' . $this->apiToken($user))
            ->postJson('/neev/logoutAll', ['otp' => $otp])
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer ' . $this->apiToken($user))
            ->deleteJson('/neev/users', ['otp' => $otp])
            ->assertStatus(403);

        $this->assertNotNull(User::model()->find($user->id));
        $this->assertDatabaseMissing('otp', [
            'owner_id' => $user->id,
            'owner_type' => $user->getMorphClass(),
        ]);
    }

    /**
     * Every passwordless account is already verified, and /email/send
     * refuses a verified address — so confirmation codes come from their
     * own endpoint, which serves exactly the accounts that need one.
     */
    public function test_a_passwordless_account_can_request_a_confirmation_code(): void
    {
        Mail::fake();

        $user = $this->passwordlessUser();
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->withHeader('Authorization', 'Bearer ' . $this->apiToken($user))
            ->postJson('/neev/confirmation/otp')
            ->assertOk();

        $this->assertDatabaseHas('otp', [
            'owner_id' => $user->id,
            'owner_type' => $user->getMorphClass(),
            'purpose' => OtpPurpose::Confirmation->value,
        ]);

        // Only the code — not the verification mail, which carries a signed
        // link that signs the reader in.
        Mail::assertSent(EmailOTP::class);
        Mail::assertNotSent(VerifyUserEmail::class);
    }

    /**
     * End to end: the code the endpoint mails is the one the action takes.
     */
    public function test_the_emailed_code_deletes_the_account(): void
    {
        Mail::fake();

        $user = $this->passwordlessUser();

        $this->withHeader('Authorization', 'Bearer ' . $this->apiToken($user))
            ->postJson('/neev/confirmation/otp')
            ->assertOk();

        $otp = null;
        Mail::assertSent(EmailOTP::class, function ($mail) use (&$otp) {
            $otp = $mail->otp;
            return true;
        });
        $this->assertNotNull($otp);

        $this->withHeader('Authorization', 'Bearer ' . $this->apiToken($user))
            ->deleteJson('/neev/users', ['otp' => (string) $otp])
            ->assertOk();

        $this->assertNull(User::model()->find($user->id));
    }

    public function test_api_still_requires_a_password_when_the_account_has_one(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        $this->withHeader('Authorization', 'Bearer ' . $this->apiToken($user))
            ->deleteJson('/neev/users', [])
            ->assertStatus(422);

        $this->withHeader('Authorization', 'Bearer ' . $this->apiToken($user))
            ->deleteJson('/neev/users', ['password' => 'wrong-password'])
            ->assertStatus(403);

        $this->assertNotNull(User::model()->find($user->id));
    }

    // -----------------------------------------------------------------
    // Changing the password
    // -----------------------------------------------------------------

    public function test_web_change_password_tells_a_passwordless_account_to_use_the_emailed_link(): void
    {
        $user = $this->passwordlessUser();

        $this->actingAs($user)
            ->from(route('account.security'))
            ->post(route('password.change'), [
                'current_password' => 'anything',
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ])
            ->assertRedirect(route('account.security'))
            ->assertSessionHasErrors('message');

        $this->assertNull(User::model()->find($user->id)->password);
    }

    public function test_api_change_password_tells_a_passwordless_account_to_use_the_emailed_link(): void
    {
        $user = $this->passwordlessUser();

        $this->withHeader('Authorization', 'Bearer ' . $this->apiToken($user))
            ->putJson('/neev/changePassword', [
                'current_password' => 'anything',
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Your account has no password yet. Use the emailed link to set one.');
    }

    // -----------------------------------------------------------------
    // Changing the email address
    // -----------------------------------------------------------------

    public function test_web_email_change_is_refused_until_a_password_is_set(): void
    {
        Mail::fake();

        $user = $this->passwordlessUser();

        $this->actingAs($user)
            ->put(route('email.update'), [
                'email' => 'new@example.com',
                'password' => 'anything',
            ])
            ->assertRedirect(route('account.security'))
            ->assertSessionHasErrors('message');

        Mail::assertNothingSent();
    }

    public function test_api_email_change_is_refused_until_a_password_is_set(): void
    {
        Mail::fake();

        $user = $this->passwordlessUser();

        $this->withHeader('Authorization', 'Bearer ' . $this->apiToken($user))
            ->postJson('/neev/email/change', [
                'email' => 'new@example.com',
                'password' => 'anything',
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Set a password on your account before changing your email address.');

        Mail::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // The pages themselves
    // -----------------------------------------------------------------

    public function test_change_email_page_points_a_passwordless_account_at_the_security_page(): void
    {
        $this->actingAs($this->passwordlessUser())
            ->get(route('email.change'))
            ->assertOk()
            ->assertSee(route('account.security'))
            ->assertDontSee('name="email"', false);
    }

    public function test_change_email_page_shows_the_form_when_the_account_has_a_password(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        $this->actingAs($user)
            ->get(route('email.change'))
            ->assertOk()
            ->assertSee('name="email"', false);
    }

    public function test_delete_dialog_asks_a_passwordless_account_for_a_code(): void
    {
        $user = $this->passwordlessUser();

        $this->actingAs($user)
            ->get(route('account.security'))
            ->assertOk()
            ->assertSee('name="otp"', false)
            ->assertSee(route('account.confirmation'), false)
            ->assertDontSee('name="password"', false);
    }

    public function test_delete_dialog_asks_an_account_with_a_password_for_it(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        $this->actingAs($user)
            ->get(route('account.security'))
            ->assertOk()
            ->assertSee('name="password"', false)
            ->assertDontSee('name="otp"', false);
    }

    public function test_sessions_page_asks_a_passwordless_account_for_a_code(): void
    {
        // The page lists rows from the session store, which the package does
        // not ship a migration for — the host application owns it.
        Schema::create('sessions', function ($table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        $user = $this->passwordlessUser();

        $this->actingAs($user)
            ->get(route('account.sessions'))
            ->assertOk()
            ->assertSee('name="otp"', false)
            ->assertSee(route('account.confirmation'), false);
    }

    /**
     * The dialogs request the code with fetch and stay open. A redirect would
     * reload the page, reset the modal's x-data and close it before the code
     * could be typed into the field the copy points at.
     */
    public function test_the_web_send_answers_json_for_an_async_caller(): void
    {
        Mail::fake();

        $user = $this->passwordlessUser();

        $this->actingAs($user)
            ->postJson(route('account.confirmation'))
            ->assertOk()
            ->assertJsonPath('message', 'A confirmation code has been sent to your email address.');

        Mail::assertSent(EmailOTP::class);
    }

    public function test_the_web_send_still_redirects_for_a_form_post(): void
    {
        Mail::fake();

        $user = $this->passwordlessUser();

        $this->actingAs($user)
            ->post(route('account.confirmation'))
            ->assertRedirect()
            ->assertSessionHas('status');
    }

    public function test_the_web_flow_deletes_a_passwordless_account(): void
    {
        Mail::fake();

        $user = $this->passwordlessUser();

        $this->actingAs($user)
            ->post(route('account.confirmation'))
            ->assertRedirect();

        $otp = null;
        Mail::assertSent(EmailOTP::class, function ($mail) use (&$otp) {
            $otp = $mail->otp;
            return true;
        });

        $this->actingAs($user)
            ->delete(route('account.delete'), ['otp' => (string) $otp])
            ->assertRedirect();

        $this->assertNull(User::model()->find($user->id));
    }

    public function test_security_page_offers_to_set_a_password_when_there_is_none(): void
    {
        $this->actingAs($this->passwordlessUser())
            ->get(route('account.security'))
            ->assertOk()
            ->assertSee('Set Password')
            ->assertSee(route('password.reset.link'));
    }

    public function test_security_page_offers_to_change_the_password_when_there_is_one(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        $this->actingAs($user)
            ->get(route('account.security'))
            ->assertOk()
            ->assertSee('Change Password')
            // The reset link is offered here too, for anyone who has simply
            // forgotten the current one.
            ->assertSee(route('password.reset.link'));
    }
}
