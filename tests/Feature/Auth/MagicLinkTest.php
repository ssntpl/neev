<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Ssntpl\Neev\Events\MagicLinkConsumed;
use Ssntpl\Neev\Events\MagicLinkGenerated;
use Ssntpl\Neev\Events\MagicLinkRejected;
use Ssntpl\Neev\Exceptions\MagicLinkBindingException;
use Ssntpl\Neev\Mail\LoginUsingLink;
use Ssntpl\Neev\Models\MagicLinkToken;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\MagicLink\MagicLinkManager;
use Ssntpl\Neev\Support\MagicLink\MagicLinkResult;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class MagicLinkTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    private function createUser(array $state = []): User
    {
        return User::factory()->create($state);
    }

    /**
     * Generate a magic link for the user and return its plain token.
     */
    private function magicLinkToken(User $user): array
    {
        return app(MagicLinkManager::class)->forWeb($user);
    }

    // -----------------------------------------------------------------
    // POST /neev/sendLoginLink
    // -----------------------------------------------------------------

    public function test_send_login_link_dispatches_mail_and_returns_success(): void
    {
        Mail::fake();

        $user = $this->createUser();

        $response = $this->postJson('/neev/sendLoginLink', [
            'email' => $user->email,
        ]);

        $response->assertOk();
        $response->assertJson([
            'message' => 'Login link has been sent.',
        ]);

        Mail::assertSent(LoginUsingLink::class, function (LoginUsingLink $mail) use ($user) {
            return $mail->hasTo($user->email);
        });
    }

    public function test_send_login_link_returns_401_for_non_existent_email(): void
    {
        Mail::fake();

        $response = $this->postJson('/neev/sendLoginLink', [
            'email' => 'nobody@example.com',
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'message' => 'Credentials are wrong.',
        ]);

        Mail::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // GET /neev/loginUsingLink  (open — must never consume by default)
    // -----------------------------------------------------------------

    public function test_get_returns_confirmation_required_without_consuming_the_token(): void
    {
        $user = $this->createUser();

        $link = $this->magicLinkToken($user);

        $response = $this->getJson('/neev/loginUsingLink?token=' . $link['token']);

        $response->assertOk();
        $response->assertJson([
            'auth_state' => 'confirmation_required',
            'channel' => 'web',
        ]);
        $response->assertJsonMissingPath('token');

        // A mail-gateway prefetch must leave the link redeemable.
        $this->assertDatabaseCount('magic_link_tokens', 1);
        $this->postJson('/neev/loginUsingLink', ['token' => $link['token']])->assertOk();
    }

    public function test_get_consumes_the_token_when_confirmation_is_disabled(): void
    {
        config(['neev.magic_link.require_confirmation' => false]);

        $user = $this->createUser();

        $link = $this->magicLinkToken($user);

        $response = $this->getJson('/neev/loginUsingLink?token=' . $link['token']);

        $response->assertOk();
        $response->assertJson(['auth_state' => 'authenticated']);
        $this->assertDatabaseCount('magic_link_tokens', 0);
    }

    // -----------------------------------------------------------------
    // POST /neev/loginUsingLink  (confirm)
    // -----------------------------------------------------------------

    public function test_login_using_link_with_valid_token_returns_token(): void
    {
        $user = $this->createUser();

        $link = $this->magicLinkToken($user);

        $response = $this->postJson('/neev/loginUsingLink', ['token' => $link['token']]);

        $response->assertOk();
        $response->assertJson([
            'auth_state' => 'authenticated',
            'expires_in' => config('neev.login_token_expiry_minutes', 1440),
            'email_verified' => true,
        ]);
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_login_using_link_returns_email_verified_status(): void
    {
        $user = $this->createUser();

        // Email is verified by default from factory
        $link = $this->magicLinkToken($user);

        $response = $this->postJson('/neev/loginUsingLink', ['token' => $link['token']]);

        $response->assertOk();
        $response->assertJson([
            'auth_state' => 'authenticated',
            'email_verified' => true,
        ]);
    }

    public function test_login_using_link_with_invalid_token_returns_403(): void
    {
        $this->createUser();

        $response = $this->postJson('/neev/loginUsingLink', ['token' => 'not-a-real-token']);

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'Invalid or expired verification link.',
        ]);
    }

    public function test_login_using_link_with_a_non_scalar_token_is_rejected_not_fatal(): void
    {
        $this->createUser();

        // `token` is client-controlled: an array must be refused like any other
        // bad token, not raise "Array to string conversion".
        $response = $this->postJson('/neev/loginUsingLink', ['token' => ['a', 'b']]);

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'Invalid or expired verification link.',
        ]);
    }

    public function test_validate_with_a_non_scalar_token_is_rejected_not_fatal(): void
    {
        $response = $this->postJson('/neev/loginUsingLink/validate', ['token' => ['a', 'b']]);

        $response->assertOk();
        $response->assertJson([
            'status' => MagicLinkResult::INVALID,
            'valid' => false,
        ]);
    }

    public function test_login_using_link_without_a_token_returns_403(): void
    {
        $this->createUser();

        $this->postJson('/neev/loginUsingLink', [])->assertStatus(403);
    }

    public function test_login_using_link_with_expired_token_returns_403(): void
    {
        $user = $this->createUser();

        $link = $this->magicLinkToken($user);
        $link['model']->forceFill(['expires_at' => now()->subMinute()])->save();

        $response = $this->postJson('/neev/loginUsingLink', ['token' => $link['token']]);

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'Invalid or expired verification link.',
        ]);
    }

    public function test_login_using_link_is_single_use(): void
    {
        $user = $this->createUser();

        $link = $this->magicLinkToken($user);

        $this->postJson('/neev/loginUsingLink', ['token' => $link['token']])->assertOk();

        // Replay: the token row was deleted on consumption.
        $this->postJson('/neev/loginUsingLink', ['token' => $link['token']])->assertStatus(403);
    }

    public function test_generating_a_new_link_invalidates_the_previous_one(): void
    {
        $user = $this->createUser();

        $old = $this->magicLinkToken($user);
        $new = $this->magicLinkToken($user);

        $this->postJson('/neev/loginUsingLink', ['token' => $old['token']])->assertStatus(403);
        $this->postJson('/neev/loginUsingLink', ['token' => $new['token']])->assertOk();
    }

    public function test_login_using_link_creates_login_attempt(): void
    {
        $user = $this->createUser();

        $link = $this->magicLinkToken($user);

        $this->postJson('/neev/loginUsingLink', ['token' => $link['token']]);

        $this->assertDatabaseHas('login_attempts', [
            'user_id' => $user->id,
            'method' => 'magic auth',
            'is_success' => true,
        ]);
    }

    public function test_login_using_link_creates_access_token(): void
    {
        $user = $this->createUser();

        $link = $this->magicLinkToken($user);

        $this->postJson('/neev/loginUsingLink', ['token' => $link['token']]);

        $this->assertDatabaseHas('access_tokens', [
            'user_id' => $user->id,
            'token_type' => 'login',
        ]);
    }

    public function test_login_using_link_for_inactive_user_returns_validation_error(): void
    {
        $user = $this->createUser(['active' => false]);

        $link = $this->magicLinkToken($user);

        $response = $this->postJson('/neev/loginUsingLink', ['token' => $link['token']]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email']);
    }

    // -----------------------------------------------------------------
    // GET|POST /neev/loginUsingLink/validate
    // -----------------------------------------------------------------

    public function test_validate_reports_pending_confirmation_without_consuming(): void
    {
        $user = $this->createUser();

        $link = $this->magicLinkToken($user);

        $response = $this->getJson('/neev/loginUsingLink/validate?token=' . $link['token']);

        $response->assertOk();
        $response->assertJson([
            'status' => MagicLinkResult::PENDING_CONFIRMATION,
            'valid' => false,
            'requires_confirmation' => true,
            'channel' => 'web',
            'email_verified' => true,
        ]);

        $this->assertDatabaseCount('magic_link_tokens', 1);
    }

    public function test_validate_reports_an_unknown_token_as_invalid(): void
    {
        $response = $this->getJson('/neev/loginUsingLink/validate?token=not-a-real-token');

        $response->assertOk();
        $response->assertJson([
            'status' => MagicLinkResult::INVALID,
            'valid' => false,
            'requires_confirmation' => false,
        ]);
    }

    // -----------------------------------------------------------------
    // Browser binding
    // -----------------------------------------------------------------

    public function test_binding_enabled_refuses_to_generate_without_a_binding_source(): void
    {
        config(['neev.magic_link.bind_to_browser' => true]);

        $user = $this->createUser();

        // A session-less API request with no X-Device-Id has nothing to bind to.
        // Minting here would produce a link that could never be redeemed.
        $this->expectException(MagicLinkBindingException::class);

        app(MagicLinkManager::class)->forWeb($user, ['request' => Request::create('/neev/sendLoginLink', 'POST')]);
    }

    public function test_refusing_to_generate_leaves_the_existing_link_intact(): void
    {
        $user = $this->createUser();
        $existing = $this->magicLinkToken($user);

        config(['neev.magic_link.bind_to_browser' => true]);

        try {
            app(MagicLinkManager::class)->forWeb($user, ['request' => Request::create('/neev/sendLoginLink', 'POST')]);
            $this->fail('Expected generation to fail without a binding source.');
        } catch (MagicLinkBindingException) {
            // Expected.
        }

        config(['neev.magic_link.bind_to_browser' => false]);

        // The user's existing link must survive a failed generation attempt.
        $this->postJson('/neev/loginUsingLink', ['token' => $existing['token']])->assertOk();
    }

    public function test_bound_link_is_redeemable_by_the_same_device(): void
    {
        config(['neev.magic_link.bind_to_browser' => true]);

        $user = $this->createUser();

        $request = Request::create('/neev/sendLoginLink', 'POST');
        $request->headers->set('X-Device-Id', 'device-abc');
        $link = app(MagicLinkManager::class)->forWeb($user, ['request' => $request]);

        $this->withHeader('X-Device-Id', 'device-abc')
            ->postJson('/neev/loginUsingLink', ['token' => $link['token']])
            ->assertOk();
    }

    public function test_bound_link_is_rejected_from_a_different_device(): void
    {
        config(['neev.magic_link.bind_to_browser' => true]);

        $user = $this->createUser();

        $request = Request::create('/neev/sendLoginLink', 'POST');
        $request->headers->set('X-Device-Id', 'device-abc');
        $link = app(MagicLinkManager::class)->forWeb($user, ['request' => $request]);

        $this->withHeader('X-Device-Id', 'device-xyz')
            ->postJson('/neev/loginUsingLink', ['token' => $link['token']])
            ->assertStatus(403);
    }

    public function test_api_send_without_a_binding_source_fails_cleanly_not_with_a_500(): void
    {
        config(['neev.magic_link.bind_to_browser' => true]);

        $user = $this->createUser();

        $this->postJson('/neev/sendLoginLink', ['email' => $user->email])
            ->assertStatus(422)
            ->assertJson(['message' => 'Unable to send a login link for this request.']);
    }

    // -----------------------------------------------------------------
    // Channel validation
    // -----------------------------------------------------------------

    /**
     * The shipped 'mobile' channel has scheme/universal_link unset by default.
     * Falling through to the web branch would mail a browser URL in answer to a
     * mobile request — a 200, a valid token, and a login that opens Safari.
     */
    public function test_unconfigured_deep_link_channel_is_rejected(): void
    {
        Mail::fake();
        config([
            'neev.magic_link.channels.mobile.scheme' => null,
            'neev.magic_link.channels.mobile.universal_link' => null,
        ]);

        $user = User::factory()->create();

        $response = $this->postJson('/neev/sendLoginLink', [
            'email' => $user->email,
            'channel' => 'mobile',
        ]);

        $response->assertStatus(422);
        Mail::assertNothingSent();
    }

    public function test_configured_deep_link_channel_builds_a_deep_link(): void
    {
        Mail::fake();
        config(['neev.magic_link.channels.mobile.scheme' => 'myapp://login']);

        $user = User::factory()->create();

        $this->postJson('/neev/sendLoginLink', [
            'email' => $user->email,
            'channel' => 'mobile',
        ])->assertOk();

        Mail::assertSent(LoginUsingLink::class, function (LoginUsingLink $mail) {
            return str_starts_with($mail->url, 'myapp://login?')
                && str_contains($mail->url, 'token=');
        });
    }

    /**
     * A typo must not quietly mint a web token in response to a mobile request.
     */
    public function test_unknown_channel_is_rejected_rather_than_downgraded_to_web(): void
    {
        Mail::fake();

        $user = User::factory()->create();

        $response = $this->postJson('/neev/sendLoginLink', [
            'email' => $user->email,
            'channel' => 'mobil',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('magic_link_tokens', 0);
        Mail::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // Event payloads must survive a queue and carry no secrets
    // -----------------------------------------------------------------

    /**
     * Single-use is enforced by deleting the token row, so an event that
     * carried the model would restore it with firstOrFail() on the worker and
     * throw ModelNotFoundException for every link redeemed before the queue
     * drained.
     */
    public function test_generated_event_survives_the_token_row_being_deleted(): void
    {
        $user = User::factory()->create();
        $token = MagicLinkToken::create([
            'user_id' => $user->id,
            'token' => MagicLinkToken::hashToken('probe-token'),
            'channel' => 'web',
            'expires_at' => now()->addMinutes(10),
        ]);

        $payload = serialize(MagicLinkGenerated::fromToken($user, $token));

        MagicLinkToken::query()->whereKey($token->getKey())->delete();

        $restored = unserialize($payload);

        $this->assertSame($token->getKey(), $restored->tokenId);
        $this->assertSame('web', $restored->channel);
        $this->assertSame($user->id, $restored->user->id);
    }

    /**
     * Queue payloads land in Redis, `jobs` and `failed_jobs` — the last retained
     * indefinitely and routinely copied into backups. None of them may carry the
     * account's password hash or the stored token hash.
     */
    public function test_event_payloads_carry_no_password_or_token_hash(): void
    {
        $user = User::factory()->create(['password' => bcrypt('SentinelPass123!')]);
        $passwordHash = $user->fresh()->password;

        $token = MagicLinkToken::create([
            'user_id' => $user->id,
            'token' => MagicLinkToken::hashToken('probe-token'),
            'channel' => 'web',
            'expires_at' => now()->addMinutes(10),
        ]);
        $tokenHash = $token->token;

        $result = MagicLinkResult::valid($user, 'web', $token);

        $payloads = [
            'generated' => serialize(MagicLinkGenerated::fromToken($user, $token)),
            'consumed' => serialize(MagicLinkConsumed::fromResult($user, $result)),
            'rejected' => serialize(MagicLinkRejected::fromResult(
                MagicLinkResult::failure(MagicLinkResult::BINDING_MISMATCH, 'web', $token, $user)
            )),
        ];

        foreach ($payloads as $name => $payload) {
            $this->assertStringNotContainsString($passwordHash, $payload, "{$name} leaks the password hash");
            $this->assertStringNotContainsString($tokenHash, $payload, "{$name} leaks the stored token hash");
        }
    }

    public function test_rejected_event_reports_status_and_survives_a_missing_user(): void
    {
        $event = MagicLinkRejected::fromResult(
            MagicLinkResult::failure(MagicLinkResult::INVALID)
        );

        $restored = unserialize(serialize($event));

        $this->assertSame(MagicLinkResult::INVALID, $restored->status);
        $this->assertNull($restored->user);
        $this->assertNull($restored->tokenId);
    }

    // -----------------------------------------------------------------
    // Replacement is serialized on the rows it replaces
    // -----------------------------------------------------------------

    /**
     * The delete-then-insert that replaces a user's link must be serialized, or
     * two concurrent sends both delete before either inserts and the user ends
     * up with two live links for one channel.
     *
     * The lock has to be taken on the token rows. Locking the user row looked
     * equivalent but User carries TenantScope: generation with no resolved
     * tenant (CLI, queued jobs) searched `tenant_id is null`, matched nothing
     * for a tenant's user, and locked no row at all — silently, because the
     * result was discarded.
     */
    public function test_replacement_locks_the_token_rows_it_is_about_to_delete(): void
    {
        $user = User::factory()->create();

        $queries = [];
        DB::listen(function ($event) use (&$queries) {
            $queries[] = $event->sql;
        });

        app(MagicLinkManager::class)->forWeb($user);

        $lock = null;
        $delete = null;
        foreach ($queries as $position => $sql) {
            if ($lock === null
                && str_starts_with($sql, 'select')
                && str_contains($sql, 'magic_link_tokens')
                && str_contains($sql, 'user_id')
                && str_contains($sql, 'channel')) {
                $lock = $position;
            }
            if ($delete === null && str_starts_with($sql, 'delete')) {
                $delete = $position;
            }
        }

        $this->assertNotNull($lock, 'Replacement must take a lock on the token rows.');
        $this->assertNotNull($delete, 'Replacement must delete the previous link.');
        $this->assertLessThan($delete, $lock, 'The lock must precede the delete.');

        // A select against users here would be the tenant-scoped lock that
        // silently matched nothing.
        foreach ($queries as $sql) {
            $this->assertStringNotContainsString(
                'from "users"',
                $sql,
                'The lock must not be taken on the tenant-scoped users table.'
            );
        }
    }

    public function test_generating_twice_leaves_exactly_one_live_link(): void
    {
        $user = User::factory()->create();
        $manager = app(MagicLinkManager::class);

        $first = $manager->forWeb($user);
        $second = $manager->forWeb($user);

        $this->assertDatabaseCount('magic_link_tokens', 1);
        $this->assertNull(MagicLinkToken::findByToken($first['token']));
        $this->assertNotNull(MagicLinkToken::findByToken($second['token']));
    }
}
