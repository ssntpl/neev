<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;

/**
 * Sessions are the only thing that keeps someone signed in: there is no
 * remember_token column, so nothing may try to issue a long-lived recaller
 * cookie against one.
 */
class RememberMeRemovedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['neev.password' => ['required']]);
    }

    public function test_the_users_table_has_no_remember_token_column(): void
    {
        $this->assertFalse(Schema::hasColumn('users', 'remember_token'));
    }

    public function test_password_login_issues_no_recaller_cookie_even_when_remember_is_posted(): void
    {
        $user = User::factory()->create(['password' => 'password123']);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password123',
            'remember' => 'on',
        ]);

        $this->assertAuthenticatedAs($user);

        $recaller = 'remember_' . Auth::getDefaultDriver();
        foreach ($response->headers->getCookies() as $cookie) {
            $this->assertStringNotContainsString($recaller, $cookie->getName());
        }
    }

    public function test_the_login_page_offers_no_remember_me_checkbox(): void
    {
        $user = User::factory()->create(['password' => 'password123']);

        $this->put('/login', ['email' => $user->email])
            ->assertOk()
            ->assertDontSee('remember_me')
            ->assertDontSee('Remember me');
    }

    public function test_the_user_model_still_hides_the_password_and_its_history(): void
    {
        $user = User::factory()->create(['password' => 'password123']);

        $array = $user->toArray();

        $this->assertArrayNotHasKey('password', $array);
        $this->assertArrayNotHasKey('password_history', $array);
    }
}
