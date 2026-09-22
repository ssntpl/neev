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
}
