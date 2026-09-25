<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Ssntpl\Neev\Mail\EmailOTP;
use Ssntpl\Neev\Mail\VerifyUserEmail;
use Ssntpl\Neev\Models\OTP;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\AuthService;
use Ssntpl\Neev\Rules\PasswordHistory;
use Ssntpl\Neev\Tests\TestCase;

/**
 * The Blade kit's forgot-password flow: one email carries a link and a code,
 * and either resets the password.
 */
class WebPasswordResetCodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['neev.password' => ['required', 'confirmed']]);
    }

    private function requestResetCode(User $user): string
    {
        Mail::fake();

        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => $user->email])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status')
            ->assertSessionHas('password_reset_code_email', $user->email);

        $otp = null;
        Mail::assertSent(VerifyUserEmail::class, function (VerifyUserEmail $mail) use (&$otp) {
            $otp = $mail->otp;

            return $mail->purpose === 'Reset Password' && $mail->url !== null;
        });
        $this->assertNotNull($otp);

        return (string) $otp;
    }

    private function resetWithCode(User $user, string $otp, string $password = 'newpassword123')
    {
        return $this->from(route('password.request'))->post(route('user-password.update'), [
            'email' => $user->email,
            'otp' => $otp,
            'password' => $password,
            'password_confirmation' => $password,
        ]);
    }

    public function test_forgot_password_page_shows_no_code_field_before_sending(): void
    {
        $this->get(route('password.request'))
            ->assertOk()
            ->assertDontSee('name="otp"', false);
    }

    public function test_forgot_password_sends_link_and_code_and_shows_the_code_form(): void
    {
        $user = User::factory()->create();

        $this->requestResetCode($user);

        Mail::assertSentCount(1);
        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('name="otp"', false)
            ->assertSee($user->email);
    }

    public function test_code_form_survives_a_wrong_guess(): void
    {
        $user = User::factory()->create();
        $this->requestResetCode($user);

        $this->resetWithCode($user, '000000');

        $this->get(route('password.request'))->assertSee('name="otp"', false);
    }

    public function test_successful_reset_with_code(): void
    {
        $user = User::factory()->create();
        $otp = $this->requestResetCode($user);

        $this->resetWithCode($user, $otp)
            ->assertRedirect(route('login'))
            ->assertSessionHas('status')
            ->assertSessionMissing('password_reset_code_email');

        $this->assertTrue(Hash::check('newpassword123', $user->fresh()->getRawOriginal('password')));
        $this->assertSame(0, OTP::count());
    }

    public function test_wrong_code_is_rejected_and_keeps_the_email(): void
    {
        $user = User::factory()->create(['password' => 'original-password']);
        $this->requestResetCode($user);

        $this->resetWithCode($user, '000000')
            ->assertRedirect(route('password.request'))
            ->assertSessionHasErrors('otp')
            ->assertSessionHasInput('email', $user->email);

        $this->assertTrue(Hash::check('original-password', $user->fresh()->getRawOriginal('password')));
    }

    /**
     * The form checks only reset codes: a code mailed to verify the address or
     * to confirm an action is refused like any wrong code.
     */
    public function test_a_code_sent_for_another_purpose_is_rejected(): void
    {
        $user = User::factory()->unverified()->create(['password' => 'original-password']);
        Mail::fake();

        app(AuthService::class)->sendEmailVerification($user);
        $verification = (string) Mail::sent(VerifyUserEmail::class)->last()->otp;
        app(AuthService::class)->sendConfirmationOtp($user);
        $confirmation = (string) Mail::sent(EmailOTP::class)->last()->otp;

        foreach ([$verification, $confirmation] as $otp) {
            $this->resetWithCode($user, $otp)
                ->assertRedirect(route('password.request'))
                ->assertSessionHasErrors('otp');
        }

        $this->assertTrue(Hash::check('original-password', $user->fresh()->getRawOriginal('password')));
    }

    public function test_unknown_email_gets_the_same_answer_as_a_wrong_code(): void
    {
        $this->from(route('password.request'))->post(route('user-password.update'), [
            'email' => 'nobody@example.com',
            'otp' => '123456',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertSessionHasErrors(['otp' => 'Invalid or expired code.']);
    }

    public function test_code_resets_only_once(): void
    {
        $user = User::factory()->create();
        $otp = $this->requestResetCode($user);

        $this->resetWithCode($user, $otp)->assertRedirect(route('login'));
        $this->resetWithCode($user, $otp, 'another123')->assertSessionHasErrors('otp');

        $this->assertTrue(Hash::check('newpassword123', $user->fresh()->getRawOriginal('password')));
    }

    public function test_password_rules_do_not_run_before_the_code_is_proven(): void
    {
        config(['neev.password' => ['required', 'confirmed', PasswordHistory::notReused(3)]]);

        $user = User::factory()->create(['password' => 'original-password']);
        $this->requestResetCode($user);

        $this->resetWithCode($user, '000000', 'original-password')
            ->assertSessionHasErrors('otp')
            ->assertSessionDoesntHaveErrors('password');
    }

    public function test_rejected_password_does_not_spend_the_code(): void
    {
        config(['neev.password' => ['required', 'confirmed', PasswordHistory::notReused(3)]]);

        $user = User::factory()->create(['password' => 'original-password']);
        $otp = $this->requestResetCode($user);

        $this->resetWithCode($user, $otp, 'original-password')->assertSessionHasErrors('password');
        $this->resetWithCode($user, $otp)->assertRedirect(route('login'));
    }

    public function test_link_reset_discards_the_code_sent_with_it(): void
    {
        $user = User::factory()->create();
        $otp = $this->requestResetCode($user);

        $this->travel(1)->seconds();

        $url = URL::temporarySignedRoute(
            'reset.request',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => hash('sha256', $user->email)]
        );
        $resetToken = $this->get($url)->assertOk()->viewData('reset_token');

        $this->post(route('user-password.update'), [
            'email' => $user->email,
            'reset_token' => $resetToken,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertRedirect('login');

        $this->resetWithCode($user, $otp, 'another123')->assertSessionHasErrors('otp');
    }

    public function test_link_form_does_not_answer_password_guesses_without_its_token(): void
    {
        // The rules compare against the current password, so they must not
        // run for a request that has not proven the link.
        config(['neev.password' => ['required', 'confirmed', PasswordHistory::notReused(3)]]);

        $user = User::factory()->create(['password' => 'original-password']);

        $this->post(route('user-password.update'), [
            'email' => $user->email,
            'reset_token' => 'not-the-token',
            'password' => 'original-password',
            'password_confirmation' => 'original-password',
        ])->assertRedirect(route('password.request'))
            ->assertSessionDoesntHaveErrors('password');
    }

    public function test_link_token_survives_a_rejected_password(): void
    {
        config(['neev.password' => ['required', 'confirmed', PasswordHistory::notReused(3)]]);

        $user = User::factory()->create(['password' => 'original-password']);
        $this->travel(1)->seconds();
        $url = URL::temporarySignedRoute(
            'reset.request',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => hash('sha256', $user->email)]
        );
        $resetToken = $this->get($url)->assertOk()->viewData('reset_token');

        $payload = ['email' => $user->email, 'reset_token' => $resetToken];

        $this->post(route('user-password.update'), $payload + [
            'password' => 'original-password',
            'password_confirmation' => 'original-password',
        ])->assertSessionHasErrors('password');

        $this->post(route('user-password.update'), $payload + [
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertRedirect('login');
    }

    // -----------------------------------------------------------------
    // Per-account limits and retired links
    // -----------------------------------------------------------------

    public function test_too_many_reset_requests_are_refused_on_the_page(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < AuthService::PASSWORD_RESET_SEND_LIMIT; $i++) {
            $this->requestResetCode($user);
        }

        Mail::fake();
        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => $user->email])
            ->assertRedirect(route('password.request'))
            ->assertSessionHasErrors('email');
        Mail::assertNothingSent();
    }

    public function test_wrong_codes_lock_the_code_form_for_the_account(): void
    {
        // More requests than the per-IP route limit allows; the per-account
        // limits under test are separate from it.
        $this->withoutMiddleware(ThrottleRequests::class);

        $user = User::factory()->create(['password' => 'original-password']);

        $this->requestResetCode($user);
        for ($i = 0; $i < 5; $i++) {
            $this->resetWithCode($user, 'wrong-' . $i);
        }
        $this->requestResetCode($user);
        for ($i = 5; $i < AuthService::PASSWORD_RESET_GUESS_LIMIT; $i++) {
            $this->resetWithCode($user, 'wrong-' . $i);
        }
        $otp = $this->requestResetCode($user);

        $this->resetWithCode($user, $otp)
            ->assertSessionHasErrors(['otp' => 'Too many incorrect codes. Use the link in the email, or try again later.']);

        $this->assertTrue(Hash::check('original-password', $user->fresh()->getRawOriginal('password')));
    }

    private function openResetLink(User $user): string
    {
        $this->travel(1)->seconds();
        $url = URL::temporarySignedRoute(
            'reset.request',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => hash('sha256', $user->email)]
        );

        return $url;
    }

    public function test_a_used_link_does_not_open_again(): void
    {
        $user = User::factory()->create();
        $url = $this->openResetLink($user);

        $resetToken = $this->get($url)->assertOk()->viewData('reset_token');
        $this->post(route('user-password.update'), [
            'email' => $user->email,
            'reset_token' => $resetToken,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertRedirect('login');

        $this->get($url)
            ->assertRedirect(route('password.request'))
            ->assertSessionHasErrors('message');
    }

    public function test_the_form_is_refused_for_an_email_other_than_the_links(): void
    {
        $user = User::factory()->create(['password' => 'original-password']);
        $other = User::factory()->create();
        $resetToken = $this->get($this->openResetLink($user))->assertOk()->viewData('reset_token');

        $this->post(route('user-password.update'), [
            'email' => $other->email,
            'reset_token' => $resetToken,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertRedirect(route('password.request'));

        $this->assertTrue(Hash::check('original-password', $user->fresh()->getRawOriginal('password')));
    }

    public function test_a_successful_link_reset_clears_its_session_data(): void
    {
        $user = User::factory()->create();
        $resetToken = $this->get($this->openResetLink($user))->assertOk()->viewData('reset_token');

        $this->post(route('user-password.update'), [
            'email' => $user->email,
            'reset_token' => $resetToken,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertRedirect('login');

        foreach (['password_reset_token', 'password_reset_email', 'password_reset_expires'] as $key) {
            $this->assertFalse(session()->has($key), $key);
        }
    }

    public function test_opening_a_link_keeps_its_expiry_for_the_submit_check(): void
    {
        $user = User::factory()->create();
        $url = $this->openResetLink($user);

        $this->get($url)->assertOk();

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame($query['expires'], session('password_reset_expires'));
    }

    public function test_a_form_opened_before_the_owner_recovers_the_account_is_refused(): void
    {
        // Someone holding the link opens its form; the owner then resets the
        // password another way. The open form must not undo that.
        $user = User::factory()->create();
        $resetToken = $this->get($this->openResetLink($user))->assertOk()->viewData('reset_token');

        $this->travel(1)->seconds();
        app(AuthService::class)->changePassword($user, 'owner-recovered');

        $this->post(route('user-password.update'), [
            'email' => $user->email,
            'reset_token' => $resetToken,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertRedirect(route('password.request'))
            ->assertSessionHasErrors('message');

        $this->assertTrue(Hash::check('owner-recovered', $user->fresh()->getRawOriginal('password')));
        $this->assertFalse(session()->has('password_reset_token'));
    }
}
