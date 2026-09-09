<?php

namespace Ssntpl\Neev\Tests\Feature\Account;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
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

    // -----------------------------------------------------------------
    // Deleting the account — web
    // -----------------------------------------------------------------

    public function test_passwordless_account_can_be_deleted_without_a_password(): void
    {
        $user = $this->passwordlessUser();

        $this->actingAs($user)
            ->delete(route('account.delete'))
            ->assertRedirect();

        $this->assertNull(User::model()->find($user->id));
    }

    public function test_account_with_a_password_still_has_to_supply_it_to_delete(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        $this->actingAs($user)
            ->from(route('account.security'))
            ->delete(route('account.delete'), ['password' => 'wrong-password'])
            ->assertRedirect(route('account.security'))
            ->assertSessionHasErrors('message');

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

    public function test_api_deletes_a_passwordless_account_without_a_password(): void
    {
        $user = $this->passwordlessUser();

        $this->withHeader('Authorization', 'Bearer ' . $this->apiToken($user))
            ->deleteJson('/neev/users')
            ->assertOk()
            ->assertJsonPath('message', 'Account has been deleted.');

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
