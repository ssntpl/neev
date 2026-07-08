<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\User;
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

    public function test_registration_succeeds_with_the_default_password_rules(): void
    {
        // Regression: PasswordHistory::notReused() used to fail with
        // "Unable to verify password history." for first-time signups
        // (no resolvable user), blocking every registration under the
        // shipped default config. This test runs WITHOUT the simplified
        // rules from setUp().
        config(['neev.password' => (require dirname(__DIR__, 3) . '/config/neev.php')['password']]);

        $response = $this->postJson('/neev/register', [
            'name' => 'Default Rules',
            'email' => 'default-rules@company.com',
            'password' => 'Str0ng@Passw0rd!',
            'password_confirmation' => 'Str0ng@Passw0rd!',
        ]);

        $response->assertOk();
        $response->assertJsonPath('auth_state', 'authenticated');
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
        $response->assertJson([
            'auth_state' => 'authenticated',
            'expires_in' => config('neev.login_token_expiry_minutes', 1440),
            'email_verified' => false,
        ]);
        $this->assertNotEmpty($response->json('token'));
        $this->assertDatabaseHas('users', ['email' => 'test@company.com']);
        $this->assertDatabaseHas('users', ['name' => 'Test User']);
    }

    public function test_registration_creates_user_with_email_and_password(): void
    {
        $this->postJson('/neev/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@company.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $this->assertDatabaseHas('users', ['name' => 'Jane Doe']);

        $user = User::where('email', 'jane@company.com')->first();
        $this->assertNotNull($user);

        // Password should be set on the user
        $this->assertNotNull($user->getRawOriginal('password'));
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
        $existingEmail = $user->email;

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

        $user = User::where('email', 'owner@company.com')->first();
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

        $response->assertOk();

        // User should be added to the team
        $user = User::where('email', $inviteeEmail)->first();
        $team->refresh();
        $this->assertTrue($team->users->contains($user));

        // Email should be auto-verified via invitation
        $this->assertNotNull($user->email_verified_at);

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

        $response->assertStatus(400);
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

        $response->assertStatus(400);
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

        $response->assertOk();

        // User should NOT have their own team since they matched a federated domain
        $user = User::where('email', 'user@domainteam.com')->first();
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

        $response->assertOk();

        // User should get their own team
        $user = User::where('email', 'user@unregistered-domain.com')->first();
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

        $response->assertOk();

        $this->assertDatabaseHas('users', [
            'name' => 'Username User',
            'username' => 'testuser123',
        ]);
    }

}
