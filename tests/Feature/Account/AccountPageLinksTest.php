<?php

namespace Ssntpl\Neev\Tests\Feature\Account;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;

/**
 * Someone stuck on the verify-email page has only two useful ways out — fix a
 * mistyped address, or drop the account — and neither is reachable from there
 * unless the page says so. The profile page likewise has to offer the way to
 * change the address it displays.
 */
class AccountPageLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_verify_email_page_links_to_changing_the_address(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get(route('verification.notice'))
            ->assertOk()
            ->assertSee(route('email.change'))
            ->assertSee('Change your email address');
    }

    public function test_the_verify_email_page_links_to_managing_the_account(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get(route('verification.notice'))
            ->assertOk()
            ->assertSee(route('account.security'))
            ->assertSee('Manage or delete it');
    }

    public function test_the_profile_page_links_to_changing_the_address(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('account.profile'))
            ->assertOk()
            ->assertSee($user->email)
            ->assertSee(route('email.change'));
    }
}
