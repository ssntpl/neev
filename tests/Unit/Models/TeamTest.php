<?php

namespace Ssntpl\Neev\Tests\Unit\Models;

use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ssntpl\Neev\Database\Factories\EmailDomainFactory;
use Ssntpl\Neev\Database\Factories\HostnameFactory;
use Ssntpl\LaravelAcl\Models\Role;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Database\Factories\TenantFactory;
use Ssntpl\Neev\Exceptions\SlugUnavailableException;
use Ssntpl\Neev\Models\Membership;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\Tenant;
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

    public function test_subdomain_returns_null_when_tenant_isolation_enabled(): void
    {
        $this->enableTenantIsolation();

        $team = TeamFactory::new()->create(['slug' => 'acme']);

        // Subdomain suffix concept removed; always null now
        $this->assertNull($team->subdomain);
    }

    // -----------------------------------------------------------------
    // getWebDomainAttribute()
    // -----------------------------------------------------------------

    public function test_web_domain_returns_primary_hostname_if_verified(): void
    {
        $team = TeamFactory::new()->create();

        $this->verifiedHost($team, 'custom.example.com', primary: true);

        $this->assertSame('custom.example.com', $team->web_domain);
    }

    public function test_web_domain_returns_null_when_no_verified_primary_hostname(): void
    {
        $team = TeamFactory::new()->create(['slug' => 'acme']);

        // Point the primary at a claim not yet proven
        $hostname = $team->claimHost('custom.example.com');
        $team->forceFill(['primary_hostname_id' => $hostname->id])->save();

        $this->assertNull($team->web_domain);
    }

    public function test_web_domain_returns_null_when_no_domains_exist(): void
    {
        $team = TeamFactory::new()->create(['slug' => 'acme']);

        $this->assertNull($team->web_domain);
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
            'joined' => true,
            'action' => 'request_to_user',
        ]);

        // Attach a non-joined user
        $team->allUsers()->attach($notJoinedUser->id, [
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
            'joined' => true,
            'action' => 'request_to_user',
        ]);

        $team->allUsers()->attach($user2->id, [
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
            'joined' => false,
            'action' => 'request_from_user',
        ]);

        $team->allUsers()->attach($invitedUser->id, [
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
            'joined' => false,
            'action' => 'request_to_user',
        ]);

        $team->allUsers()->attach($requestingUser->id, [
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
            'joined' => true,
            'action' => 'request_to_user',
        ]);

        $this->assertCount(1, $team->users);

        $team->removeUser($member);

        $team->refresh();
        $this->assertCount(0, $team->users);
    }

    public function test_remove_user_also_removes_the_team_role(): void
    {
        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        Role::create(['name' => 'editor', 'resource_type' => Team::class]);

        $member = User::factory()->create();
        $team->addMember($member, 'editor');
        $this->assertTrue($member->hasRole('editor', $team));

        $team->removeUser($member);

        // Left behind, the role would come back if the user rejoined.
        $this->assertFalse($member->fresh()->hasRole('editor', $team));
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

    /**
     * A `domains` row as an install upgraded from before RFC 006 holds it. The
     * Domain model is read-only, so rows are written past it.
     */
    private function insertDomain(Team $team, array $attributes = []): void
    {
        DB::table('domains')->insert($attributes + [
            'owner_type' => 'team',
            'owner_id' => $team->id,
            'domain' => fake()->unique()->domainName(),
            'is_primary' => false,
            'enforce' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_domains_returns_morph_many_relationship(): void
    {
        $team = TeamFactory::new()->create();

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\MorphMany::class, $team->domains());

        $this->insertDomain($team);
        $this->insertDomain($team);

        $team->refresh();

        $this->assertCount(2, $team->domains);
    }

    public function test_primary_domain_and_domain_return_the_primary_domain(): void
    {
        $team = TeamFactory::new()->create();

        $this->insertDomain($team);
        $this->insertDomain($team, [
            'domain' => 'primary.example.com',
            'is_primary' => true,
            'verified_at' => now(),
        ]);

        $primary = $team->primaryDomain;

        $this->assertNotNull($primary);
        $this->assertSame('primary.example.com', $primary->domain);
        $this->assertTrue($primary->is_primary);
        $this->assertTrue($primary->is($team->domain));
    }

    public function test_custom_domains_returns_verified_domains(): void
    {
        $team = TeamFactory::new()->create();

        $this->insertDomain($team, ['verified_at' => now()]);
        $this->insertDomain($team, ['verified_at' => now()]);
        $this->insertDomain($team); // unverified

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

    public function test_resolve_by_domain_returns_team_for_verified_hostname(): void
    {
        $team = TeamFactory::new()->create();

        HostnameFactory::new()->forOwner($team)->verified()->create(['host' => 'custom.example.com']);

        $resolved = Team::resolveByDomain('custom.example.com');

        $this->assertNotNull($resolved);
        $this->assertTrue($resolved->is($team));
    }

    public function test_resolve_by_domain_returns_null_for_unverified_hostname(): void
    {
        $team = TeamFactory::new()->create();

        HostnameFactory::new()->forOwner($team)->create(['host' => 'unverified.example.com']);

        $this->assertNull(Team::resolveByDomain('unverified.example.com'));
    }

    public function test_resolve_by_domain_returns_null_when_not_found(): void
    {
        $this->assertNull(Team::resolveByDomain('nonexistent.com'));
    }

    public function test_resolve_by_domain_ignores_a_tenant_owned_host(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);

        HostnameFactory::new()->forOwner($tenant)->verified()->create(['host' => 'acme.example.com']);

        // Returning the Tenant here would break the ?static contract.
        $this->assertNull(Team::resolveByDomain('acme.example.com'));
    }

    /**
     * An email domain says who has addresses there, not where a team is
     * served, so it does not resolve the team.
     */
    public function test_resolve_by_domain_ignores_a_verified_email_domain(): void
    {
        $team = TeamFactory::new()->create();

        EmailDomainFactory::new()->forOwner($team)->verified()->create(['domain' => 'acme.example.com']);

        $this->assertNull(Team::resolveByDomain('acme.example.com'));
    }

    // -----------------------------------------------------------------
    // addMember()
    // -----------------------------------------------------------------

    public function test_add_member_records_a_joined_membership_by_default(): void
    {
        $team = TeamFactory::new()->create();
        $user = User::factory()->create();

        $team->addMember($user);

        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $user->id,
            'joined' => true,
            'action' => Membership::REQUEST_TO_USER,
        ]);
        $this->assertTrue($team->hasMember($user));
    }

    /**
     * A pending row is the same attach with `joined` off — it is what the
     * invitation and join-request flows write, and `hasMember()` must not
     * count it.
     */
    public function test_add_member_can_record_a_pending_invitation(): void
    {
        $team = TeamFactory::new()->create();
        $user = User::factory()->create();

        $team->addMember($user, null, joined: false);

        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $user->id,
            'joined' => false,
            'action' => Membership::REQUEST_TO_USER,
        ]);
        $this->assertFalse($team->hasMember($user));
        $this->assertTrue($team->invitedUsers()->where('users.id', $user->id)->exists());
    }

    /**
     * The same pending row with the other `action` is a request from the
     * user, and lands in `joinRequests` rather than `invitedUsers`.
     */
    public function test_add_member_can_record_a_pending_join_request(): void
    {
        $team = TeamFactory::new()->create();
        $user = User::factory()->create();

        $team->addMember($user, null, joined: false, action: Membership::REQUEST_FROM_USER);

        $this->assertTrue($team->joinRequests()->where('users.id', $user->id)->exists());
        $this->assertFalse($team->invitedUsers()->where('users.id', $user->id)->exists());
    }

    public function test_add_member_is_idempotent(): void
    {
        $team = TeamFactory::new()->create();
        $user = User::factory()->create();

        $team->addMember($user);
        $team->addMember($user, null, joined: false);

        $this->assertDatabaseCount('team_user', 1);
        // The first call wins; the second does not downgrade the membership.
        $this->assertTrue($team->hasMember($user));
    }

    // -----------------------------------------------------------------
    // Uniqueness of a team name
    // -----------------------------------------------------------------

    /**
     * A team is identified by its slug, not its name, so one owner may hold
     * two teams of the same name, in one tenant or across tenants.
     */
    public function test_an_owner_may_repeat_a_team_name(): void
    {
        $owner = User::factory()->create();
        $tenant = TenantFactory::new()->create();

        TeamFactory::new()->create([
            'user_id' => $owner->id, 'name' => 'Acme', 'tenant_id' => $tenant->id,
        ]);
        TeamFactory::new()->create([
            'user_id' => $owner->id, 'name' => 'Acme', 'tenant_id' => $tenant->id,
        ]);

        $this->assertDatabaseCount('teams', 2);
    }

    /**
     * In shared mode a team is host-resolvable, so its slug is unique across
     * the installation (RFC 006 §6 Q5). The index is per tenant, so this is
     * the save that refuses it, not the database.
     */
    public function test_a_slug_is_unique_across_every_tenant_in_shared_mode(): void
    {
        $one = TenantFactory::new()->create();
        $two = TenantFactory::new()->create();

        TeamFactory::new()->create(['slug' => 'shared', 'tenant_id' => $one->id]);

        $this->expectException(SlugUnavailableException::class);

        TeamFactory::new()->create(['slug' => 'shared', 'tenant_id' => $two->id]);
    }

    public function test_an_address_on_an_unverified_domain_is_held_but_not_verified(): void
    {
        $team = TeamFactory::new()->create();
        EmailDomainFactory::new()->forOwner($team)->create(['domain' => 'acme.com']);
        EmailDomainFactory::new()->forOwner($team)->verified()->create(['domain' => 'acme.io']);
        // A host the team is served at is not an email domain.
        $this->verifiedHost($team, 'acme.net');

        $this->assertTrue($team->holdsDomainFor('Alice@ACME.com'));
        $this->assertFalse($team->hasVerifiedDomainFor('Alice@ACME.com'));
        $this->assertTrue($team->hasVerifiedDomainFor('bob@acme.io'));
        $this->assertFalse($team->holdsDomainFor('carol@other.com'));
        // The suffix is the whole domain, not any tail of it.
        $this->assertFalse($team->holdsDomainFor('dave@notacme.com'));
        $this->assertFalse($team->holdsDomainFor('erin@acme.net'));
        $this->assertFalse($team->hasVerifiedDomainFor('erin@acme.net'));
    }

    /**
     * Only claims by teams the member belongs to hold their account back; a
     * claim by a team they are not in has no say over it.
     */
    public function test_reactivate_members_on_skips_a_member_another_of_their_teams_claims(): void
    {
        $team = TeamFactory::new()->create();
        $other = TeamFactory::new()->create();
        $stranger = TeamFactory::new()->create();
        EmailDomainFactory::new()->forOwner($team)->create(['domain' => 'acme.com']);
        EmailDomainFactory::new()->forOwner($other)->verified()->create(['domain' => 'acme.com']);
        EmailDomainFactory::new()->forOwner($stranger)->create(['domain' => 'acme.com']);

        $shared = User::factory()->create(['active' => true, 'email' => 'bob@acme.com']);
        $team->addMember($shared);
        $other->addMember($shared);
        $shared->deactivate();

        $only = User::factory()->create(['active' => true, 'email' => 'alice@ACME.com']);
        $team->addMember($only);
        $only->deactivate();

        $team->reactivateMembersOn('acme.com');

        $this->assertFalse($shared->fresh()->active, 'Another of their teams claims the host.');
        $this->assertTrue($only->fresh()->active, 'A claim by a team they are not in does not count.');
    }

    /**
     * Deleting a team deletes its email domains, and with them the only thing
     * that could reactivate the members they deactivated.
     */
    public function test_deleting_a_team_reactivates_the_members_its_email_domains_deactivated(): void
    {
        $team = TeamFactory::new()->create();
        $other = TeamFactory::new()->create();
        EmailDomainFactory::new()->forOwner($team)->verified()->create(['domain' => 'acme.com']);
        EmailDomainFactory::new()->forOwner($other)->verified()->create(['domain' => 'acme.com']);

        $member = User::factory()->create(['active' => true, 'email' => 'alice@acme.com']);
        $team->addMember($member);
        $member->deactivate();

        $shared = User::factory()->create(['active' => true, 'email' => 'bob@acme.com']);
        $team->addMember($shared);
        $other->addMember($shared);
        $shared->deactivate();

        $team->delete();

        $this->assertTrue($member->fresh()->active);
        $this->assertFalse($shared->fresh()->active, 'Another of their teams still claims the domain.');
        $this->assertSame(0, $team->emailDomains()->count());
    }

}
