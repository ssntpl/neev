<?php

namespace Ssntpl\Neev\Tests\Unit\Middleware;

use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Ssntpl\Neev\Http\Middleware\NeevAPIMiddleware;
use Ssntpl\Neev\Models\AccessToken;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;
use Symfony\Component\HttpFoundation\Response;

class NeevAPIMiddlewareTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    private NeevAPIMiddleware $middleware;

    protected function setUp(): void
    {
        parent::setUp();

        $this->middleware = new NeevAPIMiddleware();
    }

    /**
     * Build a JSON API request with an optional Bearer token.
     */
    private function buildRequest(string $path = '/api/test', ?string $bearerToken = null): Request
    {
        $request = Request::create($path, 'GET', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        if ($bearerToken !== null) {
            $request->headers->set('Authorization', 'Bearer ' . $bearerToken);
        }

        return $request;
    }

    /**
     * The "next" closure that returns a simple 200 OK JSON response.
     */
    private function passThrough(): Closure
    {
        return fn (Request $req): Response => response()->json(['message' => 'OK'], 200);
    }

    /**
     * Create a user and a valid API token, returning both the user and the plain-text token string.
     * Uses the HasAccessToken trait's createApiToken() method which properly hashes.
     *
     * @return array{user: User, plainTextToken: string, accessToken: AccessToken}
     */
    private function createUserWithApiToken(array $userState = [], ?int $expiry = null): array
    {
        $user = User::factory()->create($userState);
        $newToken = $user->createApiToken('test-token', null, $expiry);

        return [
            'user' => $user,
            'plainTextToken' => $newToken->plainTextToken,
            'accessToken' => $newToken->accessToken,
        ];
    }

    /**
     * Create a user with a raw-plaintext token of a specific type.
     * Returns the full "{id}|{plaintext}" string and the DB token record.
     *
     * @return array{user: User, fullToken: string, accessToken: AccessToken}
     */
    private function createUserWithToken(string $tokenType, array $userState = [], ?string $expiresAt = null): array
    {
        $user = User::factory()->create($userState);
        $plainText = Str::random(40);
        $attrs = [
            'name' => $tokenType,
            'token' => $plainText,
            'token_type' => $tokenType,
            'permissions' => [],
        ];
        if ($expiresAt) {
            $attrs['expires_at'] = $expiresAt;
        }
        $token = $user->accessTokens()->create($attrs);

        return [
            'user' => $user,
            'fullToken' => $token->id . '|' . $plainText,
            'accessToken' => $token,
        ];
    }

    // -----------------------------------------------------------------
    // Missing / malformed token
    // -----------------------------------------------------------------

    public function test_returns_401_when_no_token_provided(): void
    {
        $request = $this->buildRequest();

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertStringContainsString('Missing token', $response->getContent());
    }

    public function test_returns_401_when_token_has_no_pipe_separator(): void
    {
        $request = $this->buildRequest('/api/test', 'tokenWithoutPipe');

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertStringContainsString('Missing token', $response->getContent());
    }

    // -----------------------------------------------------------------
    // Invalid token ID or hash mismatch
    // -----------------------------------------------------------------

    public function test_returns_401_when_token_id_does_not_exist(): void
    {
        $request = $this->buildRequest('/api/test', '99999|someRandomPlaintext');

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertStringContainsString('Invalid or expired token', $response->getContent());
    }

    public function test_returns_401_when_token_plaintext_does_not_match_hash(): void
    {
        $data = $this->createUserWithApiToken();
        $tokenId = $data['accessToken']->id;

        // Use the correct ID but wrong plaintext
        $request = $this->buildRequest('/api/test', $tokenId . '|wrongPlaintext');

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertStringContainsString('Invalid or expired token', $response->getContent());
    }

    // -----------------------------------------------------------------
    // MFA token on non-MFA path
    // -----------------------------------------------------------------

    public function test_returns_401_for_mfa_token_on_non_mfa_path(): void
    {
        $data = $this->createUserWithToken(AccessToken::mfa_token);

        // Request path is NOT neev/mfa/otp/verify
        $request = $this->buildRequest('/api/some-other-path', $data['fullToken']);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertStringContainsString('Invalid or expired token', $response->getContent());
    }

    public function test_allows_mfa_token_on_mfa_verify_path(): void
    {
        $data = $this->createUserWithToken(AccessToken::mfa_token);

        $request = $this->buildRequest('/neev/mfa/otp/verify', $data['fullToken']);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Expired token
    // -----------------------------------------------------------------

    public function test_returns_401_for_expired_token_and_deletes_it(): void
    {
        $user = User::factory()->create();
        $plainText = Str::random(40);
        $token = $user->accessTokens()->create([
            'name' => 'api token',
            'token' => $plainText,
            'token_type' => AccessToken::api_token,
            'permissions' => ['*'],
            'expires_at' => now()->subHour(),
        ]);

        $fullToken = $token->id . '|' . $plainText;
        $request = $this->buildRequest('/api/test', $fullToken);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertStringContainsString('Invalid or expired token', $response->getContent());

        // Token should be deleted from the database
        $this->assertDatabaseMissing('access_tokens', ['id' => $token->id]);
    }

    // -----------------------------------------------------------------
    // Inactive / missing user
    // -----------------------------------------------------------------

    public function test_returns_403_when_user_is_inactive(): void
    {
        $data = $this->createUserWithApiToken(['active' => true]);
        $data['user']->update(['active' => false]);

        $request = $this->buildRequest('/api/test', $data['plainTextToken']);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(403, $response->getStatusCode());
        $this->assertStringContainsString('deactivated', $response->getContent());
    }

    public function test_returns_403_when_user_does_not_exist(): void
    {
        $data = $this->createUserWithApiToken();

        // Delete the user but keep the token
        $data['user']->emails()->delete();
        $data['user']->passwords()->delete();
        $data['user']->forceDelete();

        $request = $this->buildRequest('/api/test', $data['plainTextToken']);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(403, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Updates last_used_at
    // -----------------------------------------------------------------

    public function test_updates_last_used_at_on_valid_request(): void
    {
        $data = $this->createUserWithApiToken();

        $this->assertNull($data['accessToken']->fresh()->last_used_at);

        $request = $this->buildRequest('/api/test', $data['plainTextToken']);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertNotNull($data['accessToken']->fresh()->last_used_at);
    }

    // -----------------------------------------------------------------
    // Email verification (API)
    // -----------------------------------------------------------------

    public function test_returns_401_when_email_not_verified_and_email_verification_enabled(): void
    {
        $this->enableEmailVerification();

        $data = $this->createUserWithApiToken();
        $data['user']->email->update(['verified_at' => null]);

        $request = $this->buildRequest('/api/some-protected-path', $data['plainTextToken']);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertStringContainsString('Email not verified', $response->getContent());
    }

    public function test_passes_through_email_bypass_paths_even_with_unverified_email(): void
    {
        $this->enableEmailVerification();

        $data = $this->createUserWithApiToken();
        $data['user']->email->update(['verified_at' => null]);

        $bypassPaths = [
            '/neev/email/send',
            '/neev/logout',
            '/neev/email/update',
            '/neev/email/verify',
            '/neev/email/otp/send',
            '/neev/email/otp/verify',
            '/neev/users',
        ];

        foreach ($bypassPaths as $path) {
            $request = $this->buildRequest($path, $data['plainTextToken']);
            $response = $this->middleware->handle($request, $this->passThrough());

            $this->assertEquals(200, $response->getStatusCode(), "Expected 200 for bypass path: {$path}");
        }
    }

    public function test_does_not_check_email_when_email_verification_disabled(): void
    {
        config(['neev.email_verified' => false]);

        $data = $this->createUserWithApiToken();
        $data['user']->email->update(['verified_at' => null]);

        $request = $this->buildRequest('/api/some-path', $data['plainTextToken']);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Sets user resolver and token_id attribute
    // -----------------------------------------------------------------

    public function test_sets_user_resolver_and_token_id_attribute_on_request(): void
    {
        $data = $this->createUserWithApiToken();
        $resolvedUser = null;
        $resolvedTokenId = null;

        $request = $this->buildRequest('/api/test', $data['plainTextToken']);

        $next = function (Request $req) use (&$resolvedUser, &$resolvedTokenId): Response {
            $resolvedUser = $req->user();
            $resolvedTokenId = $req->attributes->get('token_id');
            return response()->json(['message' => 'OK'], 200);
        };

        $this->middleware->handle($request, $next);

        $this->assertNotNull($resolvedUser);
        $this->assertEquals($data['user']->id, $resolvedUser->id);
        $this->assertEquals((string) $data['accessToken']->id, $resolvedTokenId);
    }

    // -----------------------------------------------------------------
    // Token extraction: Bearer header, query, input
    // -----------------------------------------------------------------

    public function test_extracts_token_from_bearer_header(): void
    {
        $data = $this->createUserWithApiToken();

        $request = Request::create('/api/test', 'GET', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $request->headers->set('Authorization', 'Bearer ' . $data['plainTextToken']);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_extracts_token_from_query_parameter(): void
    {
        $data = $this->createUserWithApiToken();

        $request = Request::create('/api/test', 'GET', [
            'token' => $data['plainTextToken'],
        ], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_extracts_token_from_post_input(): void
    {
        $data = $this->createUserWithApiToken();

        $request = Request::create('/api/test', 'POST', [
            'token' => $data['plainTextToken'],
        ], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ]);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Valid token with non-expired future date passes through
    // -----------------------------------------------------------------

    public function test_passes_through_for_non_expired_token(): void
    {
        $user = User::factory()->create();
        $plainText = Str::random(40);
        $token = $user->accessTokens()->create([
            'name' => 'api token',
            'token' => $plainText,
            'token_type' => AccessToken::api_token,
            'permissions' => ['*'],
            'expires_at' => now()->addHour(),
        ]);

        $fullToken = $token->id . '|' . $plainText;
        $request = $this->buildRequest('/api/test', $fullToken);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
    }
}
