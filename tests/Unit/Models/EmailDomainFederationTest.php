<?php

namespace Ssntpl\Neev\Tests\Unit\Models;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Database\Factories\TenantFactory;
use Ssntpl\Neev\Models\EmailDomain;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\RegistrationService;
use Ssntpl\Neev\Services\TenantResolver;
use Ssntpl\Neev\Tests\Support\FakeDns;
use Ssntpl\Neev\Tests\TestCase;

// Must load before any test calls verify(); see the file for why.
require_once __DIR__ . '/../../Support/FakeDns.php';

/**
 * RFC 006 phase 3: email federation reads `email_domains` only, so a host the
 * app is served at grants nothing to addresses at it (§3 (a)).
 */
class EmailDomainFederationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['neev.team' => true]);
    }

    protected function tearDown(): void
    {
        FakeDns::reset();

        parent::tearDown();
    }

    private function register(string $email): User
    {
        return app(RegistrationService::class)->register([
            'name' => 'Alice Example',
            'email' => $email,
            'password' => 'Password123!',
        ]);
    }

    public function test_a_verified_custom_host_does_not_federate_addresses_at_it(): void
    {
        $this->verifiedHost(TeamFactory::new()->create(), 'acme.com');

        $user = $this->register('alice@acme.com');

        $this->assertFalse(EmailDomain::isVerifiedForEmail('alice@acme.com'));
        $this->assertSame(1, $user->ownedTeams()->count(), 'A sign-up at a served host still gets a personal team.');
    }

    public function test_a_verified_email_domain_federates_addresses_at_it(): void
    {
        $team = TeamFactory::new()->create();
        $this->proven($team->federateDomain('acme.com', false));

        $user = $this->register('Alice@ACME.com');

        $this->assertTrue(EmailDomain::isVerifiedForEmail('Alice@ACME.com'));
        $this->assertSame(0, $user->ownedTeams()->count());
    }

    public function test_a_pending_email_domain_federates_nobody(): void
    {
        TeamFactory::new()->create()->federateDomain('acme.com', false);

        $this->assertFalse(EmailDomain::isVerifiedForEmail('alice@acme.com'));
    }

    public function test_two_teams_may_both_verify_one_email_domain(): void
    {
        $one = TeamFactory::new()->create()->federateDomain('acme.com', true);
        $two = TeamFactory::new()->create()->federateDomain('acme.com', false);
        FakeDns::txt('_neev-email.acme.com', $one->verification_token, $two->verification_token);

        $this->assertTrue($one->verify());
        $this->assertTrue($two->verify());
        $this->assertSame(2, EmailDomain::forHost('acme.com')->verified()->count());
    }

    /**
     * The enforcedElsewhere() check and the write happen under a lock on the
     * domain, so two owners verifying at once cannot both end up enforcing.
     */
    public function test_a_save_that_may_start_enforcing_holds_the_domain_lock(): void
    {
        $domain = TeamFactory::new()->create()->federateDomain('acme.com', true);
        FakeDns::txt('_neev-email.acme.com', $domain->verification_token);
        $locked = null;
        EmailDomain::saving(function () use (&$locked) {
            $lock = Cache::lock('neev:email-domain-enforce:acme.com', 10);
            $locked = ! $lock->get();
            $locked || $lock->release();
        });

        $this->assertTrue($domain->verify());
        $this->assertTrue($locked);
    }

    /**
     * A double submit creates the row between the first request's lookup and
     * its insert; the loser re-issues the token on that row instead of failing
     * on the unique index.
     */
    public function test_federating_a_domain_created_meanwhile_reissues_its_token(): void
    {
        $team = TeamFactory::new()->create();
        $raced = false;
        EmailDomain::creating(function (EmailDomain $domain) use (&$raced) {
            if (! $raced) {
                $raced = true;
                DB::table('email_domains')->insert([
                    'owner_type' => $domain->owner_type,
                    'owner_id' => $domain->owner_id,
                    'domain' => $domain->domain,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        $domain = $team->federateDomain('acme.com', false);

        $this->assertFalse($domain->wasRecentlyCreated);
        $this->assertNotEmpty($domain->verification_token);
        $this->assertSame(1, $team->emailDomains()->count());
    }

    public function test_an_email_domain_is_proven_by_its_own_record_name(): void
    {
        $domain = TeamFactory::new()->create()->federateDomain('acme.com', false);
        FakeDns::txt('_neev-host.acme.com', $domain->verification_token);

        $this->assertFalse($domain->verify());
        $this->assertSame('_neev-email.acme.com', $domain->getDnsRecordName());
    }

    public function test_enforcement_is_read_from_verified_email_domains(): void
    {
        $team = TeamFactory::new()->create();
        $this->verifiedHost($team, 'acme.com');
        $this->assertFalse($team->enforcesDomain());
        $this->assertTrue($team->acceptsJoinRequests());

        $team->federateDomain('acme.com', true);
        $this->assertFalse($team->enforcesDomain(), 'A pending domain enforces nothing.');

        $this->proven($team->emailDomains()->first());
        // The checks read the loaded relation, like a page asking per member.
        $team->refresh();
        $this->assertTrue($team->enforcesDomain());
        $this->assertTrue($team->hasVerifiedDomainFor('bob@acme.com'));
        $this->assertFalse($team->acceptsJoinRequests());
    }

    public function test_under_isolation_a_claim_federates_only_inside_its_own_tenant(): void
    {
        config(['neev.tenant' => true]);
        $tenantA = TenantFactory::new()->create();
        $tenantB = TenantFactory::new()->create();
        $this->proven(TeamFactory::new()->create(['tenant_id' => $tenantA->id])->federateDomain('acme.com', false));
        $this->proven($tenantB->emailDomains()->create(['domain' => 'globex.com']));
        $resolver = app(TenantResolver::class);

        $this->assertTrue($resolver->runInContext($tenantA, fn () => EmailDomain::isVerifiedForEmail('alice@acme.com')));
        $this->assertFalse($resolver->runInContext($tenantB, fn () => EmailDomain::isVerifiedForEmail('alice@acme.com')), 'Tenant A\'s team does not federate tenant B\'s sign-ups.');
        $this->assertTrue($resolver->runInContext($tenantB, fn () => EmailDomain::isVerifiedForEmail('bob@globex.com')), 'The tenant\'s own claim counts.');
        $this->assertFalse($resolver->runInContext($tenantA, fn () => EmailDomain::isVerifiedForEmail('bob@globex.com')));
    }
}
