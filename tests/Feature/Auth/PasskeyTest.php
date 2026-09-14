<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Models\Passkey;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class PasskeyTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'http://localhost',
            'neev.relying_party_id' => 'localhost',
            'neev.allowed_origins' => ['http://localhost'],
        ]);
    }

    protected function authenticatedUser(): array
    {
        $user = User::factory()->create();
        $token = $user->createLoginToken(60);

        return [$user, $token->plainTextToken];
    }

    protected function createPasskey(User $user, array $overrides = []): Passkey
    {
        return $user->passkeys()->create(array_merge([
            'credential_id' => bin2hex(random_bytes(16)),
            'public_key' => bin2hex(random_bytes(32)),
            'name' => 'Test Passkey',
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'transports' => ['usb'],
            'ip' => '127.0.0.1',
        ], $overrides));
    }

    // -----------------------------------------------------------------
    // GET /neev/passkeys — list passkeys
    // -----------------------------------------------------------------

    public function test_get_passkeys_returns_only_current_rp_credentials(): void
    {
        [$user, $token] = $this->authenticatedUser();

        // Passkey for the current RP
        $this->createPasskey($user, ['rp_id' => 'localhost']);
        // Passkey for a different RP — must not appear
        $this->createPasskey($user, ['rp_id' => 'other.com']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/passkeys');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_get_passkeys_includes_legacy_null_rp_id_on_platform_domain(): void
    {
        [$user, $token] = $this->authenticatedUser();

        // Legacy row with no rp_id — treated as the configured RP
        $this->createPasskey($user, ['rp_id' => null]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/passkeys');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    // -----------------------------------------------------------------
    // DELETE /neev/passkeys — delete passkey via API
    // -----------------------------------------------------------------

    public function test_delete_own_passkey(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $passkey = $this->createPasskey($user);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/passkeys', [
                'passkey_id' => $passkey->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Passkey has been deleted.');

        $this->assertDatabaseMissing('passkeys', ['id' => $passkey->id]);
    }

    public function test_cannot_delete_another_users_passkey(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $otherUser = User::factory()->create();
        $passkey = $this->createPasskey($otherUser);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/passkeys', [
                'passkey_id' => $passkey->id,
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Passkey was not deleted.');

        // Passkey should still exist
        $this->assertDatabaseHas('passkeys', ['id' => $passkey->id]);
    }

    public function test_delete_nonexistent_passkey(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/neev/passkeys', [
                'passkey_id' => 99999,
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Passkey was not deleted.');
    }

    // -----------------------------------------------------------------
    // PUT /neev/passkeys — update passkey name
    // -----------------------------------------------------------------

    public function test_update_passkey_name(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $passkey = $this->createPasskey($user, ['name' => 'Old Name']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/passkeys', [
                'passkey_id' => $passkey->id,
                'name' => 'New Name',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'New Name');

        $passkey->refresh();
        $this->assertEquals('New Name', $passkey->name);
    }

    public function test_update_passkey_name_rejects_other_users_passkey(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $otherUser = User::factory()->create();
        $passkey = $this->createPasskey($otherUser);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/passkeys', [
                'passkey_id' => $passkey->id,
                'name' => 'Hijacked Name',
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Passkey not found');
    }

    public function test_update_passkey_name_rejects_nonexistent(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/neev/passkeys', [
                'passkey_id' => 99999,
                'name' => 'Ghost',
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Passkey not found');
    }

    // -----------------------------------------------------------------
    // GET /neev/passkeys/register/options — registration options
    // -----------------------------------------------------------------

    public function test_generate_registration_options(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/passkeys/register/options');

        $response->assertOk()
            ->assertJsonStructure([
                'rp' => ['name', 'id'],
                'user' => ['id', 'name', 'displayName'],
                'challenge',
                'pubKeyCredParams',
                'timeout',
            ]);
    }

    public function test_registration_options_use_configured_relying_party_id(): void
    {
        config(['neev.relying_party_id' => 'passkeys.example.com']);
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/passkeys/register/options');

        $response->assertOk()
            ->assertJsonPath('rp.id', 'passkeys.example.com');
    }

    // -----------------------------------------------------------------
    // POST /passkeys/login/options (public endpoint) — login options
    // -----------------------------------------------------------------

    public function test_generate_login_options_for_valid_email(): void
    {
        $user = User::factory()->create();
        $this->createPasskey($user);

        $response = $this->getJson('/neev/passkeys/login/options?email=' . urlencode($user->email));

        $response->assertOk()
            ->assertJsonStructure([
                'challenge',
                'timeout',
                'rpId',
                'allowCredentials',
            ]);
    }

    public function test_login_options_only_offer_credentials_for_current_rp(): void
    {
        $user = User::factory()->create();
        // Passkey for the current RP
        $this->createPasskey($user, ['rp_id' => 'localhost']);
        // Passkey for a different RP — must not appear in allowCredentials
        $this->createPasskey($user, ['rp_id' => 'other.com']);

        $response = $this->getJson('/neev/passkeys/login/options?email=' . urlencode($user->email));

        $response->assertOk();
        $this->assertCount(1, $response->json('allowCredentials'));
    }

    public function test_login_options_use_configured_relying_party_id(): void
    {
        config(['neev.relying_party_id' => 'passkeys.example.com']);
        $user = User::factory()->create();
        $this->createPasskey($user);

        $response = $this->getJson('/neev/passkeys/login/options?email=' . urlencode($user->email));

        $response->assertOk()
            ->assertJsonPath('rpId', 'passkeys.example.com');
    }

    public function test_generate_login_options_fails_for_unknown_email(): void
    {
        $response = $this->getJson('/neev/passkeys/login/options?email=nobody@example.com');

        $response->assertStatus(400);
    }

    public function test_generate_login_options_requires_email(): void
    {
        $response = $this->getJson('/neev/passkeys/login/options');

        $response->assertStatus(400);
    }

    // -----------------------------------------------------------------
    // Registration deduplication — credential ID, not AAGUID
    // -----------------------------------------------------------------

    public function test_two_keys_of_the_same_model_get_separate_rows(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $sharedAaguid = 'f8a011f3-8c0a-4d15-8006-17111f9edc7d'; // same model

        $this->createPasskey($user, [
            'credential_id' => 'credential-one',
            'aaguid' => $sharedAaguid,
            'rp_id' => 'localhost',
        ]);
        $this->createPasskey($user, [
            'credential_id' => 'credential-two',
            'aaguid' => $sharedAaguid,
            'rp_id' => 'localhost',
        ]);

        $this->assertDatabaseCount('passkeys', 2);
    }

    public function test_re_enrolling_same_credential_id_updates_existing_row(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $credentialId = 'same-credential-id';

        $original = $this->createPasskey($user, [
            'credential_id' => $credentialId,
            'name' => 'Original Name',
            'rp_id' => 'localhost',
        ]);

        // Simulate re-registration with the same credential ID
        $user->passkeys()
            ->where('credential_id', $credentialId)
            ->forRelyingParty('localhost')
            ->first()
            ->update(['name' => 'Updated Name']);

        $this->assertDatabaseCount('passkeys', 1);
        $this->assertDatabaseHas('passkeys', [
            'id' => $original->id,
            'credential_id' => $credentialId,
            'name' => 'Updated Name',
        ]);
    }

    public function test_zero_aaguid_key_always_creates_a_new_row(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $this->createPasskey($user, [
            'credential_id' => 'credential-one',
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'rp_id' => 'localhost',
        ]);
        $this->createPasskey($user, [
            'credential_id' => 'credential-two',
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'rp_id' => 'localhost',
        ]);

        // Both rows must survive — zero AAGUID is not a device identity
        $this->assertDatabaseCount('passkeys', 2);
    }
}
