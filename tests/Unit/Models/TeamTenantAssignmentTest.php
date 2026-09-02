<?php

namespace Ssntpl\Neev\Tests\Unit\Models;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\Tenant;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\TenantResolver;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;
use Ssntpl\Neev\Tests\Traits\WithTenantContext;

/**
 * Teams sit inside a tenant, but Team deliberately does not use the
 * BelongsToTenant trait — its global scope would break tenant resolution,
 * which resolves Teams. The tenant_id that trait would have stamped on is
 * therefore assigned in Team::booted() instead, and only in isolated mode:
 * in shared mode the resolved context is itself a Team, which must never
 * become another team's parent.
 */
class TeamTenantAssignmentTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;
    use WithTenantContext;

    protected function makeTeam(array $attributes = []): Team
    {
        $user = User::factory()->create();

        return Team::create($attributes + [
            'name' => 'Team ' . uniqid(),
            'user_id' => $user->id,
        ]);
    }

    public function test_a_new_team_inherits_the_resolved_tenant(): void
    {
        $this->enableTenantIsolation();
        $this->setUpTenantContext();

        $team = $this->makeTeam();

        $this->assertSame($this->testTenant->id, $team->tenant_id);
    }

    public function test_an_explicit_tenant_id_is_not_overwritten(): void
    {
        $this->enableTenantIsolation();
        $this->setUpTenantContext();

        $other = Tenant::create(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        $user = User::factory()->create();

        // tenant_id is not fillable, so a caller that wants a specific parent
        // sets it on the instance before saving.
        $team = new Team(['name' => 'Explicit', 'user_id' => $user->id]);
        $team->tenant_id = $other->id;
        $team->save();

        $this->assertSame($other->id, $team->tenant_id);
    }

    public function test_no_tenant_is_stamped_on_when_nothing_is_resolved(): void
    {
        $team = $this->makeTeam();

        $this->assertNull($team->tenant_id);
    }

    public function test_a_resolved_team_never_becomes_another_teams_parent(): void
    {
        // Shared mode: the resolved context is a Team, not a Tenant.
        $this->enableTeams();

        $context = $this->makeTeam(['name' => 'Context Team']);
        app(TenantResolver::class)->setCurrentTenant($context);

        $team = $this->makeTeam();

        $this->assertNull($team->tenant_id);
        $this->assertNotSame($context->id, $team->tenant_id);
    }
}
