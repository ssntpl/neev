<?php

namespace Ssntpl\Neev\Tests\Unit\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Database\Factories\DomainFactory;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Tests\TestCase;

class ListDomainsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_id_filters_by_id(): void
    {
        $team = TeamFactory::new()->create();
        DomainFactory::new()->create(['owner_type' => 'team', 'owner_id' => $team->id, 'domain' => 'mine.com']);
        DomainFactory::new()->create(['domain' => 'other.com']);

        $this->artisan('neev:domain:list', ['--owner-type' => 'team', '--owner-id' => (string) $team->id])
            ->expectsOutputToContain('mine.com')
            ->doesntExpectOutputToContain('other.com')
            ->assertSuccessful();
    }

    public function test_owner_id_accepts_a_slug(): void
    {
        $team = TeamFactory::new()->create();
        DomainFactory::new()->create(['owner_type' => 'team', 'owner_id' => $team->id, 'domain' => 'mine.com']);
        DomainFactory::new()->create(['domain' => 'other.com']);

        $this->artisan('neev:domain:list', ['--owner-type' => 'team', '--owner-id' => $team->slug])
            ->expectsOutputToContain('mine.com')
            ->doesntExpectOutputToContain('other.com')
            ->assertSuccessful();
    }

    public function test_a_slug_needs_the_owner_type(): void
    {
        $team = TeamFactory::new()->create();

        $this->artisan('neev:domain:list', ['--owner-id' => $team->slug])
            ->expectsOutputToContain('A slug in --owner-id needs --owner-type team or tenant.')
            ->assertFailed();
    }

    public function test_an_unknown_slug_is_reported(): void
    {
        $this->artisan('neev:domain:list', ['--owner-type' => 'team', '--owner-id' => 'no-such-team'])
            ->expectsOutputToContain('Team not found: no-such-team')
            ->assertFailed();
    }
}
