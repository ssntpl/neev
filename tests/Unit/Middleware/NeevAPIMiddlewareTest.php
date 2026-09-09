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

    // Email verification is handled by the separate EnsureEmailIsVerified middleware

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

    // -----------------------------------------------------------------
    // Token type no longer gates the path
    // -----------------------------------------------------------------

    /**
     * The middleware used to refuse an `mfa_token` anywhere but the two MFA
     * paths, matched by string against the configured route prefix. The API
     * MFA challenge is carried by a short-lived JWT instead, so that token
     * type is gone and with it the path matching: a token now stands or falls
     * on its hash and its expiry alone.
     */
    public function test_token_type_does_not_restrict_which_paths_a_token_reaches(): void
    {
        $user = User::factory()->create();
        $plainText = Str::random(40);
        $token = $user->accessTokens()->create([
            'name' => 'login token',
            'token' => $plainText,
            'token_type' => AccessToken::login,
            'permissions' => [],
        ]);
        $fullToken = $token->id . '|' . $plainText;

        foreach (['/neev/mfa', '/neev/users', '/api/anything-at-all'] as $path) {
            $response = $this->middleware->handle(
                $this->buildRequest($path, $fullToken),
                $this->passThrough()
            );

            $this->assertEquals(
                200,
                $response->getStatusCode(),
                "A valid token should reach {$path}."
            );
        }
    }

    // -----------------------------------------------------------------
    // Sliding idle expiry
    // -----------------------------------------------------------------

    /**
     * Create a login token whose expiry sits a given number of minutes
     * in the future, optionally backdating when it was issued so the
     * absolute-lifetime ceiling can be exercised.
     *
     * @return array{user: User, plainTextToken: string, accessToken: AccessToken}
     */
    private function createLoginToken(int $expiresInMinutes, int $issuedMinutesAgo = 0): array
    {
        $user = User::factory()->create();
        $plainText = Str::random(40);

        $token = $user->accessTokens()->create([
            'name' => AccessToken::login,
            'token' => $plainText,
            'token_type' => AccessToken::login,
            'expires_at' => now()->addMinutes($expiresInMinutes),
        ]);

        if ($issuedMinutesAgo > 0) {
            $token->forceFill(['created_at' => now()->subMinutes($issuedMinutesAgo)])->saveQuietly();
        }

        return [
            'user' => $user,
            'plainTextToken' => $token->id . '|' . $plainText,
            'accessToken' => $token->fresh(),
        ];
    }

    public function test_login_token_past_its_half_life_slides_the_idle_window_forward(): void
    {
        config(['neev.login_token_expiry_minutes' => 1440]);

        // 6 hours left of a 24 hour window: past the half-way point.
        $data = $this->createLoginToken(360);

        $response = $this->middleware->handle(
            $this->buildRequest('/api/test', $data['plainTextToken']),
            $this->passThrough()
        );

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEqualsWithDelta(
            now()->addMinutes(1440)->getTimestamp(),
            $data['accessToken']->fresh()->expires_at->getTimestamp(),
            5,
            'An actively used session should be given a full idle window again.'
        );
    }

    public function test_login_token_inside_its_half_life_is_left_alone(): void
    {
        config(['neev.login_token_expiry_minutes' => 1440]);

        // 20 hours left of a 24 hour window: no renewal owed yet, which
        // keeps the write off the majority of requests.
        $data = $this->createLoginToken(1200);
        $before = $data['accessToken']->expires_at;

        $this->middleware->handle(
            $this->buildRequest('/api/test', $data['plainTextToken']),
            $this->passThrough()
        );

        $this->assertEquals(
            $before->getTimestamp(),
            $data['accessToken']->fresh()->expires_at->getTimestamp()
        );
    }

    public function test_sliding_is_capped_by_the_absolute_lifetime(): void
    {
        config([
            'neev.login_token_expiry_minutes' => 1440,
            'neev.login_token_max_lifetime_minutes' => 43200,
        ]);

        // Issued 29 days ago, so only a day of absolute lifetime is left:
        // the full idle window would overshoot the ceiling.
        $data = $this->createLoginToken(60, 41760);

        $this->middleware->handle(
            $this->buildRequest('/api/test', $data['plainTextToken']),
            $this->passThrough()
        );

        $this->assertEqualsWithDelta(
            now()->addMinutes(1440)->getTimestamp(),
            $data['accessToken']->fresh()->expires_at->getTimestamp(),
            5,
            'The ceiling is 30 days from issue, which is 1440 minutes away.'
        );
    }

    public function test_a_session_at_its_absolute_ceiling_stops_sliding(): void
    {
        config([
            'neev.login_token_expiry_minutes' => 1440,
            'neev.login_token_max_lifetime_minutes' => 43200,
        ]);

        // Issued 30 days ago: the ceiling has been reached, so the last
        // minutes of the window run out and the user logs in again.
        $data = $this->createLoginToken(30, 43200);
        $before = $data['accessToken']->expires_at;

        $this->middleware->handle(
            $this->buildRequest('/api/test', $data['plainTextToken']),
            $this->passThrough()
        );

        $this->assertEquals(
            $before->getTimestamp(),
            $data['accessToken']->fresh()->expires_at->getTimestamp()
        );
    }

    public function test_zero_max_lifetime_disables_the_ceiling(): void
    {
        config([
            'neev.login_token_expiry_minutes' => 1440,
            'neev.login_token_max_lifetime_minutes' => 0,
        ]);

        $data = $this->createLoginToken(60, 43200 * 2);

        $this->middleware->handle(
            $this->buildRequest('/api/test', $data['plainTextToken']),
            $this->passThrough()
        );

        $this->assertEqualsWithDelta(
            now()->addMinutes(1440)->getTimestamp(),
            $data['accessToken']->fresh()->expires_at->getTimestamp(),
            5
        );
    }

    public function test_api_tokens_never_slide(): void
    {
        config(['neev.login_token_expiry_minutes' => 1440]);

        // A deliberate, long-lived credential keeps the expiry it was
        // issued with, however often it is used.
        $data = $this->createUserWithApiToken([], 60);
        $before = $data['accessToken']->expires_at;

        $this->middleware->handle(
            $this->buildRequest('/api/test', $data['plainTextToken']),
            $this->passThrough()
        );

        $this->assertEquals(
            $before->getTimestamp(),
            $data['accessToken']->fresh()->expires_at->getTimestamp()
        );
    }

    public function test_tokens_issued_without_an_expiry_are_not_given_one(): void
    {
        $user = User::factory()->create();
        $plainText = Str::random(40);
        $token = $user->accessTokens()->create([
            'name' => AccessToken::login,
            'token' => $plainText,
            'token_type' => AccessToken::login,
        ]);

        $this->middleware->handle(
            $this->buildRequest('/api/test', $token->id . '|' . $plainText),
            $this->passThrough()
        );

        $this->assertNull($token->fresh()->expires_at);
    }

    public function test_zero_idle_window_disables_sliding(): void
    {
        config(['neev.login_token_expiry_minutes' => 0]);

        $data = $this->createLoginToken(60);
        $before = $data['accessToken']->expires_at;

        $this->middleware->handle(
            $this->buildRequest('/api/test', $data['plainTextToken']),
            $this->passThrough()
        );

        $this->assertEquals(
            $before->getTimestamp(),
            $data['accessToken']->fresh()->expires_at->getTimestamp()
        );
    }

    public function test_the_new_expiry_is_published_on_the_request(): void
    {
        config(['neev.login_token_expiry_minutes' => 1440]);

        $data = $this->createLoginToken(360);
        $published = null;

        $this->middleware->handle(
            $this->buildRequest('/api/test', $data['plainTextToken']),
            function (Request $req) use (&$published): Response {
                $published = $req->attributes->get('neev.token_expires_at');
                return response()->json(['message' => 'OK'], 200);
            }
        );

        $this->assertInstanceOf(\DateTimeInterface::class, $published);
        $this->assertEqualsWithDelta(now()->addMinutes(1440)->getTimestamp(), $published->getTimestamp(), 5);
    }

    public function test_no_expiry_is_published_when_the_token_did_not_slide(): void
    {
        config(['neev.login_token_expiry_minutes' => 1440]);

        $data = $this->createLoginToken(1200);
        $published = 'unset';

        $this->middleware->handle(
            $this->buildRequest('/api/test', $data['plainTextToken']),
            function (Request $req) use (&$published): Response {
                $published = $req->attributes->get('neev.token_expires_at');
                return response()->json(['message' => 'OK'], 200);
            }
        );

        $this->assertNull($published);
    }

    public function test_expired_token_response_carries_a_machine_readable_code(): void
    {
        $data = $this->createLoginToken(-60);

        $response = $this->middleware->handle(
            $this->buildRequest('/api/test', $data['plainTextToken']),
            $this->passThrough()
        );

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals('token_expired', json_decode($response->getContent(), true)['code']);
    }

    /** The retired `mfa_token` type is not referenced anywhere any more. */
    public function test_the_mfa_token_type_constant_is_gone(): void
    {
        $this->assertFalse(
            defined(AccessToken::class . '::mfa_token'),
            'AccessToken::mfa_token was retired with the path-matching gate.'
        );
    }
}
