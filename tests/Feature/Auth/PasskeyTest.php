<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Ssntpl\Neev\Database\Factories\DomainFactory;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Models\Passkey;
use Ssntpl\Neev\Mail\EmailOTP;
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

    /** The factory's password, which the confirmed endpoints now ask for. */
    private const PASSWORD = 'password';

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
    // POST /neev/passkeys/register/options — registration options
    // -----------------------------------------------------------------

    public function test_generate_registration_options(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/passkeys/register/options', ['password' => self::PASSWORD]);

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
            ->postJson('/neev/passkeys/register/options', ['password' => self::PASSWORD]);

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
            ->postJson('/neev/passkeys/register/options', ['password' => self::PASSWORD]);

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
            ->postJson('/neev/passkeys/register/options', ['password' => self::PASSWORD]);

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

        $response = $this->postJson('/neev/passkeys/login/options', ['email' => $user->email]);

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

        $response = $this->postJson('/neev/passkeys/login/options', ['email' => $user->email]);

        $response->assertOk();
        $this->assertCount(1, $response->json('allowCredentials'));
    }

    public function test_login_challenge_is_stored_with_its_relying_party(): void
    {
        $user = User::factory()->create();
        $this->createPasskey($user);

        $response = $this->postJson('/neev/passkeys/login/options', ['email' => $user->email]);

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

        $response = $this->postJson('/neev/passkeys/login/options', ['email' => $user->email]);

        $response->assertOk()
            ->assertJsonPath('rpId', 'passkeys.example.com');
    }

    public function test_generate_login_options_fails_for_unknown_email(): void
    {
        $response = $this->postJson('/neev/passkeys/login/options', ['email' => 'nobody@example.com']);

        $response->assertStatus(400);
    }

    public function test_generate_login_options_requires_email(): void
    {
        $response = $this->postJson('/neev/passkeys/login/options');

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
     * staff auto-join; nothing is served there. Both ceremonies run on the
     * host the team is actually reached on — under `rp.id = acme.com` the
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
        $passkey = $this->createPasskey($user, ['rp_id' => 'acme.example.com']);

        $registration = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('Origin', 'https://acme.example.com')
            ->postJson('https://acme.example.com/neev/passkeys/register/options', ['password' => self::PASSWORD]);

        $registration->assertOk()->assertJsonPath('rp.id', 'acme.example.com');
        $this->assertSame(
            'acme.example.com',
            Cache::get("passkey_reg_challenge:{$user->id}")['rp_id']
        );
        $this->assertSame(
            [$passkey->credential_id],
            array_column($registration->json('excludeCredentials'), 'id'),
            'the key already enrolled here is still recognised as enrolled'
        );

        $login = $this->withHeader('Origin', 'https://acme.example.com')
            ->postJson('https://acme.example.com/neev/passkeys/login/options', ['email' => $user->email]);

        $login->assertOk()->assertJsonPath('rpId', 'acme.example.com');
        $this->assertSame(
            'acme.example.com',
            Cache::get('passkey_login_challenge:' . hash('sha256', $user->email))['rp_id']
        );
        $this->assertSame(
            [$passkey->credential_id],
            array_column($login->json('allowCredentials'), 'id'),
            'the credentials already enrolled on this host are still offered'
        );
    }

    /**
     * The isolation a per-host relying party buys: a credential enrolled on
     * one tenant's platform subdomain is not offered on another's, so a tenant
     * that can run script on its own host cannot start a ceremony that
     * completes against a sibling's credential.
     */
    public function test_a_platform_subdomain_does_not_offer_a_siblings_credentials(): void
    {
        $this->enableTeams();
        config([
            'neev.relying_party_id' => 'example.com',
            'neev.platform_domain' => 'example.com',
            'neev.allowed_origins' => ['https://example.com'],
        ]);

        $victimTeam = TeamFactory::new()->create();
        $this->verifiedDomain($victimTeam, 'victim.example.com', primary: true);
        $evilTeam = TeamFactory::new()->create();
        $this->verifiedDomain($evilTeam, 'evil.example.com', primary: true);

        [$user] = $this->authenticatedUser();

        // Enrolled under the old shared zone-wide relying party, which is
        // what every subdomain answered to before this change: on the old
        // code this ceremony returned 200 and offered exactly this credential
        // on evil.example.com.
        $this->createPasskey($user, ['rp_id' => 'example.com']);

        $login = $this->withHeader('Origin', 'https://evil.example.com')
            ->postJson('https://evil.example.com/neev/passkeys/login/options', ['email' => $user->email]);

        // evil.example.com is its own relying party now, and no credential
        // answers for it, so there is nothing to run a ceremony against.
        $login->assertStatus(400);
    }

    /** The same for a legacy credential that records no relying party at all. */
    public function test_a_platform_subdomain_does_not_offer_legacy_platform_credentials(): void
    {
        $this->enableTeams();
        config([
            'neev.relying_party_id' => 'example.com',
            'neev.platform_domain' => 'example.com',
            'neev.allowed_origins' => ['https://example.com'],
        ]);

        $team = TeamFactory::new()->create();
        $this->verifiedDomain($team, 'acme.example.com', primary: true);

        [$user] = $this->authenticatedUser();
        $this->createPasskey($user, ['rp_id' => null]);

        $this->withHeader('Origin', 'https://acme.example.com')
            ->postJson('https://acme.example.com/neev/passkeys/login/options', ['email' => $user->email])
            ->assertStatus(400);
    }

    /** And it still works on the platform host, where it belongs. */
    public function test_a_legacy_credential_still_works_on_the_platform_host(): void
    {
        $this->enableTeams();
        config([
            'neev.relying_party_id' => 'example.com',
            'neev.platform_domain' => 'example.com',
            'neev.allowed_origins' => ['https://example.com'],
        ]);

        $team = TeamFactory::new()->create();
        $this->verifiedDomain($team, 'acme.example.com', primary: true);

        [$user] = $this->authenticatedUser();
        $this->createPasskey($user, ['rp_id' => null]);

        $this->withHeader('Origin', 'https://example.com')
            ->postJson('https://example.com/neev/passkeys/login/options', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('rpId', 'example.com');
    }

    /**
     * Enrolling a passkey is enrolling a credential that signs in with the
     * account's whole authority, so a scoped API token must not reach it —
     * otherwise a leaked `['read']` token enrols an authenticator it controls
     * and signs in past every scope it was given.
     */
    public function test_a_scoped_api_token_cannot_enrol_a_passkey(): void
    {
        $user = User::factory()->create();
        $scoped = $user->createApiToken('scoped', ['read'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $scoped)
            ->postJson('/neev/passkeys/register/options', ['password' => self::PASSWORD])
            ->assertForbidden()
            ->assertJsonPath('message', 'An API token cannot enrol a passkey.');

        $this->withHeader('Authorization', 'Bearer ' . $scoped)
            ->postJson('/neev/passkeys/register', ['attestation' => '{}'])
            ->assertForbidden();

        $this->assertSame(0, $user->passkeys()->count());
    }

    /** A login token — including the one a cookie-mode SPA carries — may. */
    public function test_a_login_token_may_enrol_a_passkey(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/passkeys/register/options', ['password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('rp.id', config('neev.relying_party_id'));
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
            ->postJson('https://acme.com/neev/passkeys/login/options', ['email' => $user->email]);

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

    /**
     * A passkey signs in with the account's whole authority and is never
     * parked at the MFA challenge, so enrolling one is strictly more than
     * enrolling a second factor — which is confirmed. A stolen session must
     * not be able to add its own way in.
     */
    public function test_enrolling_a_passkey_is_confirmed(): void
    {
        [$user, $token] = $this->authenticatedUser();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/passkeys/register/options')
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/passkeys/register/options', ['password' => 'not-the-password'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'The password is incorrect.');

        $this->assertNull(Cache::get("passkey_reg_challenge:{$user->id}"), 'No challenge is issued.');
    }

    /** An account with no password confirms with a mailed code instead. */
    public function test_a_passwordless_account_enrols_a_passkey_with_a_code(): void
    {
        Mail::fake();

        $user = User::factory()->create(['password' => null]);
        $token = $user->createLoginToken(60)->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/passkeys/register/options')
            ->assertStatus(422)
            ->assertJsonValidationErrors('otp');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/confirmation/otp')
            ->assertOk();

        $otp = null;
        Mail::assertSent(EmailOTP::class, function (EmailOTP $mail) use (&$otp) {
            $otp = $mail->otp;

            return true;
        });

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/passkeys/register/options', ['otp' => $otp])
            ->assertOk()
            ->assertJsonPath('rp.id', config('neev.relying_party_id'));
    }
}
