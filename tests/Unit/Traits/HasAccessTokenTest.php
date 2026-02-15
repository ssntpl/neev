<?php

namespace Ssntpl\Neev\Tests\Unit\Traits;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Ssntpl\Neev\Models\AccessToken;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\NewAccessToken;
use Ssntpl\Neev\Tests\TestCase;

class HasAccessTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create the ACL permissions table required by createApiToken()
        if (!Schema::hasTable('acl_permissions')) {
            Schema::create('acl_permissions', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('resource_type')->nullable();
            });
        }
    }

    // -----------------------------------------------------------------
    // createApiToken()
    // -----------------------------------------------------------------

    public function test_create_api_token_returns_new_access_token_instance(): void
    {
        $user = User::factory()->create();

        $result = $user->createApiToken();

        $this->assertInstanceOf(NewAccessToken::class, $result);
    }

    public function test_create_api_token_plain_text_token_has_correct_format(): void
    {
        $user = User::factory()->create();

        $result = $user->createApiToken();

        // Format: {id}|{random40chars}
        $this->assertMatchesRegularExpression('/^\d+\|.{40}$/', $result->plainTextToken);
    }

    public function test_create_api_token_stores_hashed_token_in_db(): void
    {
        $user = User::factory()->create();

        $result = $user->createApiToken();

        // Extract the plain text portion (after the pipe)
        $parts = explode('|', $result->plainTextToken);
        $id = $parts[0];
        $plainToken = $parts[1];

        $token = AccessToken::find($id);
        $this->assertNotNull($token);

        // The stored token should be hashed, not plain text
        $this->assertNotEquals($plainToken, $token->getAttributes()['token']);

        // Hash::check should verify the token
        $this->assertTrue(Hash::check($plainToken, $token->getAttributes()['token']));
    }

    public function test_create_api_token_sets_token_type_to_api_token(): void
    {
        $user = User::factory()->create();

        $result = $user->createApiToken();

        $this->assertEquals(AccessToken::api_token, $result->accessToken->token_type);
    }

    public function test_create_api_token_with_default_name(): void
    {
        $user = User::factory()->create();

        $result = $user->createApiToken();

        $this->assertEquals('api token', $result->accessToken->name);
    }

    public function test_create_api_token_with_custom_name(): void
    {
        $user = User::factory()->create();

        $result = $user->createApiToken('My Custom Token');

        $this->assertEquals('My Custom Token', $result->accessToken->name);
    }

    public function test_create_api_token_with_custom_permissions(): void
    {
        $user = User::factory()->create();

        $result = $user->createApiToken('token', ['read', 'write']);

        $this->assertEquals(['read', 'write'], $result->accessToken->permissions);
    }

    public function test_create_api_token_with_null_permissions_defaults_to_empty_array(): void
    {
        $user = User::factory()->create();

        $result = $user->createApiToken('token', null);

        $this->assertEquals([], $result->accessToken->permissions);
    }

    public function test_create_api_token_with_expiry_sets_expires_at(): void
    {
        $user = User::factory()->create();

        $result = $user->createApiToken('token', null, 60);

        $this->assertNotNull($result->accessToken->expires_at);
        // Should be approximately 60 minutes from now
        $this->assertTrue($result->accessToken->expires_at->isFuture());
        $this->assertEqualsWithDelta(
            now()->addMinutes(60)->timestamp,
            $result->accessToken->expires_at->timestamp,
            5 // 5 second tolerance
        );
    }

    public function test_create_api_token_without_expiry_has_null_expires_at(): void
    {
        $user = User::factory()->create();

        $result = $user->createApiToken();

        $this->assertNull($result->accessToken->expires_at);
    }

    public function test_create_api_token_belongs_to_user(): void
    {
        $user = User::factory()->create();

        $result = $user->createApiToken();

        $this->assertEquals($user->id, $result->accessToken->user_id);
    }

    // -----------------------------------------------------------------
    // createLoginToken()
    // -----------------------------------------------------------------

    public function test_create_login_token_returns_new_access_token_instance(): void
    {
        $user = User::factory()->create();

        $result = $user->createLoginToken(30);

        $this->assertInstanceOf(NewAccessToken::class, $result);
    }

    public function test_create_login_token_sets_token_type_to_login(): void
    {
        $user = User::factory()->create();

        $result = $user->createLoginToken(30);

        $this->assertEquals(AccessToken::login, $result->accessToken->token_type);
    }

    public function test_create_login_token_sets_name_to_login(): void
    {
        $user = User::factory()->create();

        $result = $user->createLoginToken(30);

        $this->assertEquals(AccessToken::login, $result->accessToken->name);
    }

    public function test_create_login_token_with_expiry_sets_expires_at(): void
    {
        $user = User::factory()->create();

        $result = $user->createLoginToken(15);

        $this->assertNotNull($result->accessToken->expires_at);
        $this->assertEqualsWithDelta(
            now()->addMinutes(15)->timestamp,
            $result->accessToken->expires_at->timestamp,
            5
        );
    }

    public function test_create_login_token_with_null_expiry_has_null_expires_at(): void
    {
        $user = User::factory()->create();

        $result = $user->createLoginToken(null);

        $this->assertNull($result->accessToken->expires_at);
    }

    public function test_create_login_token_plain_text_token_has_correct_format(): void
    {
        $user = User::factory()->create();

        $result = $user->createLoginToken(30);

        $this->assertMatchesRegularExpression('/^\d+\|.{40}$/', $result->plainTextToken);
    }

    // -----------------------------------------------------------------
    // accessTokens()
    // -----------------------------------------------------------------

    public function test_access_tokens_returns_all_tokens(): void
    {
        $user = User::factory()->create();

        $user->createApiToken();
        $user->createLoginToken(30);

        $this->assertCount(2, $user->accessTokens);
    }

    // -----------------------------------------------------------------
    // apiTokens()
    // -----------------------------------------------------------------

    public function test_api_tokens_only_returns_api_token_type(): void
    {
        $user = User::factory()->create();

        $user->createApiToken('API Token 1');
        $user->createApiToken('API Token 2');
        $user->createLoginToken(30);

        $apiTokens = $user->apiTokens;

        $this->assertCount(2, $apiTokens);
        foreach ($apiTokens as $token) {
            $this->assertEquals(AccessToken::api_token, $token->token_type);
        }
    }

    // -----------------------------------------------------------------
    // loginTokens()
    // -----------------------------------------------------------------

    public function test_login_tokens_only_returns_login_type(): void
    {
        $user = User::factory()->create();

        $user->createApiToken();
        $user->createLoginToken(30);
        $user->createLoginToken(60);

        $loginTokens = $user->loginTokens;

        $this->assertCount(2, $loginTokens);
        foreach ($loginTokens as $token) {
            $this->assertEquals(AccessToken::login, $token->token_type);
        }
    }

    public function test_login_tokens_empty_when_no_login_tokens_exist(): void
    {
        $user = User::factory()->create();

        $user->createApiToken();

        $this->assertCount(0, $user->loginTokens);
    }
}
