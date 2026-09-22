<?php

namespace Ssntpl\Neev\Tests\Unit\Models;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Ssntpl\Neev\Database\Factories\AccessTokenFactory;
use Ssntpl\Neev\Database\Factories\LoginAttemptFactory;
use Ssntpl\LaravelAcl\Models\Permission;
use Ssntpl\Neev\Models\AccessToken;
use Ssntpl\Neev\Models\LoginAttempt;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;

class AccessTokenTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Constants
    // -----------------------------------------------------------------

    public function test_api_token_constant(): void
    {
        $this->assertSame('api_token', AccessToken::api_token);
    }

    public function test_login_constant(): void
    {
        $this->assertSame('login', AccessToken::login);
    }

    // -----------------------------------------------------------------
    // Token hashing (cast 'hashed')
    // -----------------------------------------------------------------

    public function test_token_is_hashed_in_database(): void
    {
        $plainToken = 'my-plain-text-token';

        $token = AccessTokenFactory::new()->create([
            'token' => $plainToken,
        ]);

        // The raw DB value should not be the plain text
        $rawValue = \Illuminate\Support\Facades\DB::table('access_tokens')
            ->where('id', $token->id)
            ->value('token');

        $this->assertNotSame($plainToken, $rawValue);
        $this->assertTrue(Hash::check($plainToken, $rawValue));
    }

    // -----------------------------------------------------------------
    // can()
    // -----------------------------------------------------------------

    public function test_can_returns_true_for_matching_permission(): void
    {
        $token = AccessTokenFactory::new()->create([
            'permissions' => ['read', 'write', 'delete'],
        ]);

        $this->assertTrue($token->can('read'));
        $this->assertTrue($token->can('write'));
        $this->assertTrue($token->can('delete'));
    }

    public function test_can_returns_true_for_wildcard(): void
    {
        $token = AccessTokenFactory::new()->create([
            'permissions' => ['*'],
        ]);

        $this->assertTrue($token->can('read'));
        $this->assertTrue($token->can('write'));
        $this->assertTrue($token->can('anything'));
    }

    public function test_can_returns_false_for_missing_permission(): void
    {
        $token = AccessTokenFactory::new()->create([
            'permissions' => ['read', 'write'],
        ]);

        $this->assertFalse($token->can('delete'));
        $this->assertFalse($token->can('admin'));
    }

    public function test_can_returns_false_for_empty_permissions(): void
    {
        $token = AccessTokenFactory::new()->create([
            'permissions' => [],
        ]);

        $this->assertFalse($token->can('read'));
    }

    public function test_can_returns_false_for_null_permissions(): void
    {
        $token = AccessTokenFactory::new()->create([
            'permissions' => null,
        ]);

        $this->assertFalse($token->can('read'));
    }

    // -----------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------

    public function test_user_relationship(): void
    {
        $user = User::factory()->create();
        $token = AccessTokenFactory::new()->create([
            'user_id' => $user->id,
        ]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class, $token->user());
        $this->assertInstanceOf(User::class, $token->user);
        $this->assertSame($user->id, $token->user->id);
    }

    public function test_attempt_relationship(): void
    {
        $user = User::factory()->create();
        $attempt = LoginAttemptFactory::new()->create([
            'user_id' => $user->id,
        ]);

        $token = AccessTokenFactory::new()->create([
            'user_id' => $user->id,
            'attempt_id' => $attempt->id,
        ]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class, $token->attempt());
        $this->assertInstanceOf(LoginAttempt::class, $token->attempt);
        $this->assertSame($attempt->id, $token->attempt->id);
    }

    public function test_attempt_relationship_returns_null_when_no_attempt(): void
    {
        $token = AccessTokenFactory::new()->create([
            'attempt_id' => null,
        ]);

        $this->assertNull($token->attempt);
    }

    // -----------------------------------------------------------------
    // Casts
    // -----------------------------------------------------------------

    public function test_permissions_is_cast_to_array(): void
    {
        $token = AccessTokenFactory::new()->create([
            'permissions' => ['read', 'write'],
        ]);

        $token->refresh();

        $this->assertIsArray($token->permissions);
        $this->assertEquals(['read', 'write'], $token->permissions);
    }

    public function test_last_used_at_is_cast_to_datetime(): void
    {
        $now = now();
        $token = AccessTokenFactory::new()->create([
            'last_used_at' => $now,
        ]);

        $token->refresh();

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $token->last_used_at);
    }

    public function test_expires_at_is_cast_to_datetime(): void
    {
        $token = AccessTokenFactory::new()->expired()->create();

        $token->refresh();

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $token->expires_at);
    }

    // -----------------------------------------------------------------
    // canGrant()
    // -----------------------------------------------------------------

    /** A login token carries the user's whole authority, so it grants anything. */
    public function test_a_login_token_may_grant_anything(): void
    {
        $token = AccessTokenFactory::new()->create(['token_type' => AccessToken::login, 'permissions' => []]);

        $this->assertTrue($token->canGrant(['read', 'write', '*']));
    }

    /** A scoped token passes on its own entries and nothing else. */
    public function test_a_scoped_token_may_grant_only_what_it_holds(): void
    {
        $token = AccessTokenFactory::new()->create([
            'token_type' => AccessToken::api_token,
            'permissions' => ['read'],
        ]);

        $this->assertTrue($token->canGrant(['read']));
        $this->assertTrue($token->canGrant([]), 'Granting nothing is always allowed.');
        $this->assertFalse($token->canGrant(['write']));
        $this->assertFalse($token->canGrant(['read', 'write']));
        $this->assertFalse($token->canGrant(['*']), 'A scope cannot be widened from inside.');
    }

    /** A wildcard token holds every ability, so it may pass any of them on. */
    public function test_a_wildcard_token_may_grant_anything(): void
    {
        $token = AccessTokenFactory::new()->create([
            'token_type' => AccessToken::api_token,
            'permissions' => ['*'],
        ]);

        $this->assertTrue($token->canGrant(['read', 'write']));
        $this->assertTrue($token->canGrant(['*']));
    }

    /**
     * Naming every registered permission is asking for `*`, because
     * `createApiToken()` stores it that way — and a stored `*` also covers
     * permissions registered later, which is more than the grantor holds.
     */
    public function test_naming_every_registered_permission_needs_a_wildcard_in_hand(): void
    {
        Permission::create(['name' => 'read']);
        Permission::create(['name' => 'write']);

        $token = AccessTokenFactory::new()->create([
            'token_type' => AccessToken::api_token,
            'permissions' => ['read', 'write'],
        ]);

        $this->assertTrue($token->canGrant(['read']), 'A narrower grant is fine.');
        $this->assertFalse(
            $token->canGrant(['read', 'write']),
            'That list is stored as * and would cover permissions registered later.',
        );
    }

    /** A login token holds `*`, so the collapse is no obstacle to it. */
    public function test_a_login_token_may_grant_every_registered_permission(): void
    {
        Permission::create(['name' => 'read']);
        Permission::create(['name' => 'write']);

        $token = AccessTokenFactory::new()->create(['token_type' => AccessToken::login, 'permissions' => []]);

        $this->assertTrue($token->canGrant(['read', 'write']));
    }

    /** A non-string entry is not an ability anything can hold. */
    public function test_a_non_string_permission_is_never_granted(): void
    {
        $token = AccessTokenFactory::new()->create([
            'token_type' => AccessToken::api_token,
            'permissions' => ['*'],
        ]);

        $this->assertFalse($token->canGrant([['read']]));
    }
}
