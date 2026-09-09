<?php

namespace Ssntpl\Neev\Tests\Feature\Account;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Ssntpl\Neev\Mail\VerifyUserEmail;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;

/**
 * Setting or resetting a password from inside the account area. The link goes
 * to the address on the account, so it works both for an account that never
 * had a password and for one whose owner has forgotten it — neither can prove
 * ownership with the current password.
 */
class PasswordResetLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['neev.password' => ['required', 'confirmed']]);
    }

    // -----------------------------------------------------------------
    // POST account/password/reset-link
    // -----------------------------------------------------------------

    public function test_signed_in_user_is_mailed_a_reset_link(): void
    {
        Mail::fake();

        $user = User::factory()->create(['password' => 'password']);

        $this->actingAs($user)
            ->from(route('account.security'))
            ->post(route('password.reset.link'))
            ->assertRedirect(route('account.security'))
            ->assertSessionHas('status');

        Mail::assertSent(VerifyUserEmail::class, function (VerifyUserEmail $mail) use ($user) {
            return $mail->hasTo($user->email)
                && $mail->purpose === 'Forgot Password'
                && str_contains($mail->url, '/update-password/' . $user->id . '/');
        });
    }

    public function test_an_account_without_a_password_is_mailed_the_same_link(): void
    {
        Mail::fake();

        $user = User::factory()->create(['password' => null]);

        $this->actingAs($user)
            ->from(route('account.security'))
            ->post(route('password.reset.link'))
            ->assertRedirect(route('account.security'));

        Mail::assertSent(VerifyUserEmail::class, fn (VerifyUserEmail $mail) => $mail->hasTo($user->email));
    }

    public function test_the_link_is_signed_and_carries_the_configured_expiry(): void
    {
        Mail::fake();

        config(['neev.url_expiry_time' => 30]);

        $user = User::factory()->create(['password' => 'password']);

        $this->actingAs($user)->post(route('password.reset.link'));

        Mail::assertSent(VerifyUserEmail::class, function (VerifyUserEmail $mail) {
            parse_str((string) parse_url($mail->url, PHP_URL_QUERY), $query);

            return isset($query['signature'], $query['expires'])
                && $mail->link_expiry === 30;
        });
    }

    public function test_a_guest_cannot_ask_for_a_link(): void
    {
        Mail::fake();

        $this->post(route('password.reset.link'))->assertRedirect();

        Mail::assertNothingSent();
    }

    public function test_the_route_is_rate_limited(): void
    {
        Mail::fake();

        $user = User::factory()->create(['password' => 'password']);

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->post(route('password.reset.link'))->assertRedirect();
        }

        $this->actingAs($user)->post(route('password.reset.link'))->assertStatus(429);
    }

    // -----------------------------------------------------------------
    // Spending the link while still signed in
    // -----------------------------------------------------------------

    protected function resetUrl(User $user): string
    {
        return URL::temporarySignedRoute('reset.request', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => hash('sha256', $user->email),
        ]);
    }

    public function test_a_signed_in_user_may_open_their_own_reset_link(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        $this->actingAs($user)
            ->get($this->resetUrl($user))
            ->assertOk()
            ->assertSee($user->email);
    }

    public function test_a_signed_in_user_is_sent_home_when_the_link_belongs_to_someone_else(): void
    {
        $user = User::factory()->create(['password' => 'password']);
        $other = User::factory()->create(['password' => 'password']);

        $this->actingAs($user)
            ->get($this->resetUrl($other))
            ->assertRedirect(config('neev.home'));
    }

    public function test_a_guest_may_open_a_reset_link(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        $this->get($this->resetUrl($user))->assertOk();
    }

    public function test_finishing_the_reset_while_signed_in_returns_to_the_security_page(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        $this->actingAs($user)->get($this->resetUrl($user))->assertOk();
        $token = session('password_reset_token');
        $this->assertNotNull($token);

        // The view is handed the plain token; the session holds its HMAC.
        $response = $this->actingAs($user)->get($this->resetUrl($user));
        $plainToken = $response->viewData('reset_token');

        $this->actingAs($user)
            ->post(route('user-password.update'), [
                'email' => $user->email,
                'reset_token' => $plainToken,
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])
            ->assertRedirect(route('account.security'))
            ->assertSessionHas('status');

        $this->assertTrue(Hash::check('brand-new-password', User::model()->find($user->id)->password));
    }

    public function test_finishing_the_reset_as_a_guest_still_lands_on_login(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        $plainToken = $this->get($this->resetUrl($user))->viewData('reset_token');

        $this->post(route('user-password.update'), [
            'email' => $user->email,
            'reset_token' => $plainToken,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertRedirect('login');
    }
}
