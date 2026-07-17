<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Models\MagicLinkToken;
use Ssntpl\Neev\Models\Tenant;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;

/**
 * `neev:clean-magic-links` purges every expired token, whatever the config.
 *
 * MagicLinkToken is tenant-scoped, and the command runs from cron with no
 * tenant resolved — where TenantScope narrows queries to `tenant_id IS NULL`.
 * Left unchecked that strands every tenant's expired rows permanently, while
 * still reporting success.
 */
class CleanExpiredMagicLinksTest extends TestCase
{
    use RefreshDatabase;

    private function makeToken(User $user, string $plain, ?int $tenantId, $expiresAt): MagicLinkToken
    {
        return MagicLinkToken::withoutTenantScope()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenantId,
            'token' => MagicLinkToken::hashToken($plain),
            'channel' => 'web',
            'expires_at' => $expiresAt,
        ]);
    }

    /** All surviving tokens, ignoring tenant scoping. */
    private function remaining()
    {
        return MagicLinkToken::withoutTenantScope()->get();
    }

    public function test_it_purges_expired_tokens_across_every_tenant(): void
    {
        config(['neev.tenant' => true]);

        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $userA = User::factory()->create(['tenant_id' => $tenantA->id]);
        $userB = User::factory()->create(['tenant_id' => $tenantB->id]);
        $platformUser = User::factory()->create();

        $this->makeToken($userA, 'tenant-a-expired', $tenantA->id, now()->subDay());
        $this->makeToken($userB, 'tenant-b-expired', $tenantB->id, now()->subMinute());
        $this->makeToken($platformUser, 'platform-expired', null, now()->subDay());

        // Runs with no resolved tenant, exactly as it does from cron.
        $this->artisan('neev:clean-magic-links')
            ->expectsOutputToContain('Deleted 3 expired magic-link token(s).')
            ->assertSuccessful();

        $this->assertCount(0, $this->remaining(), 'Expired is expired — every tenant included.');
    }

    public function test_it_keeps_unexpired_tokens(): void
    {
        config(['neev.tenant' => true]);

        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->makeToken($user, 'tenant-expired', $tenant->id, now()->subDay());
        $this->makeToken($user, 'tenant-live', $tenant->id, now()->addHour());

        $this->artisan('neev:clean-magic-links')->assertSuccessful();

        $left = $this->remaining();

        $this->assertCount(1, $left);
        $this->assertSame(
            MagicLinkToken::hashToken('tenant-live'),
            $left->first()->token,
            'Only the unexpired token should survive.'
        );
    }

    public function test_it_purges_expired_tokens_with_tenancy_disabled(): void
    {
        config(['neev.tenant' => false]);

        $user = User::factory()->create();

        $this->makeToken($user, 'expired', null, now()->subDay());
        $this->makeToken($user, 'live', null, now()->addHour());

        $this->artisan('neev:clean-magic-links')
            ->expectsOutputToContain('Deleted 1 expired magic-link token(s).')
            ->assertSuccessful();

        $this->assertCount(1, $this->remaining());
    }

    public function test_it_reports_nothing_to_delete_on_a_clean_table(): void
    {
        $this->artisan('neev:clean-magic-links')
            ->expectsOutputToContain('Deleted 0 expired magic-link token(s).')
            ->assertSuccessful();
    }
}
