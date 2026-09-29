<?php

namespace Ssntpl\Neev\Tests\Unit\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Database\Factories\DomainFactory;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Models\Tenant;
use Ssntpl\Neev\Tests\Support\FakeDns;
use Ssntpl\Neev\Tests\TestCase;

// Must load before any test calls Domain::verify(); see the file for why.
require_once __DIR__ . '/../../Support/FakeDns.php';

class VerifyDomainCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        FakeDns::reset();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // One claim — unchanged behaviour
    // -----------------------------------------------------------------

    public function test_force_verifies_the_only_claim(): void
    {
        $domain = DomainFactory::new()->create(['domain' => 'acme.com']);

        $this->artisan('neev:domain:verify', ['domain' => 'acme.com', '--force' => true])
            ->assertSuccessful();

        $this->assertNotNull($domain->fresh()->verified_at);
    }

    public function test_finds_the_claim_whatever_the_spelling(): void
    {
        $domain = DomainFactory::new()->create(['domain' => 'acme.com']);

        $this->artisan('neev:domain:verify', ['domain' => 'ACME.com.', '--force' => true])
            ->assertSuccessful();

        $this->assertNotNull($domain->fresh()->verified_at);
    }

    // -----------------------------------------------------------------
    // Several claims — the caller has to choose
    // -----------------------------------------------------------------

    public function test_refuses_to_guess_between_several_claims(): void
    {
        $first = DomainFactory::new()->create(['domain' => 'acme.com']);
        $second = DomainFactory::new()->create(['domain' => 'acme.com']);

        $this->artisan('neev:domain:verify', ['domain' => 'acme.com', '--force' => true])
            ->expectsOutputToContain('Domain is claimed by more than one owner')
            ->assertFailed();

        $this->assertNull($first->fresh()->verified_at);
        $this->assertNull($second->fresh()->verified_at);
    }

    public function test_owner_options_pick_one_claim(): void
    {
        $team = TeamFactory::new()->create();
        $other = DomainFactory::new()->create(['domain' => 'acme.com']);
        $mine = DomainFactory::new()->forTeam($team)->create(['domain' => 'acme.com']);

        $this->artisan('neev:domain:verify', [
            'domain' => 'acme.com',
            '--owner-type' => 'team',
            '--owner-id' => (string) $team->id,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertNotNull($mine->fresh()->verified_at);
        $this->assertNull($other->fresh()->verified_at);
    }

    public function test_owner_id_accepts_a_slug(): void
    {
        $team = TeamFactory::new()->create();
        DomainFactory::new()->create(['domain' => 'acme.com']);
        $mine = DomainFactory::new()->forTeam($team)->create(['domain' => 'acme.com']);

        $this->artisan('neev:domain:verify', [
            'domain' => 'acme.com',
            '--owner-type' => 'team',
            '--owner-id' => $team->slug,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertNotNull($mine->fresh()->verified_at);
    }

    public function test_owner_options_pick_a_tenant_claim(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $other = DomainFactory::new()->create(['domain' => 'acme.com']);
        $mine = DomainFactory::new()->create([
            'domain' => 'acme.com',
            'owner_type' => 'tenant',
            'owner_id' => $tenant->id,
        ]);

        $this->artisan('neev:domain:verify', [
            'domain' => 'acme.com',
            '--owner-type' => 'tenant',
            '--owner-id' => $tenant->slug,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertNotNull($mine->fresh()->verified_at);
        $this->assertNull($other->fresh()->verified_at);
    }

    public function test_owner_id_needs_owner_type(): void
    {
        DomainFactory::new()->create(['domain' => 'acme.com']);

        $this->artisan('neev:domain:verify', ['domain' => 'acme.com', '--owner-id' => '1'])
            ->expectsOutputToContain('--owner-id needs --owner-type')
            ->assertFailed();
    }

    // -----------------------------------------------------------------
    // DNS check
    // -----------------------------------------------------------------

    public function test_verifies_when_the_txt_record_matches(): void
    {
        $domain = DomainFactory::new()->create(['domain' => 'acme.com']);
        FakeDns::txt('_neev-verification.acme.com', $domain->generateVerificationToken());

        $this->artisan('neev:domain:verify', ['domain' => 'acme.com'])
            ->expectsOutputToContain('Domain verified successfully: acme.com')
            ->assertSuccessful();

        $this->assertNotNull($domain->fresh()->verified_at);
    }

    public function test_fails_when_the_txt_record_does_not_match(): void
    {
        $domain = DomainFactory::new()->create(['domain' => 'acme.com']);
        $domain->generateVerificationToken();
        FakeDns::txt('_neev-verification.acme.com', 'someone-elses-token');

        $this->artisan('neev:domain:verify', ['domain' => 'acme.com'])
            ->expectsOutputToContain('DNS verification failed.')
            ->assertFailed();

        $this->assertNull($domain->fresh()->verified_at);
    }

    // -----------------------------------------------------------------
    // --force keeps the first-owner-wins rule
    // -----------------------------------------------------------------

    public function test_verify_refuses_a_host_another_owner_already_verified(): void
    {
        $team = TeamFactory::new()->create();
        DomainFactory::new()->verified()->create(['domain' => 'acme.com']);
        $claim = DomainFactory::new()->forTeam($team)->create(['domain' => 'acme.com']);

        $this->artisan('neev:domain:verify', [
            'domain' => 'acme.com',
            '--owner-type' => 'team',
            '--owner-id' => (string) $team->id,
        ])
            ->expectsOutputToContain('This domain is already verified by another team.')
            ->assertFailed();

        $this->assertNull($claim->fresh()->verified_at);
    }

    public function test_rejects_an_unknown_owner_type(): void
    {
        DomainFactory::new()->create(['domain' => 'acme.com']);

        $this->artisan('neev:domain:verify', ['domain' => 'acme.com', '--owner-type' => 'group'])
            ->expectsOutputToContain('--owner-type must be "team" or "tenant".')
            ->assertFailed();
    }

    public function test_force_refuses_a_host_another_owner_already_verified(): void
    {
        $team = TeamFactory::new()->create();
        DomainFactory::new()->verified()->create(['domain' => 'acme.com']);
        $claim = DomainFactory::new()->forTeam($team)->create(['domain' => 'acme.com']);

        $this->artisan('neev:domain:verify', [
            'domain' => 'acme.com',
            '--owner-type' => 'team',
            '--owner-id' => (string) $team->id,
            '--force' => true,
        ])
            ->expectsOutputToContain('This domain is already verified by another team.')
            ->assertFailed();

        $this->assertNull($claim->fresh()->verified_at);
    }
}
