<?php

namespace Ssntpl\Neev\Tests\Unit\Models;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Ssntpl\Neev\Database\Factories\DomainFactory;
use Ssntpl\Neev\Database\Factories\TeamFactory;
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
    // markAsPrimary()
    // -----------------------------------------------------------------

    public function test_mark_as_primary_unsets_other_primary_domains_for_same_team(): void
    {
        $team = TeamFactory::new()->create();

        $domain1 = DomainFactory::new()->primary()->verified()->create([
            'team_id' => $team->id,
            'domain' => 'first.example.com',
        ]);

        $domain2 = DomainFactory::new()->verified()->create([
            'team_id' => $team->id,
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

    public function test_mark_as_primary_does_not_affect_other_teams(): void
    {
        $team1 = TeamFactory::new()->create();
        $team2 = TeamFactory::new()->create();

        $domain1 = DomainFactory::new()->primary()->verified()->create([
            'team_id' => $team1->id,
        ]);

        $domain2 = DomainFactory::new()->verified()->create([
            'team_id' => $team2->id,
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

    public function test_generate_verification_token_hashes_token_in_database(): void
    {
        $domain = DomainFactory::new()->create();

        $plaintext = $domain->generateVerificationToken();

        $rawValue = DB::table('domains')
            ->where('id', $domain->id)
            ->value('verification_token');

        $this->assertNotSame($plaintext, $rawValue);
        $this->assertTrue(Hash::check($plaintext, $rawValue));
    }

    // -----------------------------------------------------------------
    // verify()
    // -----------------------------------------------------------------

    public function test_verify_with_correct_token_sets_verified_at_and_returns_true(): void
    {
        $domain = DomainFactory::new()->create();

        $plaintext = $domain->generateVerificationToken();

        $this->assertNull($domain->verified_at);

        $result = $domain->verify($plaintext);

        $this->assertTrue($result);

        $domain->refresh();
        $this->assertNotNull($domain->verified_at);
        // verification_token should be cleared
        $rawToken = DB::table('domains')
            ->where('id', $domain->id)
            ->value('verification_token');
        $this->assertNull($rawToken);
    }

    public function test_verify_with_wrong_token_returns_false(): void
    {
        $domain = DomainFactory::new()->create();

        $domain->generateVerificationToken();

        $result = $domain->verify('wrong-token');

        $this->assertFalse($result);

        $domain->refresh();
        $this->assertNull($domain->verified_at);
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

    public function test_team_relationship(): void
    {
        $team = TeamFactory::new()->create();
        $domain = DomainFactory::new()->create(['team_id' => $team->id]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class, $domain->team());
        $this->assertInstanceOf(Team::class, $domain->team);
        $this->assertSame($team->id, $domain->team->id);
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
}
