<?php

namespace Ssntpl\Neev\Tests\Unit\Models;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Ssntpl\Neev\Database\Factories\EmailDomainFactory;
use Ssntpl\Neev\Database\Factories\HostnameFactory;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Exceptions\DomainAlreadyVerifiedException;
use Ssntpl\Neev\Models\Domain;
use Ssntpl\Neev\Models\Hostname;
use Ssntpl\Neev\Tests\TestCase;

/**
 * Domain is the deprecated reader of the old `domains` table (RFC 006): rows
 * can be read for copying, never written, and its static helpers answer from
 * hostnames and email_domains.
 */
class DomainTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A row as an install upgraded from before RFC 006 holds it.
     */
    private function insertDomain(array $attributes = []): Domain
    {
        $id = DB::table('domains')->insertGetId($attributes + [
            'owner_type' => 'team',
            'owner_id' => 1,
            'domain' => 'acme.com',
            'verification_token' => 'old-token',
            'is_primary' => false,
            'enforce' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Domain::findOrFail($id);
    }

    // -----------------------------------------------------------------
    // Read-only
    // -----------------------------------------------------------------

    public function test_existing_rows_can_be_read_for_copying(): void
    {
        $team = TeamFactory::new()->create();
        $domain = $this->insertDomain([
            'owner_id' => $team->id,
            'enforce' => true,
            'is_primary' => true,
            'verified_at' => now(),
        ]);

        $this->assertSame('acme.com', $domain->domain);
        $this->assertTrue($domain->enforce);
        $this->assertTrue($domain->is_primary);
        $this->assertTrue($domain->isVerified());
        $this->assertFalse($domain->hasVerificationFailure());
        $this->assertTrue($domain->owner->is($team));
        $this->assertSame('_neev-verification.acme.com', $domain->getDnsRecordName());
        $this->assertArrayNotHasKey('verification_token', $domain->toArray());
    }

    public function test_a_new_row_is_refused(): void
    {
        $this->expectException(LogicException::class);

        $domain = new Domain();
        $domain->forceFill(['owner_type' => 'team', 'owner_id' => 1, 'domain' => 'acme.com']);
        $domain->save();
    }

    public function test_an_existing_row_cannot_be_changed(): void
    {
        $domain = $this->insertDomain();

        try {
            $domain->forceFill(['verified_at' => now()])->save();
            $this->fail('Expected LogicException.');
        } catch (LogicException) {
        }

        $this->assertNull(DB::table('domains')->value('verified_at'));
    }

    public function test_an_existing_row_cannot_be_deleted(): void
    {
        $domain = $this->insertDomain();

        try {
            $domain->delete();
            $this->fail('Expected LogicException.');
        } catch (LogicException) {
        }

        $this->assertSame(1, DB::table('domains')->count());
    }

    public function test_verification_is_stale_only_after_the_given_days(): void
    {
        $this->assertFalse($this->insertDomain()->isVerificationStale(0), 'An unverified row is never stale.');

        $domain = $this->insertDomain(['domain' => 'old.acme.com', 'verified_at' => now()->subDays(10)]);

        $this->assertTrue($domain->isVerificationStale(7));
        $this->assertFalse($domain->isVerificationStale(30));
    }

    public function test_the_deprecated_already_verified_exception_names_the_owner_kind(): void
    {
        $this->assertSame(
            'This domain is already verified by another team.',
            (new DomainAlreadyVerifiedException())->getMessage(),
        );
        $this->assertSame(
            'This domain is already verified by another tenant.',
            (new DomainAlreadyVerifiedException('tenant'))->getMessage(),
        );
    }

    // -----------------------------------------------------------------
    // Reads delegated to the new tables
    // -----------------------------------------------------------------

    /**
     * The bug RFC 006 removes: a verified `domains` row once federated every
     * address at it. Federation is read from email_domains only.
     */
    public function test_is_verified_for_email_reads_email_domains_only(): void
    {
        $this->insertDomain(['verified_at' => now()]);

        $this->assertFalse(Domain::isVerifiedForEmail('someone@acme.com'));

        EmailDomainFactory::new()->verified()->create(['domain' => 'acme.com']);

        $this->assertTrue(Domain::isVerifiedForEmail('Someone@ACME.com'));
        $this->assertFalse(Domain::isVerifiedForEmail('not-an-address'));
    }

    public function test_host_lookups_read_verified_hostnames_only(): void
    {
        $team = TeamFactory::new()->create();
        $this->insertDomain(['owner_id' => $team->id, 'domain' => 'old.acme.com', 'verified_at' => now()]);
        HostnameFactory::new()->forOwner($team)->create(['host' => 'pending.acme.com']);
        $hostname = HostnameFactory::new()->forOwner($team)->verified()->create(['host' => 'app.acme.com']);

        $this->assertNull(Domain::findByHost('old.acme.com'));
        $this->assertNull(Domain::findByHost('pending.acme.com'));

        foreach (['app.acme.com', 'APP.acme.com', 'app.acme.com.'] as $spelling) {
            $this->assertInstanceOf(Hostname::class, Domain::findByHost($spelling));
            $this->assertTrue($hostname->is(Domain::findByHost($spelling)), $spelling);
            $this->assertTrue($hostname->is(Domain::findByHostForOwnerType($spelling, $team->getMorphClass())), $spelling);
            $this->assertTrue($hostname->is(Domain::findByHostForOwner($spelling, $team->getMorphClass(), $team->id)), $spelling);
        }

        $this->assertNull(Domain::findByHostForOwnerType('app.acme.com', 'tenant'));
        $this->assertNull(Domain::findByHostForOwner('app.acme.com', $team->getMorphClass(), $team->id + 1));
    }

    public function test_rows_are_matched_in_any_spelling(): void
    {
        $this->insertDomain(['domain' => 'acme.com']);

        $this->assertTrue(Domain::forHost(' ACME.com. ')->exists());
    }

    public function test_platform_domain_is_the_canonical_zone(): void
    {
        config(['neev.platform_domain' => '.Otper.COM']);

        $this->assertSame('otper.com', Domain::platformDomain());

        config(['neev.platform_domain' => null]);

        $this->assertNull(Domain::platformDomain());
    }

    /**
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
            'uppercase host'                 => ['otper.com', 'ACME.Otper.COM', true],
            'fully qualified trailing dot'   => ['otper.com', 'acme.otper.com.', true],
            'no zone configured'             => [null, 'acme.otper.com', false],
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
     * @return array<string, array{0: string|null, 1: string, 2: string|null, 3: bool}>
     */
    public static function issuedHostProvider(): array
    {
        return [
            'the owner\'s own subdomain'     => ['otper.com', 'acme.otper.com', 'acme', true],
            'another owner\'s subdomain'     => ['otper.com', 'other.otper.com', 'acme', false],
            'a deeper host under its own'    => ['otper.com', 'eu.acme.otper.com', 'acme', false],
            'the apex'                       => ['otper.com', 'otper.com', 'acme', false],
            'uppercase host'                 => ['otper.com', 'ACME.Otper.COM', 'acme', true],
            'no slug'                        => ['otper.com', 'acme.otper.com', null, false],
            'no zone configured'             => [null, 'acme.otper.com', 'acme', false],
        ];
    }

    #[DataProvider('issuedHostProvider')]
    public function test_only_the_owners_own_subdomain_is_issued_to_it(?string $configured, string $host, ?string $slug, bool $expected): void
    {
        config(['neev.platform_domain' => $configured]);

        $this->assertSame($expected, Domain::isPlatformSubdomainFor($host, $slug));
    }
}
