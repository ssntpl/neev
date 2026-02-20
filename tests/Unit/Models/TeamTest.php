<?php

namespace Ssntpl\Neev\Tests\Unit\Models;

use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Database\Factories\DomainFactory;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Models\Domain;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\TeamInvitation;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class TeamTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    // -----------------------------------------------------------------
    // model() & getClass()
    // -----------------------------------------------------------------

    public function test_model_returns_configured_model_instance(): void
    {
        $instance = Team::model();

        $this->assertInstanceOf(Team::class, $instance);
    }

    public function test_get_class_returns_configured_class_string(): void
    {
        $class = Team::getClass();

        $this->assertSame(Team::class, $class);
    }

    // -----------------------------------------------------------------
    // Slug auto-generation
    // -----------------------------------------------------------------

    public function test_auto_generates_slug_on_creating_if_empty(): void
    {
        $user = User::factory()->create();

        $team = Team::create([
            'user_id' => $user->id,
            'name' => 'My Test Team',
            'activated_at' => now(),
        ]);

        $this->assertNotNull($team->slug);
        $this->assertNotEmpty($team->slug);
    }

    public function test_preserves_explicit_slug_on_creating(): void
    {
        $user = User::factory()->create();

        $team = Team::create([
            'user_id' => $user->id,
            'name' => 'My Test Team',
            'slug' => 'custom-slug',
            'activated_at' => now(),
        ]);

        $this->assertSame('custom-slug', $team->slug);
    }

    // -----------------------------------------------------------------
    // isActive()
    // -----------------------------------------------------------------

    public function test_is_active_returns_true_when_activated_at_set(): void
    {
        $team = TeamFactory::new()->create();

        $this->assertTrue($team->isActive());
    }

    public function test_is_active_returns_false_when_activated_at_null(): void
    {
        $team = TeamFactory::new()->inactive()->create();

        $this->assertFalse($team->isActive());
    }

    // -----------------------------------------------------------------
    // activate() / deactivate()
    // -----------------------------------------------------------------

    public function test_activate_sets_activated_at_and_clears_inactive_reason(): void
    {
        $team = TeamFactory::new()->inactive('Pending review')->create();

        $this->assertFalse($team->isActive());
        $this->assertSame('Pending review', $team->inactive_reason);

        $team->activate();

        $team->refresh();
        $this->assertTrue($team->isActive());
        $this->assertNull($team->inactive_reason);
    }

    public function test_deactivate_clears_activated_at_and_sets_reason(): void
    {
        $team = TeamFactory::new()->create();

        $this->assertTrue($team->isActive());

        $team->deactivate('Policy violation');

        $team->refresh();
        $this->assertFalse($team->isActive());
        $this->assertSame('Policy violation', $team->inactive_reason);
    }

    public function test_deactivate_with_null_reason(): void
    {
        $team = TeamFactory::new()->create();

        $team->deactivate();

        $team->refresh();
        $this->assertFalse($team->isActive());
        $this->assertNull($team->inactive_reason);
    }

    // -----------------------------------------------------------------
    // getSubdomainAttribute()
    // -----------------------------------------------------------------

    public function test_subdomain_returns_null_when_tenant_isolation_disabled(): void
    {
        $team = TeamFactory::new()->create(['slug' => 'acme']);

        $this->assertNull($team->subdomain);
    }

    public function test_subdomain_returns_slug_dot_suffix_when_tenant_isolation_enabled(): void
    {
        $this->enableTenantIsolation('example.com');

        $team = TeamFactory::new()->create(['slug' => 'acme']);

        $this->assertSame('acme.example.com', $team->subdomain);
    }

    public function test_subdomain_strips_leading_dot_from_suffix(): void
    {
        $this->enableTenantIsolation('.example.com');

        $team = TeamFactory::new()->create(['slug' => 'acme']);

        $this->assertSame('acme.example.com', $team->subdomain);
    }

    // -----------------------------------------------------------------
    // getWebDomainAttribute()
    // -----------------------------------------------------------------

    public function test_web_domain_returns_primary_domain_if_verified(): void
    {
        $team = TeamFactory::new()->create();

        DomainFactory::new()->verified()->primary()->create([
            'team_id' => $team->id,
            'domain' => 'custom.example.com',
        ]);

        $this->assertSame('custom.example.com', $team->web_domain);
    }

    public function test_web_domain_returns_subdomain_when_no_verified_primary_domain(): void
    {
        $this->enableTenantIsolation('test.com');

        $team = TeamFactory::new()->create(['slug' => 'acme']);

        // Create an unverified primary domain
        DomainFactory::new()->primary()->create([
            'team_id' => $team->id,
            'domain' => 'custom.example.com',
        ]);

        $this->assertSame('acme.test.com', $team->web_domain);
    }

    public function test_web_domain_returns_subdomain_when_no_domains_exist(): void
    {
        $this->enableTenantIsolation('test.com');

        $team = TeamFactory::new()->create(['slug' => 'acme']);

        $this->assertSame('acme.test.com', $team->web_domain);
    }

    // -----------------------------------------------------------------
    // owner()
    // -----------------------------------------------------------------

    public function test_owner_returns_user(): void
    {
        $user = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $user->id]);

        $owner = $team->owner;

        $this->assertInstanceOf(User::class, $owner);
        $this->assertSame($user->id, $owner->id);
    }

    // -----------------------------------------------------------------
    // users() — only joined members
    // -----------------------------------------------------------------

    public function test_users_only_returns_joined_members(): void
    {
        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $joinedUser = User::factory()->create();
        $notJoinedUser = User::factory()->create();

        // Attach a joined user
        $team->allUsers()->attach($joinedUser->id, [
            'role' => 'member',
            'joined' => true,
            'action' => 'request_to_user',
        ]);

        // Attach a non-joined user
        $team->allUsers()->attach($notJoinedUser->id, [
            'role' => 'member',
            'joined' => false,
            'action' => 'request_to_user',
        ]);

        $this->assertCount(1, $team->users);
        $this->assertTrue($team->users->contains($joinedUser));
        $this->assertFalse($team->users->contains($notJoinedUser));
    }

    // -----------------------------------------------------------------
    // allUsers() — all including non-joined
    // -----------------------------------------------------------------

    public function test_all_users_returns_all_including_non_joined(): void
    {
        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $team->allUsers()->attach($user1->id, [
            'role' => 'member',
            'joined' => true,
            'action' => 'request_to_user',
        ]);

        $team->allUsers()->attach($user2->id, [
            'role' => 'member',
            'joined' => false,
            'action' => 'request_from_user',
        ]);

        $this->assertCount(2, $team->allUsers);
    }

    // -----------------------------------------------------------------
    // joinRequests()
    // -----------------------------------------------------------------

    public function test_join_requests_returns_non_joined_with_request_from_user(): void
    {
        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $requestingUser = User::factory()->create();
        $invitedUser = User::factory()->create();

        $team->allUsers()->attach($requestingUser->id, [
            'role' => 'member',
            'joined' => false,
            'action' => 'request_from_user',
        ]);

        $team->allUsers()->attach($invitedUser->id, [
            'role' => 'member',
            'joined' => false,
            'action' => 'request_to_user',
        ]);

        $joinRequests = $team->joinRequests;

        $this->assertCount(1, $joinRequests);
        $this->assertTrue($joinRequests->contains($requestingUser));
    }

    // -----------------------------------------------------------------
    // invitedUsers()
    // -----------------------------------------------------------------

    public function test_invited_users_returns_non_joined_with_request_to_user(): void
    {
        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $invitedUser = User::factory()->create();
        $requestingUser = User::factory()->create();

        $team->allUsers()->attach($invitedUser->id, [
            'role' => 'member',
            'joined' => false,
            'action' => 'request_to_user',
        ]);

        $team->allUsers()->attach($requestingUser->id, [
            'role' => 'member',
            'joined' => false,
            'action' => 'request_from_user',
        ]);

        $invited = $team->invitedUsers;

        $this->assertCount(1, $invited);
        $this->assertTrue($invited->contains($invitedUser));
    }

    // -----------------------------------------------------------------
    // removeUser()
    // -----------------------------------------------------------------

    public function test_remove_user_detaches_user(): void
    {
        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $member = User::factory()->create();
        $team->allUsers()->attach($member->id, [
            'role' => 'member',
            'joined' => true,
            'action' => 'request_to_user',
        ]);

        $this->assertCount(1, $team->users);

        $team->removeUser($member);

        $team->refresh();
        $this->assertCount(0, $team->users);
    }

    public function test_remove_user_throws_for_owner(): void
    {
        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $this->expectException(Exception::class);

        $team->removeUser($owner);
    }

    // -----------------------------------------------------------------
    // hasUser()
    // -----------------------------------------------------------------

    public function test_has_user_returns_true_for_joined_member(): void
    {
        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $member = User::factory()->create();
        $team->allUsers()->attach($member->id, [
            'role' => 'member',
            'joined' => true,
            'action' => 'request_to_user',
        ]);

        // Load the users relation
        $team->load('users');

        $this->assertTrue($team->hasUser($member));
    }

    public function test_has_user_returns_false_for_non_member(): void
    {
        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        $nonMember = User::factory()->create();

        // Load the users relation
        $team->load('users');

        $this->assertFalse($team->hasUser($nonMember));
    }

    // -----------------------------------------------------------------
    // domains(), primaryDomain(), customDomains(), invitations()
    // -----------------------------------------------------------------

    public function test_domains_returns_has_many_relationship(): void
    {
        $team = TeamFactory::new()->create();

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $team->domains());

        DomainFactory::new()->create(['team_id' => $team->id]);
        DomainFactory::new()->create(['team_id' => $team->id]);

        $team->refresh();

        $this->assertCount(2, $team->domains);
    }

    public function test_primary_domain_returns_primary_domain(): void
    {
        $team = TeamFactory::new()->create();

        DomainFactory::new()->create(['team_id' => $team->id, 'is_primary' => false]);
        DomainFactory::new()->primary()->verified()->create([
            'team_id' => $team->id,
            'domain' => 'primary.example.com',
        ]);

        $primary = $team->primaryDomain;

        $this->assertNotNull($primary);
        $this->assertSame('primary.example.com', $primary->domain);
        $this->assertTrue($primary->is_primary);
    }

    public function test_custom_domains_returns_verified_domains(): void
    {
        $team = TeamFactory::new()->create();

        DomainFactory::new()->verified()->create(['team_id' => $team->id]);
        DomainFactory::new()->verified()->create(['team_id' => $team->id]);
        DomainFactory::new()->create(['team_id' => $team->id]); // unverified

        $this->assertCount(2, $team->customDomains);
    }

    public function test_invitations_returns_has_many_relationship(): void
    {
        $team = TeamFactory::new()->create();

        TeamInvitation::create([
            'team_id' => $team->id,
            'email' => 'invite1@example.com',
            'role' => 'member',
        ]);

        TeamInvitation::create([
            'team_id' => $team->id,
            'email' => 'invite2@example.com',
            'role' => 'admin',
        ]);

        $team->refresh();

        $this->assertCount(2, $team->invitations);
        $this->assertInstanceOf(TeamInvitation::class, $team->invitations->first());
    }

    // -----------------------------------------------------------------
    // ResolvableContextInterface
    // -----------------------------------------------------------------

    public function test_resolve_by_slug_returns_team(): void
    {
        $team = TeamFactory::new()->create(['slug' => 'acme']);

        $resolved = Team::resolveBySlug('acme');

        $this->assertNotNull($resolved);
        $this->assertTrue($resolved->is($team));
    }

    public function test_resolve_by_slug_returns_null_when_not_found(): void
    {
        $this->assertNull(Team::resolveBySlug('nonexistent'));
    }

    public function test_resolve_by_domain_returns_team_for_verified_domain(): void
    {
        $team = TeamFactory::new()->create();

        DomainFactory::new()->verified()->create([
            'team_id' => $team->id,
            'domain' => 'custom.example.com',
        ]);

        $resolved = Team::resolveByDomain('custom.example.com');

        $this->assertNotNull($resolved);
        $this->assertTrue($resolved->is($team));
    }

    public function test_resolve_by_domain_returns_null_for_unverified_domain(): void
    {
        $team = TeamFactory::new()->create();

        DomainFactory::new()->create([
            'team_id' => $team->id,
            'domain' => 'unverified.example.com',
        ]);

        $this->assertNull(Team::resolveByDomain('unverified.example.com'));
    }

    public function test_resolve_by_domain_returns_null_when_not_found(): void
    {
        $this->assertNull(Team::resolveByDomain('nonexistent.com'));
    }
}
