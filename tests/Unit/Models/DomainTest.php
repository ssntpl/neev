<?php

namespace Ssntpl\Neev\Tests\Unit\Models;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ssntpl\Neev\Database\Factories\DomainFactory;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Ssntpl\Neev\Models\Domain;
use Ssntpl\Neev\Models\DomainRule;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Tests\TestCase;

class DomainTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // isVerified()
    // -----------------------------------------------------------------

    public function test_is_verified_returns_true_when_verified_at_set(): void
    {
        $domain = DomainFactory::new()->verified()->create();

        $this->assertTrue($domain->isVerified());
    }

    public function test_is_verified_returns_false_when_verified_at_null(): void
    {
        $domain = DomainFactory::new()->create();

        $this->assertFalse($domain->isVerified());
    }

    // -----------------------------------------------------------------
    // findByHostForOwnerType() — also the availability check for a claim:
    // only a verified claim reserves a domain, and only against its own
    // kind of owner, so a null return means the domain is free
    // -----------------------------------------------------------------

    public function test_an_unclaimed_domain_is_free(): void
    {
        $this->assertNull(Domain::findByHostForOwnerType('acme.test', 'team'));
    }

    public function test_an_unverified_claim_reserves_nothing(): void
    {
        DomainFactory::new()->create(['domain' => 'acme.test', 'owner_type' => 'team', 'owner_id' => 1]);

        $this->assertNull(Domain::findByHostForOwnerType('acme.test', 'team'));
    }

    public function test_a_verified_claim_reserves_the_domain_for_that_owner_type(): void
    {
        DomainFactory::new()->verified()->create(['domain' => 'acme.test', 'owner_type' => 'team', 'owner_id' => 1]);

        // Not free for another team, nor for the team that already holds it.
        $this->assertNotNull(Domain::findByHostForOwnerType('acme.test', 'team'));
    }

    public function test_a_claim_is_scoped_to_the_owner_type(): void
    {
        DomainFactory::new()->verified()->create(['domain' => 'acme.test', 'owner_type' => 'team', 'owner_id' => 1]);

        // A tenant may federate the same company domain a team has verified.
        $this->assertNull(Domain::findByHostForOwnerType('acme.test', 'tenant'));
    }

    public function test_a_verified_tenant_claim_blocks_a_second_tenant(): void
    {
        DomainFactory::new()->verified()->create(['domain' => 'acme.test', 'owner_type' => 'tenant', 'owner_id' => 1]);

        $this->assertNotNull(Domain::findByHostForOwnerType('acme.test', 'tenant'));
    }

    public function test_find_by_host_for_owner_type_finds_the_requested_kind(): void
    {
        // Tenant row first, so an unfiltered lookup would shadow the team's.
        DomainFactory::new()->verified()->create(['domain' => 'acme.test', 'owner_type' => 'tenant', 'owner_id' => 7]);
        DomainFactory::new()->verified()->create(['domain' => 'acme.test', 'owner_type' => 'team', 'owner_id' => 9]);

        $this->assertSame(9, Domain::findByHostForOwnerType('acme.test', 'team')?->owner_id);
        $this->assertSame(7, Domain::findByHostForOwnerType('acme.test', 'tenant')?->owner_id);
    }

    public function test_find_by_host_for_owner_type_ignores_unverified_rows(): void
    {
        DomainFactory::new()->create(['domain' => 'acme.test', 'owner_type' => 'team', 'owner_id' => 9]);

        $this->assertNull(Domain::findByHostForOwnerType('acme.test', 'team'));
    }

    // -----------------------------------------------------------------
    // findByHost()
    // -----------------------------------------------------------------

    public function test_find_by_host_finds_verified_domain(): void
    {
        $domain = DomainFactory::new()->verified()->create([
            'domain' => 'myapp.example.com',
        ]);

        $found = Domain::findByHost('myapp.example.com');

        $this->assertNotNull($found);
        $this->assertSame($domain->id, $found->id);
    }

    public function test_find_by_host_returns_null_for_unverified_domain(): void
    {
        DomainFactory::new()->create([
            'domain' => 'unverified.example.com',
        ]);

        $found = Domain::findByHost('unverified.example.com');

        $this->assertNull($found);
    }

    public function test_find_by_host_returns_null_for_nonexistent_domain(): void
    {
        $found = Domain::findByHost('nonexistent.example.com');

        $this->assertNull($found);
    }

    // -----------------------------------------------------------------
    // findPrimaryByHost()
    // -----------------------------------------------------------------

    public function test_find_primary_by_host_finds_primary_verified_domain(): void
    {
        $domain = DomainFactory::new()->verified()->primary()->create([
            'domain' => 'primary.example.com',
        ]);

        $found = Domain::findPrimaryByHost('primary.example.com');

        $this->assertNotNull($found);
        $this->assertSame($domain->id, $found->id);
    }

    public function test_find_primary_by_host_returns_null_for_non_primary_verified_domain(): void
    {
        DomainFactory::new()->verified()->create([
            'domain' => 'notprimary.example.com',
            'is_primary' => false,
        ]);

        $found = Domain::findPrimaryByHost('notprimary.example.com');

        $this->assertNull($found);
    }

    public function test_find_primary_by_host_returns_null_for_unverified_primary_domain(): void
    {
        DomainFactory::new()->primary()->create([
            'domain' => 'unverified-primary.example.com',
        ]);

        $found = Domain::findPrimaryByHost('unverified-primary.example.com');

        $this->assertNull($found);
    }

    // -----------------------------------------------------------------
    // findByHostForOwner()
    // -----------------------------------------------------------------

    public function test_find_by_host_for_owner_finds_matching_domain(): void
    {
        $team = TeamFactory::new()->create();
        $domain = DomainFactory::new()->verified()->create([
            'owner_type' => 'team',
            'owner_id' => $team->id,
            'domain' => 'owner-test.example.com',
        ]);

        $found = Domain::findByHostForOwner('owner-test.example.com', 'team', $team->id);

        $this->assertNotNull($found);
        $this->assertSame($domain->id, $found->id);
    }

    public function test_find_by_host_for_owner_returns_null_for_wrong_owner(): void
    {
        $team = TeamFactory::new()->create();
        DomainFactory::new()->verified()->create([
            'owner_type' => 'team',
            'owner_id' => $team->id,
            'domain' => 'wrong-owner.example.com',
        ]);

        $found = Domain::findByHostForOwner('wrong-owner.example.com', 'team', 99999);

        $this->assertNull($found);
    }

    // -----------------------------------------------------------------
    // markAsPrimary()
    // -----------------------------------------------------------------

    public function test_mark_as_primary_unsets_other_primary_domains_for_same_owner(): void
    {
        $team = TeamFactory::new()->create();

        $domain1 = DomainFactory::new()->primary()->verified()->create([
            'owner_type' => 'team',
            'owner_id' => $team->id,
            'domain' => 'first.example.com',
        ]);

        $domain2 = DomainFactory::new()->verified()->create([
            'owner_type' => 'team',
            'owner_id' => $team->id,
            'domain' => 'second.example.com',
        ]);

        $this->assertTrue($domain1->is_primary);
        $this->assertFalse($domain2->is_primary);

        $domain2->markAsPrimary();

        $domain1->refresh();
        $domain2->refresh();

        $this->assertFalse($domain1->is_primary);
        $this->assertTrue($domain2->is_primary);
    }

    public function test_mark_as_primary_does_not_affect_other_owners(): void
    {
        $team1 = TeamFactory::new()->create();
        $team2 = TeamFactory::new()->create();

        $domain1 = DomainFactory::new()->primary()->verified()->create([
            'owner_type' => 'team',
            'owner_id' => $team1->id,
        ]);

        $domain2 = DomainFactory::new()->verified()->create([
            'owner_type' => 'team',
            'owner_id' => $team2->id,
        ]);

        $domain2->markAsPrimary();

        $domain1->refresh();

        // Team 1's primary domain should remain primary
        $this->assertTrue($domain1->is_primary);
        $this->assertTrue($domain2->is_primary);
    }

    // -----------------------------------------------------------------
    // generateVerificationToken()
    // -----------------------------------------------------------------

    public function test_generate_verification_token_returns_plaintext(): void
    {
        $domain = DomainFactory::new()->create();

        $plaintext = $domain->generateVerificationToken();

        $this->assertIsString($plaintext);
        $this->assertNotEmpty($plaintext);
        // Should be 64 hex characters (32 bytes)
        $this->assertSame(64, strlen($plaintext));
    }

    public function test_generate_verification_token_stores_plaintext_in_database(): void
    {
        $domain = DomainFactory::new()->create();

        $plaintext = $domain->generateVerificationToken();

        $rawValue = DB::table('domains')
            ->where('id', $domain->id)
            ->value('verification_token');

        $this->assertSame($plaintext, $rawValue);
    }

    // -----------------------------------------------------------------
    // getDnsRecordName()
    // -----------------------------------------------------------------

    public function test_get_dns_record_name_returns_correct_format(): void
    {
        $domain = DomainFactory::new()->create([
            'domain' => 'example.com',
        ]);

        $this->assertSame('_neev-verification.example.com', $domain->getDnsRecordName());
    }

    public function test_get_dns_record_name_works_with_subdomain(): void
    {
        $domain = DomainFactory::new()->create([
            'domain' => 'sub.example.com',
        ]);

        $this->assertSame('_neev-verification.sub.example.com', $domain->getDnsRecordName());
    }

    // -----------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------

    public function test_owner_relationship(): void
    {
        $team = TeamFactory::new()->create();
        $domain = DomainFactory::new()->create([
            'owner_type' => 'team',
            'owner_id' => $team->id,
        ]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\MorphTo::class, $domain->owner());
        $this->assertInstanceOf(Team::class, $domain->owner);
        $this->assertSame($team->id, $domain->owner->id);
    }

    public function test_rules_relationship(): void
    {
        $domain = DomainFactory::new()->create();

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $domain->rules());

        DomainRule::create([
            'domain_id' => $domain->id,
            'name' => 'mfa_required',
            'value' => 'true',
        ]);

        DomainRule::create([
            'domain_id' => $domain->id,
            'name' => 'password_policy',
            'value' => 'strict',
        ]);

        $domain->refresh();

        $this->assertCount(2, $domain->rules);
    }

    public function test_rule_method_returns_specific_rule_by_name(): void
    {
        $domain = DomainFactory::new()->create();

        DomainRule::create([
            'domain_id' => $domain->id,
            'name' => 'mfa_required',
            'value' => 'true',
        ]);

        DomainRule::create([
            'domain_id' => $domain->id,
            'name' => 'password_policy',
            'value' => 'strict',
        ]);

        $rule = $domain->rule('mfa_required');

        $this->assertNotNull($rule);
        $this->assertInstanceOf(DomainRule::class, $rule);
        $this->assertSame('mfa_required', $rule->name);
        $this->assertSame('true', $rule->value);
    }

    public function test_rule_method_returns_null_for_nonexistent_rule(): void
    {
        $domain = DomainFactory::new()->create();

        $rule = $domain->rule('nonexistent');

        $this->assertNull($rule);
    }

    // -----------------------------------------------------------------
    // hasVerificationFailure() / isVerificationStale()
    // -----------------------------------------------------------------

    public function test_has_verification_failure_returns_true_when_set(): void
    {
        $domain = DomainFactory::new()->create([
            'verification_failed_at' => now(),
        ]);

        $this->assertTrue($domain->hasVerificationFailure());
    }

    public function test_has_verification_failure_returns_false_when_null(): void
    {
        $domain = DomainFactory::new()->create();

        $this->assertFalse($domain->hasVerificationFailure());
    }

    public function test_is_verification_stale_returns_true_when_old(): void
    {
        $domain = DomainFactory::new()->verified()->create([
            'verified_at' => now()->subDays(31),
        ]);

        $this->assertTrue($domain->isVerificationStale(30));
    }

    public function test_is_verification_stale_returns_false_when_recent(): void
    {
        $domain = DomainFactory::new()->verified()->create([
            'verified_at' => now()->subDays(5),
        ]);

        $this->assertFalse($domain->isVerificationStale(30));
    }

    // -----------------------------------------------------------------
    // Platform zones
    // -----------------------------------------------------------------

    /**
     * The boundary between "a host we issued" and "somebody else's property".
     * Getting it wrong in the permissive direction hands an attacker a verified
     * claim on a domain they do not own, so the look-alike cases matter as much
     * as the happy path.
     *
     * @return array<string, array{0: string|null, 1: string, 2: bool}>
     */
    public static function platformHostProvider(): array
    {
        return [
            'host below the zone'            => ['otper.com', 'acme.otper.com', true],
            'deeper host below the zone'     => ['otper.com', 'eu.acme.otper.com', true],
            'the apex itself'                => ['otper.com', 'otper.com', false],
            'look-alike prefix'              => ['otper.com', 'evil-otper.com', false],
            'zone name used as a prefix'     => ['otper.com', 'otper.com.evil.com', false],
            'unrelated domain'               => ['otper.com', 'ssntpl.in', false],
            'suffix without the dot'         => ['otper.com', 'notperotper.com', false],
            'uppercase host'                 => ['otper.com', 'ACME.Otper.COM', true],
            'fully qualified trailing dot'   => ['otper.com', 'acme.otper.com.', true],
            'zone configured with a dot'     => ['.otper.com', 'acme.otper.com', true],
            'no zones configured'            => [null, 'acme.otper.com', false],
            'empty string configured'        => ['', 'acme.otper.com', false],
            'whitespace-only zone'           => ['   ', 'acme.otper.com', false],
            'empty host'                     => ['otper.com', '', false],
        ];
    }

    #[DataProvider('platformHostProvider')]
    public function test_platform_subdomain_matching(?string $configured, string $host, bool $expected): void
    {
        config(['neev.platform_domain' => $configured]);

        $this->assertSame($expected, Domain::isPlatformSubdomain($host));
    }

    /**
     * Several teams may hold pending claims on one domain; only a verified one
     * counts. Reading the first row of any kind and then testing it would
     * answer "no" whenever an unverified claim sorted first.
     */
    public function test_a_verified_claim_is_found_behind_an_unverified_one(): void
    {
        Domain::create(['owner_type' => 'team', 'owner_id' => 1, 'domain' => 'acme.com']);
        Domain::create([
            'owner_type' => 'team', 'owner_id' => 2, 'domain' => 'acme.com',
            'verified_at' => now(),
        ]);

        $this->assertTrue(Domain::isVerifiedForEmail('someone@acme.com'));
    }

    public function test_an_unverified_claim_alone_is_not_enough(): void
    {
        Domain::create(['owner_type' => 'team', 'owner_id' => 1, 'domain' => 'acme.com']);

        $this->assertFalse(Domain::isVerifiedForEmail('someone@acme.com'));
    }

    public function test_an_address_without_a_domain_is_not_verified(): void
    {
        $this->assertFalse(Domain::isVerifiedForEmail('not-an-address'));
    }

    /**
     * The decision, the uniqueness reservation and the resolution lookup must
     * compare the same spelling. Stored as written, `acme.otper.com.` is a
     * different string from `acme.otper.com`, so a second team could claim an
     * alias of a host another team already holds.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function hostAliasProvider(): array
    {
        return [
            'trailing dot'   => ['acme.otper.com.', 'acme.otper.com'],
            'several dots'   => ['acme.otper.com..', 'acme.otper.com'],
            'leading dot'    => ['.acme.otper.com', 'acme.otper.com'],
            'uppercase'      => ['ACME.Otper.COM', 'acme.otper.com'],
            'surrounding ws' => ["  acme.otper.com \t", 'acme.otper.com'],
            'already canonical' => ['acme.otper.com', 'acme.otper.com'],
        ];
    }

    #[DataProvider('hostAliasProvider')]
    public function test_a_host_is_stored_in_one_canonical_spelling(string $written, string $stored): void
    {
        $domain = Domain::create([
            'owner_type' => 'team',
            'owner_id' => 1,
            'domain' => $written,
        ]);

        $this->assertSame($stored, $domain->fresh()->domain);
    }

    /**
     * The claim is anchored to the claimant's own identity, not just the zone.
     *
     * @return array<string, array{0: string|null, 1: string, 2: string|null, 3: bool}>
     */
    public static function issuedHostProvider(): array
    {
        return [
            'the team\'s own subdomain'      => ['otper.com', 'acme.otper.com', 'acme', true],
            'an operational host'            => ['otper.com', 'app.otper.com', 'acme', false],
            'another team\'s subdomain'      => ['otper.com', 'other.otper.com', 'acme', false],
            'a deeper host under its own'    => ['otper.com', 'eu.acme.otper.com', 'acme', false],
            'the apex'                       => ['otper.com', 'otper.com', 'acme', false],
            'a look-alike zone'              => ['otper.com', 'acme.evil-otper.com', 'acme', false],
            'outside every zone'             => ['otper.com', 'acme.example.com', 'acme', false],
            'uppercase host'                 => ['otper.com', 'ACME.Otper.COM', 'acme', true],
            'fully qualified trailing dot'   => ['otper.com', 'acme.otper.com.', 'acme', true],
            'no slug'                        => ['otper.com', 'acme.otper.com', null, false],
            'empty slug'                     => ['otper.com', 'acme.otper.com', '', false],
            'no zones configured'            => [null, 'acme.otper.com', 'acme', false],
        ];
    }

    #[DataProvider('issuedHostProvider')]
    public function test_only_the_owners_own_subdomain_is_issued_to_it(?string $configured, string $host, ?string $slug, bool $expected): void
    {
        config(['neev.platform_domain' => $configured]);

        $this->assertSame($expected, Domain::isPlatformSubdomainFor($host, $slug));
    }
}
