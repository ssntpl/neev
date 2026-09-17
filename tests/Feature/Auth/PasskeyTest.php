<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Ssntpl\Neev\Database\Factories\DomainFactory;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Models\Passkey;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\RelyingPartyResolver;
use Ssntpl\Neev\Services\TenantResolver;
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

    /**
     * The list is the page a user revokes from, so it shows every credential
     * whichever relying party issued it — a passkey enrolled on a tenant's
     * domain must stay revocable from the platform. `rp_id` is exposed so a
     * UI can label them.
     */
    public function test_get_passkeys_lists_credentials_from_every_relying_party(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $this->createPasskey($user, ['rp_id' => 'localhost']);
        $this->createPasskey($user, ['rp_id' => 'other.com']);
        $this->createPasskey($user, ['rp_id' => null]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/passkeys');

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
        $this->assertEqualsCanonicalizing(
            ['localhost', 'other.com', null],
            array_column($response->json('data'), 'rp_id')
        );
    }

    /**
     * The same holds on a tenant's own relying party: the list is where a
     * user revokes, not where they sign in, so the credentials enrolled on
     * the platform stay visible from `acme.com` even though no ceremony
     * there can use them.
     */
    public function test_get_passkeys_is_not_scoped_to_the_requests_relying_party(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $this->createPasskey($user, ['rp_id' => 'localhost']);
        $this->createPasskey($user, ['rp_id' => 'acme.com']);

        $this->app->instance(RelyingPartyResolver::class, new class (app(TenantResolver::class)) extends RelyingPartyResolver {
            public function rpId(): string
            {
                return 'acme.com';
            }
        });

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/passkeys');

        $response->assertOk();
        $this->assertEqualsCanonicalizing(
            ['localhost', 'acme.com'],
            array_column($response->json('data'), 'rp_id')
        );
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

    public function test_registration_options_exclude_credentials_already_on_this_relying_party(): void
    {
        [$user, $token] = $this->authenticatedUser();
        $mine = $this->createPasskey($user, ['rp_id' => 'localhost']);
        $legacy = $this->createPasskey($user, ['rp_id' => null]);
        $this->createPasskey($user, ['rp_id' => 'other.com']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/passkeys/register/options');

        $response->assertOk();
        $this->assertEqualsCanonicalizing(
            [$mine->credential_id, $legacy->credential_id],
            array_column($response->json('excludeCredentials'), 'id')
        );
        $this->assertSame('public-key', $response->json('excludeCredentials.0.type'));
    }

    public function test_registration_challenge_is_stored_with_its_relying_party(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/neev/passkeys/register/options');

        $response->assertOk();
        $stored = Cache::get("passkey_reg_challenge:{$user->id}");
        $this->assertSame($response->json('challenge'), $stored['challenge']);
        $this->assertSame('localhost', $stored['rp_id']);
    }

    /**
     * Options and completion are two requests, and the context that picks
     * the relying party can differ between them. A challenge issued under
     * one relying party must not complete a ceremony under another.
     */
    public function test_registration_refuses_a_challenge_issued_for_another_relying_party(): void
    {
        [$user, $token] = $this->authenticatedUser();
        Cache::put("passkey_reg_challenge:{$user->id}", ['challenge' => 'abc', 'rp_id' => 'other.com'], 300);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/passkeys/register', ['attestation' => '{}']);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Unable to register passkey.');
        $this->assertNull(Cache::get("passkey_reg_challenge:{$user->id}"), 'a refused challenge is consumed');
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

    public function test_login_challenge_is_stored_with_its_relying_party(): void
    {
        $user = User::factory()->create();
        $this->createPasskey($user);

        $response = $this->getJson('/neev/passkeys/login/options?email=' . urlencode($user->email));

        $response->assertOk();
        $stored = Cache::get('passkey_login_challenge:' . hash('sha256', $user->email));
        $this->assertSame($response->json('challenge'), $stored['challenge']);
        $this->assertSame('localhost', $stored['rp_id']);
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

    // -----------------------------------------------------------------
    // A federated domain must not take over the host users sign in on
    // -----------------------------------------------------------------

    /**
     * A team served at `acme.example.com` federates `acme.com` so `@acme.com`
     * staff auto-join; nothing is served there. Both ceremonies must keep
     * running on the platform relying party — under `rp.id = acme.com` the
     * browser refuses registration and every enrolled credential drops out of
     * `allowCredentials`.
     */
    public function test_ceremonies_on_a_platform_host_ignore_a_federated_domain(): void
    {
        $this->enableTeams();
        config([
            'neev.relying_party_id' => 'example.com',
            'neev.platform_domain' => 'example.com',
            'neev.allowed_origins' => ['https://example.com'],
        ]);

        $team = TeamFactory::new()->create();
        $this->verifiedDomain($team, 'acme.example.com', primary: true);
        $this->verifiedDomain($team, 'acme.com');

        [$user, $token] = $this->authenticatedUser();
        $passkey = $this->createPasskey($user, ['rp_id' => 'example.com']);

        $registration = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('Origin', 'https://acme.example.com')
            ->getJson('https://acme.example.com/neev/passkeys/register/options');

        $registration->assertOk()->assertJsonPath('rp.id', 'example.com');
        $this->assertSame(
            'example.com',
            Cache::get("passkey_reg_challenge:{$user->id}")['rp_id']
        );
        $this->assertSame(
            [$passkey->credential_id],
            array_column($registration->json('excludeCredentials'), 'id'),
            'the key already enrolled here is still recognised as enrolled'
        );

        $login = $this->withHeader('Origin', 'https://acme.example.com')
            ->getJson('https://acme.example.com/neev/passkeys/login/options?email=' . urlencode($user->email));

        $login->assertOk()->assertJsonPath('rpId', 'example.com');
        $this->assertSame(
            'example.com',
            Cache::get('passkey_login_challenge:' . hash('sha256', $user->email))['rp_id']
        );
        $this->assertSame(
            [$passkey->credential_id],
            array_column($login->json('allowCredentials'), 'id'),
            'the credentials already enrolled on the platform are still offered'
        );
    }

    /** The team's own domain still takes over when it is the host being served. */
    public function test_ceremonies_on_the_custom_domain_use_it(): void
    {
        $this->enableTeams();
        config([
            'neev.relying_party_id' => 'example.com',
            'neev.platform_domain' => 'example.com',
            'neev.allowed_origins' => ['https://example.com'],
        ]);

        $team = TeamFactory::new()->create();
        $this->verifiedDomain($team, 'acme.example.com');
        $this->verifiedDomain($team, 'acme.com');

        $user = User::factory()->create();
        $onPlatform = $this->createPasskey($user, ['rp_id' => 'example.com']);
        $onCustom = $this->createPasskey($user, ['rp_id' => 'acme.com']);

        $login = $this->withHeader('Origin', 'https://acme.com')
            ->getJson('https://acme.com/neev/passkeys/login/options?email=' . urlencode($user->email));

        $login->assertOk()->assertJsonPath('rpId', 'acme.com');
        $this->assertSame(
            [$onCustom->credential_id],
            array_column($login->json('allowCredentials'), 'id')
        );
    }

    private function verifiedDomain(object $owner, string $host, bool $primary = false): void
    {
        DomainFactory::new()->verified()->create([
            'owner_type' => $owner->getContextType(),
            'owner_id' => $owner->getKey(),
            'domain' => $host,
            'is_primary' => $primary,
        ]);
    }
}
