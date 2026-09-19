<?php

namespace Ssntpl\Neev\Tests\Unit\Models;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Models\Passkey;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;

class PasskeyTest extends TestCase
{
    use RefreshDatabase;

    private function makePasskey(array $attributes = []): Passkey
    {
        $user = User::factory()->create();

        return $user->passkeys()->create(array_merge([
            'credential_id' => bin2hex(random_bytes(16)),
            'public_key' => bin2hex(random_bytes(32)),
            'aaguid' => '00000000-0000-0000-0000-000000000000',
        ], $attributes));
    }

    // -----------------------------------------------------------------
    // effectiveRpId()
    // -----------------------------------------------------------------

    public function test_effective_rp_id_returns_stored_rp_id(): void
    {
        $passkey = $this->makePasskey(['rp_id' => 'acme.com']);

        $this->assertSame('acme.com', $passkey->effectiveRpId());
    }

    public function test_effective_rp_id_falls_back_to_config_when_null(): void
    {
        config(['neev.relying_party_id' => 'platform.com']);
        $passkey = $this->makePasskey(['rp_id' => null]);

        $this->assertSame('platform.com', $passkey->effectiveRpId());
    }

    /**
     * The resolver canonicalises the configured value (lowercase, no
     * trailing dot) before it becomes a ceremony's relying party; a legacy
     * row must be read in the same spelling or it never matches again.
     */
    public function test_effective_rp_id_canonicalises_the_configured_value(): void
    {
        config(['neev.relying_party_id' => 'Platform.COM.']);
        $passkey = $this->makePasskey(['rp_id' => null]);

        $this->assertSame('platform.com', $passkey->effectiveRpId());
        $this->assertTrue($passkey->matchesRelyingParty('platform.com'));
    }

    public function test_scope_includes_legacy_rows_when_configured_value_is_not_canonical(): void
    {
        config(['neev.relying_party_id' => 'Platform.COM.']);
        $passkey = $this->makePasskey(['rp_id' => null]);

        $this->assertTrue(Passkey::forRelyingParty('platform.com')->whereKey($passkey->id)->exists());
    }

    // -----------------------------------------------------------------
    // matchesRelyingParty()
    // -----------------------------------------------------------------

    public function test_matches_relying_party_returns_true_for_same_rp(): void
    {
        $passkey = $this->makePasskey(['rp_id' => 'acme.com']);

        $this->assertTrue($passkey->matchesRelyingParty('acme.com'));
    }

    public function test_matches_relying_party_returns_false_for_different_rp(): void
    {
        $passkey = $this->makePasskey(['rp_id' => 'acme.com']);

        $this->assertFalse($passkey->matchesRelyingParty('other.com'));
    }

    public function test_matches_relying_party_uses_config_for_null_rp_id(): void
    {
        config(['neev.relying_party_id' => 'platform.com']);
        $passkey = $this->makePasskey(['rp_id' => null]);

        $this->assertTrue($passkey->matchesRelyingParty('platform.com'));
        $this->assertFalse($passkey->matchesRelyingParty('other.com'));
    }

    // -----------------------------------------------------------------
    // scopeForRelyingParty()
    // -----------------------------------------------------------------

    public function test_scope_returns_credentials_for_matching_rp(): void
    {
        $user = User::factory()->create();
        $user->passkeys()->create([
            'credential_id' => bin2hex(random_bytes(16)),
            'public_key' => bin2hex(random_bytes(32)),
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'rp_id' => 'acme.com',
        ]);

        $results = $user->passkeys()->forRelyingParty('acme.com')->get();

        $this->assertCount(1, $results);
    }

    public function test_scope_excludes_credentials_for_different_rp(): void
    {
        $user = User::factory()->create();
        $user->passkeys()->create([
            'credential_id' => bin2hex(random_bytes(16)),
            'public_key' => bin2hex(random_bytes(32)),
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'rp_id' => 'other.com',
        ]);

        $results = $user->passkeys()->forRelyingParty('acme.com')->get();

        $this->assertCount(0, $results);
    }

    public function test_scope_includes_null_rp_id_rows_for_configured_rp(): void
    {
        config(['neev.relying_party_id' => 'platform.com']);
        $user = User::factory()->create();
        $user->passkeys()->create([
            'credential_id' => bin2hex(random_bytes(16)),
            'public_key' => bin2hex(random_bytes(32)),
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'rp_id' => null,
        ]);

        $results = $user->passkeys()->forRelyingParty('platform.com')->get();

        $this->assertCount(1, $results);
    }

    public function test_scope_excludes_null_rp_id_rows_for_non_configured_rp(): void
    {
        config(['neev.relying_party_id' => 'platform.com']);
        $user = User::factory()->create();
        $user->passkeys()->create([
            'credential_id' => bin2hex(random_bytes(16)),
            'public_key' => bin2hex(random_bytes(32)),
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'rp_id' => null,
        ]);

        // Legacy rows belong to the configured RP only, not to a custom domain
        $results = $user->passkeys()->forRelyingParty('acme.com')->get();

        $this->assertCount(0, $results);
    }
}
