<?php

namespace Ssntpl\Neev\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class RoleTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableTeams();
        $this->loadMigrationsFrom(
            dirname(__DIR__, 2) . '/vendor/ssntpl/laravel-acl/database/migrations'
        );
    }

    protected function authenticatedUser(): array
    {
        $user = User::factory()->create();
        $token = $user->createLoginToken(60);

        return [$user, $token->plainTextToken];
    }

    // -----------------------------------------------------------------
    // PUT /neev/role/change — change role via API
    // -----------------------------------------------------------------

    public function test_change_user_role_via_api(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $team = TeamFactory::new()->create(['user_id' => $user->id]);
        $member = User::factory()->create();

        $team->allUsers()->attach($user, ['joined' => true, 'role' => 'admin']);
        $team->allUsers()->attach($member, ['joined' => true, 'role' => 'member']);

        // Create the role
        \Ssntpl\LaravelAcl\Models\Role::create([
            'name' => 'editor',
            'resource_type' => Team::class,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/role/change', [
                'resource_type' => Team::class,
                'resource_id' => $team->id,
                'user_id' => $member->id,
                'role' => 'editor',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonPath('message', 'Role has been changed.');
    }

    public function test_change_role_returns_error_for_missing_resource(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/role/change', [
                'resource_type' => Team::class,
                'resource_id' => 99999,
                'user_id' => $user->id,
                'role' => 'admin',
            ]);

        $response->assertStatus(500)
            ->assertJsonPath('status', 'Failed');
    }

    public function test_change_invitation_role(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $team = TeamFactory::new()->create(['user_id' => $user->id]);

        $invitation = $team->invitations()->create([
            'email' => 'invited@example.com',
            'role' => 'member',
            'expires_at' => now()->addDays(7),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/role/change', [
                'resource_type' => Team::class,
                'resource_id' => $team->id,
                'invitation_id' => $invitation->id,
                'role' => 'admin',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success');

        $invitation->refresh();
        $this->assertEquals('admin', $invitation->role);
    }
}
