<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Ssntpl\Neev\Models\AccessToken;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;

/**
 * `AccessToken` has carried a `permissions` column and a `can()` method since
 * the beginning, and nothing consulted either — every scoped token was a fully
 * privileged token. These cover the middleware that makes the column mean
 * something, and the distinction that makes it usable: a login token is the
 * API's session and carries the user's whole authority, while an API token
 * carries only the abilities it was given.
 */
class TokenAbilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['neev:api', 'neev-token-can:write'])
            ->get('/needs-write', fn () => response()->json(['ok' => true]));

        Route::middleware(['neev:api', 'neev-token-can:read,write'])
            ->get('/needs-both', fn () => response()->json(['ok' => true]));

        Route::middleware(['neev:api'])
            ->get('/needs-nothing', fn () => response()->json(['ok' => true]));

        // Misconfigured on purpose: the ability check with nothing in front of
        // it to authenticate a token.
        Route::middleware(['neev-token-can:write'])
            ->get('/no-token', fn () => response()->json(['ok' => true]));
    }

    private function hit(string $uri, string $token)
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ])->getJson($uri);
    }

    public function test_a_token_holding_the_ability_is_allowed(): void
    {
        $user = User::factory()->create();
        $token = $user->createApiToken('scoped', ['write'])->plainTextToken;

        $this->hit('/needs-write', $token)->assertOk();
    }

    public function test_a_token_without_the_ability_is_refused(): void
    {
        $user = User::factory()->create();
        $token = $user->createApiToken('scoped', ['read'])->plainTextToken;

        $this->hit('/needs-write', $token)->assertForbidden();
    }

    /**
     * The refusal is JSON whatever the client asked for. Everything here was
     * authenticated by a bearer token; a redirect would hand a curl client an
     * HTML page instead of a 403.
     */
    public function test_a_refusal_is_a_json_403_even_without_an_accept_header(): void
    {
        $user = User::factory()->create();
        $token = $user->createApiToken('scoped', ['read'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->get('/needs-write')
            ->assertForbidden()
            ->assertJsonPath('message', 'This token is not permitted to perform this action.');
    }

    /**
     * Fail-closed: with no token on the request there is nothing whose
     * abilities can be checked, so the request is refused, not waved through.
     */
    public function test_a_request_with_no_token_is_refused(): void
    {
        $this->getJson('/no-token')
            ->assertForbidden()
            ->assertJsonPath('message', 'This action requires an API token.');

        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/no-token')->assertForbidden();
    }

    /**
     * The schema default for `token_type` is `login`, the type `can()` trusts
     * unconditionally. A row created directly, without naming a type, must
     * come out as the scoped kind rather than inherit that authority.
     */
    public function test_a_token_created_without_a_type_is_an_api_token(): void
    {
        $user = User::factory()->create();
        $plainText = 'raw-token-plaintext';

        $token = $user->accessTokens()->create([
            'name' => 'raw',
            'token' => $plainText,
            'permissions' => ['read'],
        ]);

        $this->assertSame(AccessToken::api_token, $token->fresh()->token_type);
        $this->hit('/needs-write', $token->id . '|' . $plainText)->assertForbidden();
    }

    /** A wildcard stands for every ability. */
    public function test_a_wildcard_token_is_allowed(): void
    {
        $user = User::factory()->create();
        $token = $user->createApiToken('root', ['*'])->plainTextToken;

        $this->hit('/needs-write', $token)->assertOk();
    }

    /** Every listed ability is required, not just one of them. */
    public function test_all_listed_abilities_are_required(): void
    {
        $user = User::factory()->create();

        $partial = $user->createApiToken('partial', ['read'])->plainTextToken;
        $this->hit('/needs-both', $partial)->assertForbidden();

        $full = $user->createApiToken('full', ['read', 'write'])->plainTextToken;
        $this->hit('/needs-both', $full)->assertOk();
    }

    /**
     * createApiToken() defaults permissions to an empty array, so a token nobody
     * chose abilities for can do nothing on a guarded route. Fail-closed is the
     * point of a scope.
     */
    public function test_a_token_created_without_abilities_can_do_nothing_guarded(): void
    {
        $user = User::factory()->create();
        $token = $user->createApiToken('unscoped')->plainTextToken;

        $this->hit('/needs-write', $token)->assertForbidden();
        // ...but an unguarded route is unaffected: the middleware is opt-in.
        $this->hit('/needs-nothing', $token)->assertOk();
    }

    /**
     * The credential from an ordinary sign-in. It sets no permissions at all, so
     * without the login-token exemption an ability check would refuse every
     * signed-in caller and the feature would be unusable.
     */
    public function test_a_login_token_carries_full_authority(): void
    {
        $user = User::factory()->create();
        $token = $user->createLoginToken(60)->plainTextToken;

        $this->hit('/needs-write', $token)->assertOk();
        $this->hit('/needs-both', $token)->assertOk();
    }
}
