<?php

namespace Ssntpl\Neev\Tests\Unit\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Ssntpl\Neev\Database\Factories\HostnameFactory;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Database\Factories\TenantFactory;
use Ssntpl\Neev\Jobs\VerifyDomainJob;
use Ssntpl\Neev\Models\Hostname;
use Ssntpl\Neev\Models\Tenant;
use Ssntpl\Neev\Tests\Support\FakeDns;
use Ssntpl\Neev\Tests\TestCase;
use Symfony\Component\Console\Exception\InvalidOptionException;

// Must load before any test calls verify(); see the file for why.
require_once __DIR__ . '/../../Support/FakeDns.php';

class HostnameCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        FakeDns::reset();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // neev:hostname:add
    // -----------------------------------------------------------------

    public function test_add_claims_a_pending_host(): void
    {
        $team = TeamFactory::new()->create();

        $this->artisan('neev:hostname:add', [
            'host' => 'APP.acme.com.',
            '--owner-type' => 'team',
            '--owner-id' => (string) $team->id,
        ])
            ->expectsOutputToContain('Host added: app.acme.com')
            ->expectsOutputToContain('_neev-host.app.acme.com')
            ->expectsOutputToContain('neev:hostname:verify app.acme.com')
            ->assertSuccessful();

        $hostname = Hostname::forHost('app.acme.com')->sole();
        $this->assertTrue($hostname->isOwnedBy($team));
        $this->assertFalse($hostname->isVerified());
        $this->assertNotNull($hostname->verification_token);
        $this->assertNull($team->fresh()->primary_hostname_id);
        $this->assertDatabaseCount('email_domains', 0);
    }

    public function test_a_tenant_host_is_added_verified_then_made_primary(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);

        $this->artisan('neev:hostname:add', ['host' => 'app.acme.com', '--owner-type' => 'tenant', '--owner-id' => 'acme'])
            ->assertSuccessful();
        $this->artisan('neev:hostname:primary', ['host' => 'app.acme.com'])->assertFailed();

        FakeDns::txt('_neev-host.app.acme.com', Hostname::forHost('app.acme.com')->sole()->verification_token);
        $this->artisan('neev:hostname:verify', ['host' => 'app.acme.com'])->assertSuccessful();
        $this->artisan('neev:hostname:primary', ['host' => 'app.acme.com'])->assertSuccessful();

        $this->assertSame('app.acme.com', $tenant->fresh()->canonicalHost());
    }

    public function test_add_cannot_skip_dns(): void
    {
        $team = TeamFactory::new()->create();

        $this->expectException(InvalidOptionException::class);

        $this->artisan('neev:hostname:add', [
            'host' => 'app.acme.com',
            '--owner-type' => 'team',
            '--owner-id' => (string) $team->id,
            '--skip-verification' => true,
        ]);
    }

    public function test_add_refuses_a_host_under_the_platform_zone(): void
    {
        config(['neev.platform_domain' => 'neev.test']);
        $team = TeamFactory::new()->create();

        $this->artisan('neev:hostname:add', ['host' => 'acme.neev.test', '--owner-type' => 'team', '--owner-id' => (string) $team->id])
            ->expectsOutputToContain('platform domain')
            ->assertFailed();

        $this->assertDatabaseCount('hostnames', 0);
    }

    public function test_add_refuses_a_host_another_owner_holds(): void
    {
        $holder = TeamFactory::new()->create();
        $holder->claimHost('app.acme.com');
        $team = TeamFactory::new()->create();

        $this->artisan('neev:hostname:add', [
            'host' => 'app.acme.com',
            '--owner-type' => 'team',
            '--owner-id' => (string) $team->id,
        ])
            ->expectsOutputToContain('This host cannot be added. (app.acme.com)')
            ->assertFailed();

        $hostname = Hostname::forHost('app.acme.com')->sole();
        $this->assertTrue($hostname->isOwnedBy($holder));
        $this->assertFalse($hostname->isVerified());
    }

    public function test_add_refuses_a_host_the_owner_already_has(): void
    {
        $team = TeamFactory::new()->create();
        $team->claimHost('app.acme.com');

        $this->artisan('neev:hostname:add', ['host' => 'app.acme.com', '--owner-type' => 'team', '--owner-id' => (string) $team->id])
            ->expectsOutputToContain('Host already added for this team: app.acme.com')
            ->assertFailed();
    }

    public function test_add_asks_for_the_host_when_it_is_missing(): void
    {
        $team = TeamFactory::new()->create();

        $this->artisan('neev:hostname:add', ['--owner-type' => 'team', '--owner-id' => (string) $team->id])
            ->expectsQuestion('What host would you like to add?', 'app.acme.com')
            ->expectsOutputToContain('Host added: app.acme.com')
            ->assertSuccessful();

        $this->assertTrue(Hostname::forHost('app.acme.com')->sole()->isOwnedBy($team));
    }

    public function test_add_asks_for_the_owner_when_it_is_missing(): void
    {
        $team = TeamFactory::new()->create();

        $this->artisan('neev:hostname:add', ['host' => 'app.acme.com'])
            ->expectsQuestion('What owns it?', 'team')
            ->expectsQuestion('Which team? (ID or slug)', $team->slug)
            ->expectsOutputToContain('Host added: app.acme.com')
            ->assertSuccessful();

        $this->assertTrue(Hostname::forHost('app.acme.com')->sole()->isOwnedBy($team));
    }

    public function test_add_needs_an_owner(): void
    {
        $this->artisan('neev:hostname:add', ['host' => 'app.acme.com', '--no-interaction' => true])
            ->expectsOutputToContain('You must specify --owner-type and --owner-id.')
            ->assertFailed();
    }

    // -----------------------------------------------------------------
    // neev:hostname:list
    // -----------------------------------------------------------------

    public function test_list_shows_hosts_with_the_primary_marked(): void
    {
        $team = TeamFactory::new()->create();
        $primary = HostnameFactory::new()->forOwner($team)->verified()->create(['host' => 'app.acme.com']);
        $team->makePrimaryHostname($primary);
        $other = HostnameFactory::new()->forOwner($team)->create(['host' => 'eu.acme.com', 'created_at' => now()->subDay()]);

        $this->artisan('neev:hostname:list')
            ->expectsTable(
                ['ID', 'Host', 'Owner Type', 'Owner ID', 'Primary', 'Status'],
                [
                    [$primary->id, 'app.acme.com', 'team', $team->id, 'Yes', 'Verified'],
                    [$other->id, 'eu.acme.com', 'team', $team->id, 'No', 'Pending'],
                ],
            )
            ->assertSuccessful();
    }

    public function test_list_filters_by_owner_slug_and_unverified(): void
    {
        $team = TeamFactory::new()->create();
        HostnameFactory::new()->forOwner($team)->create(['host' => 'mine.acme.com']);
        HostnameFactory::new()->forOwner($team)->verified()->create(['host' => 'proven.acme.com']);
        HostnameFactory::new()->create(['host' => 'other.acme.com']);

        $this->artisan('neev:hostname:list', ['--owner-type' => 'team', '--owner-id' => $team->slug, '--unverified' => true])
            ->expectsOutputToContain('mine.acme.com')
            ->doesntExpectOutputToContain('proven.acme.com')
            ->doesntExpectOutputToContain('other.acme.com')
            ->assertSuccessful();
    }

    public function test_list_reports_when_no_host_matches(): void
    {
        HostnameFactory::new()->verified()->create(['host' => 'proven.acme.com']);

        $this->artisan('neev:hostname:list', ['--unverified' => true])
            ->expectsOutputToContain('No hosts found.')
            ->doesntExpectOutputToContain('proven.acme.com')
            ->assertSuccessful();
    }

    public function test_list_prints_json(): void
    {
        $team = TeamFactory::new()->create();
        HostnameFactory::new()->forOwner($team)->create(['host' => 'app.acme.com']);

        $this->assertSame(0, Artisan::call('neev:hostname:list', ['--json' => true]));

        $rows = json_decode(Artisan::output(), true);
        $this->assertCount(1, $rows);
        $this->assertSame('app.acme.com', $rows[0]['host']);
        $this->assertSame($team->id, $rows[0]['owner_id']);
        $this->assertArrayNotHasKey('verification_token', $rows[0]);
    }

    public function test_list_rejects_an_unknown_owner_type(): void
    {
        $this->artisan('neev:hostname:list', ['--owner-type' => 'group'])
            ->expectsOutputToContain('--owner-type must be "team" or "tenant".')
            ->assertFailed();
    }

    public function test_list_needs_the_owner_type_with_a_slug(): void
    {
        $this->artisan('neev:hostname:list', ['--owner-id' => 'acme'])
            ->expectsOutputToContain('A slug in --owner-id needs --owner-type team or tenant.')
            ->assertFailed();
    }

    // -----------------------------------------------------------------
    // neev:hostname:verify
    // -----------------------------------------------------------------

    public function test_verify_checks_the_host_record(): void
    {
        $hostname = TeamFactory::new()->create()->claimHost('app.acme.com');
        FakeDns::txt('_neev-host.app.acme.com', $hostname->verification_token);

        $this->artisan('neev:hostname:verify', ['host' => 'APP.acme.com'])
            ->expectsOutputToContain('Checking DNS TXT record: _neev-host.app.acme.com')
            ->expectsOutputToContain('Host verified successfully: app.acme.com')
            ->assertSuccessful();

        $this->assertTrue($hostname->fresh()->isVerified());
    }

    public function test_verify_fails_when_the_record_does_not_match(): void
    {
        $hostname = TeamFactory::new()->create()->claimHost('app.acme.com');
        FakeDns::txt('_neev-host.app.acme.com', 'someone-elses-token');

        $this->artisan('neev:hostname:verify', ['host' => 'app.acme.com'])
            ->expectsOutputToContain('DNS verification failed.')
            ->assertFailed();

        $this->assertFalse($hostname->fresh()->isVerified());
    }

    public function test_verify_cannot_skip_dns(): void
    {
        TeamFactory::new()->create()->claimHost('app.acme.com');

        $this->expectException(InvalidOptionException::class);

        $this->artisan('neev:hostname:verify', ['host' => 'app.acme.com', '--force' => true]);
    }

    public function test_verify_all_queues_a_recheck_for_each_verified_or_failing_host(): void
    {
        Bus::fake();
        $verified = HostnameFactory::new()->verified()->create(['host' => 'proven.acme.com']);
        $failing = HostnameFactory::new()->create(['host' => 'lapsed.acme.com', 'status' => Hostname::STATUS_FAILED]);
        HostnameFactory::new()->create(['host' => 'pending.acme.com']);

        $this->artisan('neev:hostname:verify', ['--all' => true])
            ->expectsOutputToContain('Dispatched verification jobs for all verified and failing hosts.')
            ->assertSuccessful();

        Bus::assertDispatchedTimes(VerifyDomainJob::class, 2);
        Bus::assertDispatched(VerifyDomainJob::class, fn (VerifyDomainJob $job) => $job->domain->is($verified));
        Bus::assertDispatched(VerifyDomainJob::class, fn (VerifyDomainJob $job) => $job->domain->is($failing));
    }

    public function test_verify_needs_a_host_or_all(): void
    {
        $this->artisan('neev:hostname:verify')
            ->expectsOutputToContain('You must specify a host or use --all.')
            ->assertFailed();
    }

    public function test_verify_reports_an_unknown_host(): void
    {
        $this->artisan('neev:hostname:verify', ['host' => 'app.acme.com'])
            ->expectsOutputToContain('Host not found: app.acme.com')
            ->assertFailed();
    }

    // -----------------------------------------------------------------
    // neev:hostname:primary
    // -----------------------------------------------------------------

    public function test_primary_points_the_owner_at_a_verified_host(): void
    {
        $team = TeamFactory::new()->create();
        $this->verifiedHost($team, 'app.acme.com');

        $this->artisan('neev:hostname:primary', ['host' => 'APP.acme.com'])
            ->expectsOutputToContain('Primary host set: app.acme.com')
            ->assertSuccessful();

        $this->assertSame('app.acme.com', $team->fresh()->primaryHostname->host);
    }

    public function test_primary_refuses_a_pending_host(): void
    {
        $team = TeamFactory::new()->create();
        $team->claimHost('app.acme.com');

        $this->artisan('neev:hostname:primary', ['host' => 'app.acme.com'])
            ->expectsOutputToContain('Only a verified host can be primary: app.acme.com')
            ->assertFailed();

        $this->assertNull($team->fresh()->primary_hostname_id);
    }

    public function test_primary_and_list_find_a_team_inside_a_tenant_under_isolation(): void
    {
        config(['neev.tenant' => true]);
        $team = TeamFactory::new()->create(['tenant_id' => TenantFactory::new()->create()->id]);
        HostnameFactory::new()->forOwner($team)->verified()->create(['host' => 'app.acme.com']);

        $this->artisan('neev:hostname:primary', ['host' => 'app.acme.com'])
            ->expectsOutputToContain('Primary host set: app.acme.com')
            ->assertSuccessful();

        $this->artisan('neev:hostname:list')
            ->expectsTable(
                ['ID', 'Host', 'Owner Type', 'Owner ID', 'Primary', 'Status'],
                [[Hostname::forHost('app.acme.com')->value('id'), 'app.acme.com', 'team', $team->id, 'Yes', 'Verified']],
            )
            ->assertSuccessful();
    }

    public function test_primary_reports_an_unknown_host(): void
    {
        $this->artisan('neev:hostname:primary', ['host' => 'app.acme.com'])
            ->expectsOutputToContain('Host not found: app.acme.com')
            ->assertFailed();
    }

    public function test_primary_refuses_a_host_whose_owner_is_gone(): void
    {
        // No foreign key ties a host to its owner, so a host can outlive it.
        HostnameFactory::new()->verified()->create(['host' => 'app.acme.com', 'owner_id' => 999999]);

        $this->artisan('neev:hostname:primary', ['host' => 'app.acme.com'])
            ->expectsOutputToContain('The owner of app.acme.com keeps no primary host.')
            ->assertFailed();
    }
}
