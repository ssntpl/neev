<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;

class CustomRoutePrefixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The prefix is read at route registration, so it must be set
     * before the service provider boots.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('neev.route_prefix', 'auth');
        $app['config']->set('neev.tenant', true); // load SSO routes too
    }

    public function test_api_routes_live_under_the_custom_prefix(): void
    {
        $user = User::factory()->create(['password' => 'password123']);
        config(['neev.password' => ['required']]);

        $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertOk()
            ->assertJsonPath('auth_state', 'authenticated');

        $this->postJson('/neev/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertNotFound();
    }

    public function test_authenticated_routes_work_under_the_custom_prefix(): void
    {
        $user = User::factory()->create();
        $token = $user->createLoginToken(1440)->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/auth/users')
            ->assertOk();
    }

    public function test_mfa_token_route_gate_follows_the_prefix(): void
    {
        // An MFA-type token may only reach the MFA verification routes.
        // The gate matches paths, so it must track the configured prefix.
        $user = User::factory()->create();
        $mfaToken = $user->accessTokens()->create([
            'name' => 'mfa',
            'token' => $plain = \Illuminate\Support\Str::random(40),
            'token_type' => \Ssntpl\Neev\Models\AccessToken::mfa_token,
        ]);
        $bearer = $mfaToken->id . '|' . $plain;

        // Blocked from ordinary API routes under the custom prefix.
        $this->withHeader('Authorization', 'Bearer ' . $bearer)
            ->getJson('/auth/users')
            ->assertUnauthorized();

        // Allowed on the MFA route under the custom prefix.
        $this->withHeader('Authorization', 'Bearer ' . $bearer)
            ->getJson('/auth/mfa')
            ->assertOk();
    }

    public function test_sso_and_csrf_routes_live_under_the_custom_prefix(): void
    {
        $this->get('/auth/csrf-cookie')->assertNoContent();
        $this->getJson('/auth/tenant/auth')->assertOk();
        $this->getJson('/neev/tenant/auth')->assertNotFound();
    }
}
