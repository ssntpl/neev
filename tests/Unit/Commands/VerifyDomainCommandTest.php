<?php

namespace Ssntpl\Neev\Tests\Unit\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Database\Factories\DomainFactory;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Tests\TestCase;

class VerifyDomainCommandTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_owner_id_needs_owner_type(): void
    {
        DomainFactory::new()->create(['domain' => 'acme.com']);

        $this->artisan('neev:domain:verify', ['domain' => 'acme.com', '--owner-id' => '1'])
            ->expectsOutputToContain('--owner-id needs --owner-type')
            ->assertFailed();
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
