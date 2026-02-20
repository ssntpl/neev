<?php

namespace Ssntpl\Neev\Tests\Unit\Services;

use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Mockery;
use Ssntpl\Neev\Database\Factories\TeamAuthSettingsFactory;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Models\Email;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\TenantSSOManager;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class TenantSSOManagerTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    private TenantSSOManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new TenantSSOManager();
    }

    /**
     * Create a mock SocialiteUser with the given email and name.
     */
    private function mockSocialiteUser(string $email, ?string $name = null): SocialiteUser
    {
        $mock = Mockery::mock(SocialiteUser::class);
        $mock->shouldReceive('getEmail')->andReturn($email);
        $mock->shouldReceive('getName')->andReturn($name);
        $mock->shouldReceive('getId')->andReturn('sso-id-123');
        $mock->shouldReceive('getAvatar')->andReturn(null);
        $mock->shouldReceive('getNickname')->andReturn(null);

        return $mock;
    }

    // ---------------------------------------------------------------
    // isTenantAuthEnabled()
    // ---------------------------------------------------------------

    public function test_is_tenant_auth_enabled_returns_false_when_both_disabled(): void
    {
        config([
            'neev.tenant_auth' => false,
            'neev.tenant_isolation' => false,
        ]);

        $this->assertFalse($this->manager->isTenantAuthEnabled());
    }

    public function test_is_tenant_auth_enabled_returns_true_when_only_tenant_auth_enabled(): void
    {
        config([
            'neev.tenant_auth' => true,
            'neev.tenant_isolation' => false,
        ]);

        $this->assertTrue($this->manager->isTenantAuthEnabled());
    }

    public function test_is_tenant_auth_enabled_returns_false_when_only_tenant_isolation_enabled(): void
    {
        config([
            'neev.tenant_auth' => false,
            'neev.tenant_isolation' => true,
        ]);

        $this->assertFalse($this->manager->isTenantAuthEnabled());
    }

    public function test_is_tenant_auth_enabled_returns_true_when_both_enabled(): void
    {
        $this->enableTenantAuth();

        $this->assertTrue($this->manager->isTenantAuthEnabled());
    }

    // ---------------------------------------------------------------
    // getProvider()
    // ---------------------------------------------------------------

    public function test_get_provider_returns_null_when_tenant_auth_disabled(): void
    {
        config([
            'neev.tenant_auth' => false,
            'neev.tenant_isolation' => false,
        ]);

        $team = TeamFactory::new()->create();
        TeamAuthSettingsFactory::new()->sso('entra')->create(['team_id' => $team->id]);

        $this->assertNull($this->manager->getProvider($team));
    }

    public function test_get_provider_returns_null_when_no_auth_settings(): void
    {
        $this->enableTenantAuth();

        $team = TeamFactory::new()->create();

        $this->assertNull($this->manager->getProvider($team));
    }

    public function test_get_provider_returns_provider_from_auth_settings(): void
    {
        $this->enableTenantAuth();

        $team = TeamFactory::new()->create();
        TeamAuthSettingsFactory::new()->sso('google')->create(['team_id' => $team->id]);

        $this->assertSame('google', $this->manager->getProvider($team));
    }

    public function test_get_provider_returns_entra_provider(): void
    {
        $this->enableTenantAuth();

        $team = TeamFactory::new()->create();
        TeamAuthSettingsFactory::new()->sso('entra')->create(['team_id' => $team->id]);

        $this->assertSame('entra', $this->manager->getProvider($team));
    }

    // ---------------------------------------------------------------
    // buildSocialiteDriver()
    // ---------------------------------------------------------------

    public function test_build_socialite_driver_throws_when_no_auth_settings(): void
    {
        $team = TeamFactory::new()->create();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('SSO is not configured for this tenant.');

        $this->manager->buildSocialiteDriver($team);
    }

    public function test_build_socialite_driver_throws_when_sso_not_configured(): void
    {
        $team = TeamFactory::new()->create();
        // Password auth settings -- SSO fields are not populated
        TeamAuthSettingsFactory::new()->create(['team_id' => $team->id]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('SSO is not configured for this tenant.');

        $this->manager->buildSocialiteDriver($team);
    }

    public function test_build_socialite_driver_throws_when_provider_not_supported(): void
    {
        $team = TeamFactory::new()->create();
        TeamAuthSettingsFactory::new()->sso('entra')->create(['team_id' => $team->id]);

        // Empty supported providers list
        config(['neev.tenant_auth_options.sso_providers' => []]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("SSO provider 'entra' is not supported.");

        $this->manager->buildSocialiteDriver($team);
    }

    // ---------------------------------------------------------------
    // findOrCreateUser()
    // ---------------------------------------------------------------

    public function test_find_or_create_user_finds_existing_user_by_email(): void
    {
        $this->enableTenantAuth();

        $team = TeamFactory::new()->create();
        $user = User::factory()->create();
        $email = $user->emails()->first();

        $ssoUser = $this->mockSocialiteUser($email->email, 'SSO Name');

        $found = $this->manager->findOrCreateUser($team, $ssoUser);

        $this->assertEquals($user->id, $found->id);
    }

    public function test_find_or_create_user_throws_when_email_empty(): void
    {
        $this->enableTenantAuth();

        $team = TeamFactory::new()->create();
        $ssoUser = $this->mockSocialiteUser('', 'No Email User');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('SSO provider did not return an email address.');

        $this->manager->findOrCreateUser($team, $ssoUser);
    }

    public function test_find_or_create_user_creates_new_user_when_auto_provision_enabled(): void
    {
        $this->enableTenantAuth();

        $team = TeamFactory::new()->create();
        TeamAuthSettingsFactory::new()
            ->sso('entra')
            ->autoProvision('member')
            ->create(['team_id' => $team->id]);

        $ssoUser = $this->mockSocialiteUser('newuser@company.com', 'New User');

        $countBefore = User::count();

        $created = $this->manager->findOrCreateUser($team, $ssoUser);

        $this->assertSame($countBefore + 1, User::count());
        $this->assertSame('New User', $created->name);

        // Verify email record was created
        $this->assertDatabaseHas('emails', [
            'user_id' => $created->id,
            'email' => 'newuser@company.com',
            'is_primary' => true,
        ]);

        // Verify email is pre-verified (SSO emails)
        $emailRecord = Email::where('email', 'newuser@company.com')->first();
        $this->assertNotNull($emailRecord->verified_at);

        // Verify password was created
        $this->assertDatabaseHas('passwords', [
            'user_id' => $created->id,
        ]);
    }

    public function test_find_or_create_user_uses_extracted_name_when_sso_name_null(): void
    {
        $this->enableTenantAuth();

        $team = TeamFactory::new()->create();
        TeamAuthSettingsFactory::new()
            ->sso('entra')
            ->autoProvision()
            ->create(['team_id' => $team->id]);

        $ssoUser = $this->mockSocialiteUser('john.doe@company.com', null);

        $created = $this->manager->findOrCreateUser($team, $ssoUser);

        // extractNameFromEmail('john.doe@company.com') should produce 'John Doe'
        $this->assertSame('John Doe', $created->name);
    }

    public function test_find_or_create_user_throws_when_not_found_and_auto_provision_disabled(): void
    {
        $this->enableTenantAuth();

        $team = TeamFactory::new()->create();
        // No auto_provision -- default is false
        TeamAuthSettingsFactory::new()->sso('entra')->create(['team_id' => $team->id]);

        $ssoUser = $this->mockSocialiteUser('unknown@company.com', 'Unknown');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('You are not a member of this organization.');

        $this->manager->findOrCreateUser($team, $ssoUser);
    }

    // ---------------------------------------------------------------
    // ensureMembership()
    // ---------------------------------------------------------------

    public function test_ensure_membership_does_nothing_when_user_already_member(): void
    {
        $this->enableTenantAuth();

        $team = TeamFactory::new()->create();
        $user = User::factory()->create();

        // Add the user to the team
        $team->users()->attach($user, ['joined' => true, 'role' => 'member']);

        // Should not throw and should not create a second membership
        $this->manager->ensureMembership($user, $team);

        $this->assertSame(1, $team->users()->where('users.id', $user->id)->count());
    }

    public function test_ensure_membership_adds_user_when_auto_provision_enabled(): void
    {
        $this->enableTenantAuth();

        $team = TeamFactory::new()->create();
        // Use auto_provision without a role to avoid acl_roles table dependency
        TeamAuthSettingsFactory::new()
            ->sso('entra')
            ->create([
                'team_id' => $team->id,
                'auto_provision' => true,
                'auto_provision_role' => null,
            ]);

        $user = User::factory()->create();

        $this->assertFalse($user->belongsToTeam($team));

        $this->manager->ensureMembership($user, $team);

        $user->refresh();
        $this->assertTrue($user->belongsToTeam($team));
    }

    public function test_ensure_membership_throws_when_not_member_and_auto_provision_disabled(): void
    {
        $this->enableTenantAuth();

        $team = TeamFactory::new()->create();
        TeamAuthSettingsFactory::new()->sso('entra')->create(['team_id' => $team->id]);

        $user = User::factory()->create();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('You are not a member of this organization.');

        $this->manager->ensureMembership($user, $team);
    }

    public function test_ensure_membership_assigns_configured_role(): void
    {
        // Load ACL migrations required for assignRole
        $this->loadMigrationsFrom(
            dirname(__DIR__, 3) . '/vendor/ssntpl/laravel-acl/database/migrations'
        );

        $this->enableTenantAuth();

        $team = TeamFactory::new()->create();
        TeamAuthSettingsFactory::new()
            ->sso('entra')
            ->autoProvision('editor')
            ->create(['team_id' => $team->id]);

        // Create the ACL role so assignRole can find it
        \Ssntpl\LaravelAcl\Models\Role::create([
            'name' => 'editor',
            'resource_type' => Team::class,
        ]);

        $user = User::factory()->create();

        $this->manager->ensureMembership($user, $team);

        // Verify the membership has the correct role in the pivot
        $pivot = $team->users()->where('users.id', $user->id)->first();
        $this->assertNotNull($pivot);
        $this->assertSame('editor', $pivot->membership->role);
    }

    // ---------------------------------------------------------------
    // extractNameFromEmail() -- tested via findOrCreateUser
    // ---------------------------------------------------------------

    public function test_extract_name_from_email_handles_dots(): void
    {
        $this->enableTenantAuth();

        $team = TeamFactory::new()->create();
        TeamAuthSettingsFactory::new()
            ->sso('entra')
            ->autoProvision()
            ->create(['team_id' => $team->id]);

        $ssoUser = $this->mockSocialiteUser('jane.smith@company.com', null);

        $created = $this->manager->findOrCreateUser($team, $ssoUser);

        $this->assertSame('Jane Smith', $created->name);
    }

    public function test_extract_name_from_email_handles_underscores(): void
    {
        $this->enableTenantAuth();

        $team = TeamFactory::new()->create();
        TeamAuthSettingsFactory::new()
            ->sso('entra')
            ->autoProvision()
            ->create(['team_id' => $team->id]);

        $ssoUser = $this->mockSocialiteUser('bob_jones@company.com', null);

        $created = $this->manager->findOrCreateUser($team, $ssoUser);

        $this->assertSame('Bob Jones', $created->name);
    }

    public function test_extract_name_from_email_handles_hyphens(): void
    {
        $this->enableTenantAuth();

        $team = TeamFactory::new()->create();
        TeamAuthSettingsFactory::new()
            ->sso('entra')
            ->autoProvision()
            ->create(['team_id' => $team->id]);

        $ssoUser = $this->mockSocialiteUser('mary-jane@company.com', null);

        $created = $this->manager->findOrCreateUser($team, $ssoUser);

        $this->assertSame('Mary Jane', $created->name);
    }

    public function test_extract_name_from_email_handles_single_word(): void
    {
        $this->enableTenantAuth();

        $team = TeamFactory::new()->create();
        TeamAuthSettingsFactory::new()
            ->sso('entra')
            ->autoProvision()
            ->create(['team_id' => $team->id]);

        $ssoUser = $this->mockSocialiteUser('admin@company.com', null);

        $created = $this->manager->findOrCreateUser($team, $ssoUser);

        $this->assertSame('Admin', $created->name);
    }

    // ---------------------------------------------------------------
    // getProviderClass() — tested via reflection
    // ---------------------------------------------------------------

    public function test_get_provider_class_returns_correct_class_for_azure(): void
    {
        $method = new \ReflectionMethod(TenantSSOManager::class, 'getProviderClass');
        $method->setAccessible(true);

        $result = $method->invoke($this->manager, 'azure');
        $this->assertSame(\SocialiteProviders\Azure\Provider::class, $result);
    }

    public function test_get_provider_class_returns_correct_class_for_google(): void
    {
        $method = new \ReflectionMethod(TenantSSOManager::class, 'getProviderClass');
        $method->setAccessible(true);

        $result = $method->invoke($this->manager, 'google');
        $this->assertSame(\Laravel\Socialite\Two\GoogleProvider::class, $result);
    }

    public function test_get_provider_class_throws_for_unknown_provider(): void
    {
        $method = new \ReflectionMethod(TenantSSOManager::class, 'getProviderClass');
        $method->setAccessible(true);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Provider class not found for driver: unknown');

        $method->invoke($this->manager, 'unknown');
    }
}
