<?php

namespace Ssntpl\Neev\Tests\Feature\Account;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use OTPHP\TOTP;
use Ssntpl\Neev\Events\MfaMethodAdded;
use Ssntpl\Neev\Events\MfaMethodRemoved;
use Ssntpl\Neev\Models\MultiFactorAuth;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;

class PendingMfaSetupTest extends TestCase
{
    use RefreshDatabase;

    private function createAuthenticatedUser(): array
    {
        $user = User::factory()->create(['password' => 'Str0ng@Password']);
        $newToken = $user->createLoginToken(config('neev.login_token_expiry_minutes', 1440));

        return [
            'user' => $user,
            'plainTextToken' => $newToken->plainTextToken,
        ];
    }

    // -----------------------------------------------------------------
    // Pending creation
    // -----------------------------------------------------------------

    public function test_adding_authenticator_creates_pending_setup_without_event(): void
    {
        Event::fake([MfaMethodAdded::class]);

        $user = User::factory()->create();
        $user->addMultiFactorAuth('authenticator');

        $auth = $user->multiFactorAuths()->where('method', 'authenticator')->first();
        $this->assertSame(MultiFactorAuth::STATUS_PENDING, $auth->status);
        $this->assertFalse($auth->preferred);
        Event::assertNotDispatched(MfaMethodAdded::class);
    }

    public function test_adding_email_method_is_active_immediately(): void
    {
        $user = User::factory()->create();
        $user->addMultiFactorAuth('email');

        $auth = $user->multiFactorAuths()->where('method', 'email')->first();
        $this->assertSame(MultiFactorAuth::STATUS_ACTIVE, $auth->status);
    }

    public function test_re_adding_pending_authenticator_reuses_secret(): void
    {
        $user = User::factory()->create();
        $first = $user->addMultiFactorAuth('authenticator');
        $second = $user->addMultiFactorAuth('authenticator');

        $this->assertSame($first['secret'], $second['secret']);
        $this->assertSame(1, $user->multiFactorAuths()->count());
    }

    // -----------------------------------------------------------------
    // Login is not gated by a pending setup
    // -----------------------------------------------------------------

    public function test_pending_authenticator_does_not_gate_api_login(): void
    {
        $user = User::factory()->create(['password' => 'Str0ng@Password']);
        $user->addMultiFactorAuth('authenticator');

        $response = $this->postJson('/neev/login', [
            'email' => $user->email,
            'password' => 'Str0ng@Password',
        ]);

        $response->assertOk();
        $response->assertJsonPath('auth_state', 'authenticated');
    }

    public function test_active_authenticator_gates_api_login(): void
    {
        $user = User::factory()->create(['password' => 'Str0ng@Password']);
        $setup = $user->addMultiFactorAuth('authenticator');
        $user->verifyMfaSetup('authenticator', TOTP::create($setup['secret'])->now());

        $response = $this->postJson('/neev/login', [
            'email' => $user->email,
            'password' => 'Str0ng@Password',
        ]);

        $response->assertOk();
        $response->assertJsonPath('auth_state', 'mfa_required');
        $response->assertJsonPath('mfa_options', ['authenticator']);
    }

    // -----------------------------------------------------------------
    // Setup verification endpoint
    // -----------------------------------------------------------------

    public function test_setup_verify_endpoint_activates_pending_authenticator(): void
    {
        Event::fake([MfaMethodAdded::class]);

        $data = $this->createAuthenticatedUser();
        $setup = $data['user']->addMultiFactorAuth('authenticator');
        $otp = TOTP::create($setup['secret'])->now();

        $response = $this->withHeader('Authorization', 'Bearer ' . $data['plainTextToken'])
            ->postJson('/neev/mfa/setup/verify', [
                'auth_method' => 'authenticator',
                'otp' => $otp,
            ]);

        $response->assertOk();
        $auth = $data['user']->multiFactorAuths()->where('method', 'authenticator')->first();
        $this->assertSame(MultiFactorAuth::STATUS_ACTIVE, $auth->status);
        $this->assertTrue($auth->preferred);
        Event::assertDispatched(MfaMethodAdded::class, function (MfaMethodAdded $event) {
            return $event->method === 'authenticator';
        });
    }

    public function test_setup_verify_endpoint_rejects_wrong_otp(): void
    {
        $data = $this->createAuthenticatedUser();
        $data['user']->addMultiFactorAuth('authenticator');

        $response = $this->withHeader('Authorization', 'Bearer ' . $data['plainTextToken'])
            ->postJson('/neev/mfa/setup/verify', [
                'auth_method' => 'authenticator',
                'otp' => '000000',
            ]);

        $response->assertStatus(400);
        $auth = $data['user']->multiFactorAuths()->where('method', 'authenticator')->first();
        $this->assertSame(MultiFactorAuth::STATUS_PENDING, $auth->status);
    }

