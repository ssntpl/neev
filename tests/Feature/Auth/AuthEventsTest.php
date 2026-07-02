<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Ssntpl\Neev\Events\EmailVerified;
use Ssntpl\Neev\Events\MemberAdded;
use Ssntpl\Neev\Events\MemberRemoved;
use Ssntpl\Neev\Events\MfaMethodAdded;
use Ssntpl\Neev\Events\MfaMethodRemoved;
use Ssntpl\Neev\Events\PasswordChanged;
use Ssntpl\Neev\Events\RecoveryCodesGenerated;
use Ssntpl\Neev\Events\TeamCreated;
use Ssntpl\Neev\Events\TeamDeleted;
use Ssntpl\Neev\Events\TenantCreated;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\Tenant;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\AuthService;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class AuthEventsTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    protected function setUp(): void
    {
        parent::setUp();

        // Simplify password rules so tests can focus on event dispatch
        config(['neev.password' => ['required', 'confirmed']]);
    }

    // -----------------------------------------------------------------
    // Registered
    // -----------------------------------------------------------------

    public function test_api_registration_fires_registered_event(): void
    {
        Event::fake([Registered::class]);

        $this->postJson('/neev/register', [
            'name' => 'Test User',
            'email' => 'events@example.com',
            'password' => 'Str0ng@Password',
            'password_confirmation' => 'Str0ng@Password',
        ]);

        Event::assertDispatched(Registered::class, function (Registered $event) {
            return $event->user->email === 'events@example.com';
        });
    }

    public function test_api_registration_with_teams_fires_team_created_and_member_added(): void
    {
        $this->enableTeams();
        Event::fake([TeamCreated::class, MemberAdded::class]);

        $this->postJson('/neev/register', [
            'name' => 'Test User',
            'email' => 'teamevents@example.com',
            'password' => 'Str0ng@Password',
            'password_confirmation' => 'Str0ng@Password',
        ]);

        Event::assertDispatched(TeamCreated::class);
        Event::assertDispatched(MemberAdded::class, function (MemberAdded $event) {
            return $event->user->email === 'teamevents@example.com';
        });
    }

    // -----------------------------------------------------------------
    // PasswordChanged / PasswordReset
    // -----------------------------------------------------------------

    public function test_change_password_fires_password_changed_event(): void
    {
        Event::fake([PasswordChanged::class]);

        $user = User::factory()->create();
        app(AuthService::class)->changePassword($user, 'N3w@Password123');

        Event::assertDispatched(PasswordChanged::class, function (PasswordChanged $event) use ($user) {
            return $event->user->id === $user->id;
        });
    }

    public function test_api_password_reset_fires_password_reset_event(): void
    {
        Event::fake([PasswordReset::class]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $url = URL::temporarySignedRoute('neev.resetPassword', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => hash('sha256', $user->email),
        ]);

        $this->postJson($url, [
            'password' => 'N3w@Password123',
            'password_confirmation' => 'N3w@Password123',
        ])->assertOk();

        Event::assertDispatched(PasswordReset::class, function (PasswordReset $event) use ($user) {
            return $event->user->id === $user->id;
        });
    }

    // -----------------------------------------------------------------
    // EmailVerified
    // -----------------------------------------------------------------

    public function test_mark_email_as_verified_fires_email_verified_event_once(): void
    {
        Event::fake([EmailVerified::class]);

        $user = User::factory()->create(['email_verified_at' => null]);
        $user->markEmailAsVerified();

        Event::assertDispatched(EmailVerified::class, 1);
    }

    public function test_mark_email_as_verified_does_not_fire_when_already_verified(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        Event::fake([EmailVerified::class]);
        $user->markEmailAsVerified();

        Event::assertNotDispatched(EmailVerified::class);
    }

    // -----------------------------------------------------------------
    // MFA events
    // -----------------------------------------------------------------

    public function test_adding_mfa_method_fires_mfa_method_added(): void
    {
        Event::fake([MfaMethodAdded::class]);

        $user = User::factory()->create();
        $user->addMultiFactorAuth('email');

        Event::assertDispatched(MfaMethodAdded::class, function (MfaMethodAdded $event) use ($user) {
            return $event->user->id === $user->id && $event->method === 'email';
        });
    }

    public function test_adding_existing_mfa_method_does_not_fire_event_again(): void
    {
        $user = User::factory()->create();
        $user->addMultiFactorAuth('email');
        $user->load('multiFactorAuths');

        Event::fake([MfaMethodAdded::class]);
        $user->addMultiFactorAuth('email');

        Event::assertNotDispatched(MfaMethodAdded::class);
    }

    public function test_removing_mfa_method_fires_mfa_method_removed(): void
    {
        $user = User::factory()->create();
        $user->addMultiFactorAuth('email');
        $user->load('multiFactorAuths');

        Event::fake([MfaMethodRemoved::class]);
        $result = $user->removeMultiFactorAuth('email');

        $this->assertTrue($result);
        Event::assertDispatched(MfaMethodRemoved::class, function (MfaMethodRemoved $event) use ($user) {
            return $event->user->id === $user->id && $event->method === 'email';
        });
    }

    public function test_removing_unconfigured_mfa_method_returns_false_without_event(): void
    {
        Event::fake([MfaMethodRemoved::class]);

        $user = User::factory()->create();
        $this->assertFalse($user->removeMultiFactorAuth('email'));

        Event::assertNotDispatched(MfaMethodRemoved::class);
    }

    public function test_removing_last_mfa_method_deletes_recovery_codes(): void
    {
        $user = User::factory()->create();
        $user->addMultiFactorAuth('email');
        $user->load('multiFactorAuths');
        $user->generateRecoveryCodes();
        $this->assertGreaterThan(0, $user->recoveryCodes()->count());

        $user->removeMultiFactorAuth('email');

        $this->assertSame(0, $user->recoveryCodes()->count());
    }

    public function test_removing_preferred_method_reassigns_preferred_flag(): void
    {
        $user = User::factory()->create();
        $user->addMultiFactorAuth('email');
        $user->load('multiFactorAuths');
        $user->addMultiFactorAuth('authenticator');
        $user->load('multiFactorAuths');

        $preferred = $user->preferredMultiFactorAuth()->first();
        $user->removeMultiFactorAuth($preferred->method);

        $user->load('multiFactorAuths');
        $this->assertNotNull($user->preferredMultiFactorAuth()->first());
    }

    public function test_generating_recovery_codes_fires_event(): void
    {
        Event::fake([RecoveryCodesGenerated::class]);

        $user = User::factory()->create();
        $user->addMultiFactorAuth('email');
        $user->generateRecoveryCodes();

        Event::assertDispatched(RecoveryCodesGenerated::class);
    }

    // -----------------------------------------------------------------
    // Team / Tenant lifecycle events
    // -----------------------------------------------------------------

    public function test_team_creation_and_deletion_fire_events(): void
    {
        Event::fake([TeamCreated::class, TeamDeleted::class]);

        $owner = User::factory()->create();
        $team = Team::model()->forceCreate([
            'name' => 'Events Team',
            'user_id' => $owner->id,
            'is_public' => false,
            'activated_at' => now(),
        ]);
        $team->delete();

        Event::assertDispatched(TeamCreated::class);
        Event::assertDispatched(TeamDeleted::class);
    }

    public function test_member_removal_fires_member_removed(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $team = Team::model()->forceCreate([
            'name' => 'Events Team',
            'user_id' => $owner->id,
            'is_public' => false,
            'activated_at' => now(),
        ]);
        $team->addMember($member);

        Event::fake([MemberRemoved::class]);
        $team->removeUser($member);

        Event::assertDispatched(MemberRemoved::class, function (MemberRemoved $event) use ($member) {
            return $event->user->id === $member->id;
        });
    }

    public function test_tenant_creation_fires_tenant_created(): void
    {
        Event::fake([TenantCreated::class]);

        Tenant::model()->forceCreate([
            'name' => 'Events Tenant',
            'slug' => 'events-tenant',
            'activated_at' => now(),
        ]);

        Event::assertDispatched(TenantCreated::class);
    }
}
