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
        config(['neev.frontend_url' => 'http://localhost']);
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
            ->assertJsonPath('status', 'Success')
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

        $response->assertOk()
            ->assertJsonPath('status', 'Failed');

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

        $response->assertOk()
            ->assertJsonPath('status', 'Failed');
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
            ->assertJsonPath('status', 'Success')
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
            ->assertJsonPath('status', 'Failed');
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
            ->assertJsonPath('status', 'Failed');
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

    // -----------------------------------------------------------------
    // POST /passkeys/login/options (public endpoint) — login options
    // -----------------------------------------------------------------

    public function test_generate_login_options_for_valid_email(): void
    {
        $user = User::factory()->create();
        $this->createPasskey($user);

        $response = $this->getJson('/neev/passkeys/login/options?email=' . urlencode($user->email->email));

        $response->assertOk()
            ->assertJsonPath('status', 'Success')
            ->assertJsonStructure([
                'challenge',
                'timeout',
                'rpId',
                'allowCredentials',
            ]);
    }

    public function test_generate_login_options_fails_for_unknown_email(): void
    {
        $response = $this->getJson('/neev/passkeys/login/options?email=nobody@example.com');

        $response->assertOk()
            ->assertJsonPath('status', 'Failed');
    }

    public function test_generate_login_options_requires_email(): void
    {
        // Validation is inside a try/catch so it returns a Failed response, not 422
        $response = $this->getJson('/neev/passkeys/login/options');

        $response->assertOk()
            ->assertJsonPath('status', 'Failed');
    }
}
