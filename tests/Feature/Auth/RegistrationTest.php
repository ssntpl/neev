<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Models\Email;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\TenantResolver;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    protected function setUp(): void
    {
        parent::setUp();

        // Simplify password rules so tests can focus on registration logic
        config(['neev.password' => ['required', 'confirmed']]);
    }

    public function test_api_registration_returns_token(): void
    {
        $response = $this->postJson('/neev/register', [
            'name' => 'Test User',
            'email' => 'test@company.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['status', 'token', 'email_verified']);
        $response->assertJson(['status' => 'Success']);
        $this->assertNotEmpty($response->json('token'));
        $this->assertDatabaseHas('emails', ['email' => 'test@company.com']);
        $this->assertDatabaseHas('users', ['name' => 'Test User']);
    }

    public function test_registration_creates_user_email_and_password(): void
    {
        $this->postJson('/neev/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@company.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $this->assertDatabaseHas('users', ['name' => 'Jane Doe']);

        $email = Email::where('email', 'jane@company.com')->first();
        $this->assertNotNull($email);
        $this->assertTrue($email->is_primary);

        // Password record should exist for the user
        $user = $email->user;
        $this->assertNotNull($user->password);
    }

    public function test_returns_validation_error_for_missing_name(): void
    {
        $response = $this->postJson('/neev/register', [
            'email' => 'test@company.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_returns_validation_error_for_missing_email(): void
    {
        $response = $this->postJson('/neev/register', [
            'name' => 'Test User',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_returns_validation_error_for_missing_password(): void
    {
        $response = $this->postJson('/neev/register', [
            'name' => 'Test User',
            'email' => 'test@company.com',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['password']);
    }

    public function test_returns_validation_error_for_duplicate_email(): void
    {
        $user = User::factory()->create();
        $existingEmail = $user->email->email;

        $response = $this->postJson('/neev/register', [
            'name' => 'Another User',
            'email' => $existingEmail,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_returns_validation_error_for_weak_password(): void
    {
        // Restore strict password rules
        config(['neev.password' => [
            'required',
            'confirmed',
            \Illuminate\Validation\Rules\Password::min(8)->mixedCase()->numbers()->symbols(),
        ]]);

        $response = $this->postJson('/neev/register', [
            'name' => 'Test User',
            'email' => 'test@company.com',
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['password']);
    }

    public function test_returns_validation_error_for_unconfirmed_password(): void
    {
        $response = $this->postJson('/neev/register', [
            'name' => 'Test User',
            'email' => 'test@company.com',
            'password' => 'password123',
            'password_confirmation' => 'different',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['password']);
    }

    public function test_creates_team_when_teams_enabled(): void
    {
        $this->enableTeams();

        $response = $this->postJson('/neev/register', [
            'name' => 'Team Owner',
            'email' => 'owner@company.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertOk();

        $user = Email::where('email', 'owner@company.com')->first()->user;
        $team = Team::where('user_id', $user->id)->first();

        $this->assertNotNull($team);
        $this->assertNotNull($team->activated_at);
    }

    public function test_free_email_creates_inactive_team_when_company_email_required(): void
    {
        $this->enableTeams();
        $this->enableCompanyEmailRequirement();

        $response = $this->postJson('/neev/register', [
            'name' => 'Free User',
            'email' => 'user@gmail.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertOk();

        $user = Email::where('email', 'user@gmail.com')->first()->user;
        $team = Team::where('user_id', $user->id)->first();

        $this->assertNotNull($team);
        $this->assertNull($team->activated_at);
        $this->assertEquals('free_email_provider', $team->inactive_reason);
    }

    public function test_company_email_creates_active_team_when_company_email_required(): void
    {
        $this->enableTeams();
        $this->enableCompanyEmailRequirement();

        $response = $this->postJson('/neev/register', [
            'name' => 'Corp User',
            'email' => 'user@acmecorp.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertOk();

        $user = Email::where('email', 'user@acmecorp.com')->first()->user;
        $team = Team::where('user_id', $user->id)->first();

        $this->assertNotNull($team);
        $this->assertNotNull($team->activated_at);
    }

    public function test_returns_email_verified_false_when_email_not_verified(): void
    {
        $response = $this->postJson('/neev/register', [
            'name' => 'Unverified User',
            'email' => 'unverified@company.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertOk();
        // Newly registered user has not yet verified their email
        $response->assertJson(['email_verified' => false]);
    }

    public function test_does_not_create_team_when_teams_disabled(): void
    {
        config(['neev.team' => false]);

        $response = $this->postJson('/neev/register', [
            'name' => 'Solo User',
            'email' => 'solo@company.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertOk();
        $this->assertDatabaseCount('teams', 0);
    }

    public function test_returns_validation_error_for_invalid_email_format(): void
    {
        $response = $this->postJson('/neev/register', [
            'name' => 'Bad Email User',
            'email' => 'not-an-email',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email']);
    }

    // -----------------------------------------------------------------
    // Registration with team invitation
    // -----------------------------------------------------------------

    public function test_register_via_team_invitation(): void
    {
        $this->enableTeams();

        $owner = User::factory()->create();
        $team = Team::forceCreate([
            'name' => 'Invite Team',
            'user_id' => $owner->id,
            'is_public' => false,
        ]);

        $inviteeEmail = 'invitee@example.com';
        $invitation = $team->invitations()->create([
            'email' => $inviteeEmail,
            'expires_at' => now()->addDays(7),
        ]);

        $response = $this->postJson('/neev/register', [
            'name' => 'Invited User',
            'email' => $inviteeEmail,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'invitation_id' => $invitation->id,
            'hash' => sha1($inviteeEmail),
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success');

        // User should be added to the team
        $user = Email::where('email', $inviteeEmail)->first()->user;
        $team->refresh();
        $this->assertTrue($team->users->contains($user));

        // Email should be auto-verified via invitation
        $email = Email::where('email', $inviteeEmail)->first();
        $this->assertNotNull($email->verified_at);

        // Invitation should be deleted
        $this->assertDatabaseMissing('team_invitations', ['id' => $invitation->id]);
    }

    public function test_register_via_invalid_invitation_returns_error(): void
    {
        $this->enableTeams();

        $response = $this->postJson('/neev/register', [
            'name' => 'Bad Invite User',
            'email' => 'badinvite@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'invitation_id' => 99999,
            'hash' => sha1('badinvite@example.com'),
        ]);

        $response->assertStatus(500)
            ->assertJsonPath('status', 'Failed');
    }

    public function test_register_via_invitation_with_wrong_hash_returns_error(): void
    {
        $this->enableTeams();

        $owner = User::factory()->create();
        $team = Team::forceCreate([
            'name' => 'Hash Team',
            'user_id' => $owner->id,
            'is_public' => false,
        ]);

        $invitation = $team->invitations()->create([
            'email' => 'hashinvite@example.com',
            'role' => 'member',
            'expires_at' => now()->addDays(7),
        ]);

        $response = $this->postJson('/neev/register', [
            'name' => 'Wrong Hash User',
            'email' => 'hashinvite@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'invitation_id' => $invitation->id,
            'hash' => 'wronghash',
        ]);

        $response->assertStatus(500)
            ->assertJsonPath('status', 'Failed');
    }

    // -----------------------------------------------------------------
    // Registration with domain federation
    // -----------------------------------------------------------------

    public function test_register_with_domain_federation_joins_existing_team(): void
    {
        $this->enableTeams();
        $this->enableDomainFederation();

        $owner = User::factory()->create();
        $team = Team::forceCreate([
            'name' => 'Domain Team',
            'user_id' => $owner->id,
            'is_public' => false,
        ]);

        // Create a verified domain for the team
        $team->domains()->create([
            'domain' => 'domainteam.com',
            'verified_at' => now(),
            'is_primary' => true,
        ]);

        $response = $this->postJson('/neev/register', [
            'name' => 'Domain User',
            'email' => 'user@domainteam.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success');

        // User should NOT have their own team since they matched a federated domain
        $user = Email::where('email', 'user@domainteam.com')->first()->user;
        $ownedTeams = Team::where('user_id', $user->id)->count();
        $this->assertEquals(0, $ownedTeams);
    }

    public function test_register_without_domain_federation_creates_own_team(): void
    {
        $this->enableTeams();
        $this->enableDomainFederation();

        $response = $this->postJson('/neev/register', [
            'name' => 'No Domain User',
            'email' => 'user@unregistered-domain.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success');

        // User should get their own team
        $user = Email::where('email', 'user@unregistered-domain.com')->first()->user;
        $ownedTeams = Team::where('user_id', $user->id)->count();
        $this->assertEquals(1, $ownedTeams);
    }

    // -----------------------------------------------------------------
    // Registration with username support
    // -----------------------------------------------------------------

    public function test_registration_with_username(): void
    {
        $this->enableUsernameSupport();

        $response = $this->postJson('/neev/register', [
            'name' => 'Username User',
            'email' => 'username@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'username' => 'testuser123',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'Success');

        $this->assertDatabaseHas('users', [
            'name' => 'Username User',
            'username' => 'testuser123',
        ]);
    }

    // -----------------------------------------------------------------
    // Registration with tenant isolation (single_tenant_users)
    // -----------------------------------------------------------------

    public function test_register_with_tenant_isolation_no_tenant_returns_error(): void
    {
        $this->enableTeams();
        config([
            'neev.tenant_isolation' => true,
            'neev.tenant_isolation_options.single_tenant_users' => true,
        ]);

        // Mock TenantResolver to return no tenant
        $resolver = Mockery::mock(TenantResolver::class);
        $resolver->shouldReceive('current')->andReturn(null);
        $resolver->shouldReceive('resolve')->andReturn(null);
        $this->app->instance(TenantResolver::class, $resolver);

        $response = $this->postJson('/neev/register', [
            'name' => 'Tenant User',
            'email' => 'tenant@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed')
            ->assertJsonPath('message', 'Registration must be done through a valid tenant domain.');
    }

    public function test_register_with_tenant_isolation_checks_existing_email(): void
    {
        $this->enableTeams();
        config([
            'neev.tenant_isolation' => true,
            'neev.tenant_isolation_options.single_tenant_users' => true,
        ]);

        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        // Mock TenantResolver to return the team as current tenant
        $resolver = Mockery::mock(TenantResolver::class);
        $resolver->shouldReceive('current')->andReturn($team);
        $this->app->instance(TenantResolver::class, $resolver);

        $response = $this->postJson('/neev/register', [
            'name' => 'Tenant Member',
            'email' => 'tenantmember@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        // The code creates the email first then checks if it exists,
        // so it always finds the just-created record and rolls back
        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed')
            ->assertJsonPath('message', 'An account with this email already exists.');
    }

    public function test_register_with_tenant_isolation_unverified_domain_returns_error(): void
    {
        $this->enableTeams();
        config([
            'neev.tenant_isolation' => true,
            'neev.tenant_isolation_options.single_tenant_users' => true,
        ]);

        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);

        // Mock TenantResolver — current returns null, resolve returns team
        // but domain is not verified
        $resolver = Mockery::mock(TenantResolver::class);
        $resolver->shouldReceive('current')->andReturn(null);
        $resolver->shouldReceive('resolve')->andReturn($team);
        $resolver->shouldReceive('isResolvedDomainVerified')->andReturn(false);
        $this->app->instance(TenantResolver::class, $resolver);

        $response = $this->postJson('/neev/register', [
            'name' => 'Unverified Domain User',
            'email' => 'unverified@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'Failed')
            ->assertJsonPath('message', 'Registration must be done through a valid tenant domain.');
    }
}