    public function test_setup_verify_fails_for_already_active_method(): void
    {
        $data = $this->createAuthenticatedUser();
        $setup = $data['user']->addMultiFactorAuth('authenticator');
        $totp = TOTP::create($setup['secret']);
        $data['user']->verifyMfaSetup('authenticator', $totp->now());

        $response = $this->withHeader('Authorization', 'Bearer ' . $data['plainTextToken'])
            ->postJson('/neev/mfa/setup/verify', [
                'auth_method' => 'authenticator',
                'otp' => $totp->now(),
            ]);

        $response->assertStatus(400);
    }

    // -----------------------------------------------------------------
    // Pending methods are second-class
    // -----------------------------------------------------------------

    public function test_pending_method_cannot_be_set_preferred(): void
    {
        $data = $this->createAuthenticatedUser();
        $data['user']->addMultiFactorAuth('authenticator');

        $response = $this->withHeader('Authorization', 'Bearer ' . $data['plainTextToken'])
            ->putJson('/neev/mfa/preferred', [
                'auth_method' => 'authenticator',
            ]);

        $response->assertStatus(400);
    }

    public function test_removing_pending_setup_does_not_fire_removed_event(): void
    {
        $user = User::factory()->create();
        $user->addMultiFactorAuth('authenticator');

        Event::fake([MfaMethodRemoved::class]);
        $this->assertTrue($user->removeMultiFactorAuth('authenticator'));

        $this->assertSame(0, $user->multiFactorAuths()->count());
        Event::assertNotDispatched(MfaMethodRemoved::class);
    }

    public function test_recovery_codes_require_an_active_method(): void
    {
        $data = $this->createAuthenticatedUser();
        $data['user']->addMultiFactorAuth('authenticator'); // pending only

        $response = $this->withHeader('Authorization', 'Bearer ' . $data['plainTextToken'])
            ->postJson('/neev/recoveryCodes');

        $response->assertStatus(400);
    }

    // -----------------------------------------------------------------
    // Programmatic activation escape hatch
    // -----------------------------------------------------------------

    public function test_activate_skips_otp_but_keeps_invariants(): void
    {
        Event::fake([MfaMethodAdded::class]);

        $user = User::factory()->create();
        $user->addMultiFactorAuth('authenticator');
        $auth = $user->multiFactorAuths()->where('method', 'authenticator')->first();

        $this->assertTrue($auth->activate());

        $auth->refresh();
        $this->assertSame(MultiFactorAuth::STATUS_ACTIVE, $auth->status);
        $this->assertTrue($auth->preferred);
        Event::assertDispatched(MfaMethodAdded::class, function (MfaMethodAdded $event) use ($user) {
            return $event->user->id === $user->id && $event->method === 'authenticator';
        });
    }

    public function test_activate_returns_false_for_already_active_method(): void
    {
        $user = User::factory()->create();
        $user->addMultiFactorAuth('email');
        $auth = $user->multiFactorAuths()->where('method', 'email')->first();

        Event::fake([MfaMethodAdded::class]);
        $this->assertFalse($auth->activate());

        Event::assertNotDispatched(MfaMethodAdded::class);
    }

    public function test_activate_does_not_steal_preferred_from_existing_active_method(): void
    {
        $user = User::factory()->create();
        $user->addMultiFactorAuth('email'); // active + preferred
        $user->load('multiFactorAuths');
        $user->addMultiFactorAuth('authenticator'); // pending

        $auth = $user->multiFactorAuths()->where('method', 'authenticator')->first();
        $auth->activate();

        $auth->refresh();
        $this->assertFalse($auth->preferred);
        $this->assertSame('email', $user->preferredMultiFactorAuth()->first()->method);
    }

    // -----------------------------------------------------------------
    // Cleanup command
    // -----------------------------------------------------------------

    public function test_clean_command_deletes_only_stale_pending_setups(): void
    {
        $user = User::factory()->create();

        $stalePending = $user->multiFactorAuths()->create([
            'method' => 'authenticator',
            'status' => MultiFactorAuth::STATUS_PENDING,
            'secret' => 'SECRET',
        ]);
        $stalePending->created_at = now()->subDays(5);
        $stalePending->save();

        $freshPending = User::factory()->create()->multiFactorAuths()->create([
            'method' => 'authenticator',
            'status' => MultiFactorAuth::STATUS_PENDING,
            'secret' => 'SECRET',
        ]);

        $staleActive = User::factory()->create()->multiFactorAuths()->create([
            'method' => 'email',
            'status' => MultiFactorAuth::STATUS_ACTIVE,
        ]);
        $staleActive->created_at = now()->subDays(30);
        $staleActive->save();

        $this->artisan('neev:clean-pending-mfa-setups')->assertSuccessful();

        $this->assertDatabaseMissing('multi_factor_auths', ['id' => $stalePending->id]);
        $this->assertDatabaseHas('multi_factor_auths', ['id' => $freshPending->id]);
        $this->assertDatabaseHas('multi_factor_auths', ['id' => $staleActive->id]);
    }
}
