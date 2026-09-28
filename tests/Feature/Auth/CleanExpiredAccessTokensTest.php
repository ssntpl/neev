<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Models\AccessToken;
use Ssntpl\Neev\Models\Tenant;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;

/**
 * `neev:clean-access-tokens` purges every expired token, whatever the config.
 *
 * `NeevAPIMiddleware` deletes an expired token only when it is presented, so
 * one its holder never sends again lingers forever. AccessToken is
 * tenant-scoped, and the command runs from cron with no tenant resolved —
 * where TenantScope narrows queries to `tenant_id IS NULL` — so without
 * dropping the scope every tenant's expired rows would be stranded.
 */
class CleanExpiredAccessTokensTest extends TestCase
{
    use RefreshDatabase;

    private function makeToken(User $user, ?int $tenantId, $expiresAt, string $type = AccessToken::login): AccessToken
    {
        return AccessToken::withoutTenantScope()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenantId,
            'name' => $type,
            'token' => fake()->sha256(),
            'token_type' => $type,
            'expires_at' => $expiresAt,
        ]);
    }

    /** All surviving tokens, ignoring tenant scoping. */
    private function remaining()
    {
        return AccessToken::withoutTenantScope()->get();
    }

    public function test_it_purges_expired_tokens_across_every_tenant(): void
    {
        config(['neev.tenant' => true]);

        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $userA = User::factory()->create(['tenant_id' => $tenantA->id]);
        $userB = User::factory()->create(['tenant_id' => $tenantB->id]);
        $platformUser = User::factory()->create();

        $this->makeToken($userA, $tenantA->id, now()->subDay());
        $this->makeToken($userB, $tenantB->id, now()->subMinute(), AccessToken::api_token);
        $this->makeToken($platformUser, null, now()->subDay());

        // Runs with no resolved tenant, exactly as it does from cron.
        $this->artisan('neev:clean-access-tokens')
            ->expectsOutputToContain('Deleted 3 expired access token(s).')
            ->assertSuccessful();

        $this->assertCount(0, $this->remaining(), 'Expired is expired — every tenant included.');
    }

    public function test_it_keeps_unexpired_and_non_expiring_tokens(): void
    {
        $user = User::factory()->create();

        $expired = $this->makeToken($user, null, now()->subDay());
        $live = $this->makeToken($user, null, now()->addHour());
        $forever = $this->makeToken($user, null, null, AccessToken::api_token);

        $this->artisan('neev:clean-access-tokens')
            ->expectsOutputToContain('Deleted 1 expired access token(s).')
            ->assertSuccessful();

        $left = $this->remaining()->pluck('id')->all();

        $this->assertNotContains($expired->id, $left);
        $this->assertEqualsCanonicalizing(
            [$live->id, $forever->id],
            $left,
            'A token that has not expired, or never expires, must survive.'
        );
    }

    public function test_it_reports_nothing_to_delete_on_a_clean_table(): void
    {
        $this->artisan('neev:clean-access-tokens')
            ->expectsOutputToContain('Deleted 0 expired access token(s).')
            ->assertSuccessful();
    }
}
