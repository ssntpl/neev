<?php

namespace Ssntpl\Neev\Tests\Unit\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\LaravelAcl\Models\Role;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class MemberCommandsTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    protected User $owner;

    protected Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->team = $this->makeTeam($this->owner);
    }

    protected function makeTeam(User $owner, ?int $tenantId = null): Team
    {
        $team = new Team();
        $team->name = 'Acme Team';
        $team->slug = 'acme-team-' . uniqid();
        $team->user_id = $owner->id;
        $team->tenant_id = $tenantId;
        $team->save();

        return $team;
    }

    // -----------------------------------------------------------------
    // neev:member:add — an unknown role rolls the membership back
    // -----------------------------------------------------------------

    public function test_add_member_rolls_back_the_membership_when_the_role_is_unknown(): void
    {
        $member = User::factory()->create();

        $this->artisan('neev:member:add', [
            'email' => $member->email,
            '--team' => $this->team->slug,
            '--role' => 'no-such-role',
        ])->assertFailed();

        $this->assertDatabaseMissing('team_user', [
            'team_id' => $this->team->id,
            'user_id' => $member->id,
        ]);
    }

    public function test_add_member_attaches_the_member_with_a_known_role(): void
    {
        $member = User::factory()->create();

        Role::create(['name' => 'editor', 'resource_type' => Team::class]);

        $this->artisan('neev:member:add', [
            'email' => $member->email,
            '--team' => $this->team->slug,
            '--role' => 'editor',
        ])->assertSuccessful();

        $this->assertDatabaseHas('team_user', [
            'team_id' => $this->team->id,
            'user_id' => $member->id,
        ]);
    }

    // -----------------------------------------------------------------
    // neev:member:add — tenant boundary
    // -----------------------------------------------------------------

    public function test_add_member_refuses_a_user_from_another_tenant(): void
    {
        $this->enableTenantIsolation();

        $member = User::factory()->create();
        $member->tenant_id = 99;
        $member->save();

        $team = $this->makeTeam($this->owner, tenantId: 1);

        $this->artisan('neev:member:add', [
            'email' => $member->email,
            '--team' => $team->slug,
        ])->assertFailed();

        $this->assertDatabaseMissing('team_user', [
            'team_id' => $team->id,
            'user_id' => $member->id,
        ]);
    }

    // -----------------------------------------------------------------
    // neev:member:remove — the team-scoped role goes with the membership
    // -----------------------------------------------------------------

    public function test_remove_member_also_removes_the_team_role(): void
    {
        $member = User::factory()->create();
        Role::create(['name' => 'editor', 'resource_type' => Team::class]);

        $this->team->addMember($member, 'editor');
        $this->assertTrue($member->hasRole('editor', $this->team));

        $this->artisan('neev:member:remove', [
            'email' => $member->email,
            '--team' => $this->team->slug,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertDatabaseMissing('team_user', [
            'team_id' => $this->team->id,
            'user_id' => $member->id,
        ]);
        $this->assertFalse($member->fresh()->hasRole('editor', $this->team));
    }
}
