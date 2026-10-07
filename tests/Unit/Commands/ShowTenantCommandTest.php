<?php

namespace Ssntpl\Neev\Tests\Unit\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Ssntpl\Neev\Database\Factories\EmailDomainFactory;
use Ssntpl\Neev\Database\Factories\HostnameFactory;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Database\Factories\TenantFactory;
use Ssntpl\Neev\Tests\TestCase;

class ShowTenantCommandTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Isolated mode: a tenant
    // -----------------------------------------------------------------

    public function test_it_shows_a_tenant_with_its_hosts_and_email_domains(): void
    {
        config(['neev.tenant' => true]);
        $tenant = TenantFactory::new()->create(['name' => 'Acme', 'slug' => 'acme']);
        $primary = HostnameFactory::new()->forOwner($tenant)->verified()->create(['host' => 'app.acme.com']);
        $tenant->makePrimaryHostname($primary);
        HostnameFactory::new()->forOwner($tenant)->create(['host' => 'eu.acme.com']);
        EmailDomainFactory::new()->forOwner($tenant)->verified()->create(['domain' => 'acme.com', 'enforce' => true]);
        EmailDomainFactory::new()->forOwner($tenant)->create(['domain' => 'acme.org']);

        $this->artisan('neev:tenant:show', ['identifier' => 'acme'])
            ->expectsOutputToContain('Tenant: Acme')
            ->expectsOutputToContain('Hostnames:')
            ->expectsOutputToContain('- app.acme.com [verified] (primary)')
            ->expectsOutputToContain('- eu.acme.com [unverified]')
            ->expectsOutputToContain('Email domains:')
            ->expectsOutputToContain('- acme.com [verified] (enforced)')
            ->expectsOutputToContain('- acme.org [unverified]')
            ->doesntExpectOutputToContain('eu.acme.com [unverified] (primary)')
            ->doesntExpectOutputToContain('acme.org [unverified] (enforced)')
            ->assertSuccessful();
    }

    public function test_it_finds_a_tenant_by_its_custom_host(): void
    {
        config(['neev.tenant' => true]);
        $tenant = TenantFactory::new()->create(['name' => 'Acme']);
        HostnameFactory::new()->forOwner($tenant)->verified()->create(['host' => 'app.acme.com']);

        $this->artisan('neev:tenant:show', ['identifier' => 'app.acme.com'])
            ->expectsOutputToContain('Tenant: Acme')
            ->expectsOutputToContain("ID:     {$tenant->id}")
            ->assertSuccessful();
    }

    public function test_an_unknown_host_finds_no_tenant(): void
    {
        config(['neev.tenant' => true]);

        $this->artisan('neev:tenant:show', ['identifier' => 'app.acme.com'])
            ->expectsOutputToContain('Tenant not found: app.acme.com')
            ->assertFailed();
    }

    public function test_it_prints_a_tenant_as_json_with_its_hosts_and_email_domains(): void
    {
        config(['neev.tenant' => true]);
        $tenant = TenantFactory::new()->create(['slug' => 'acme']);
        HostnameFactory::new()->forOwner($tenant)->create(['host' => 'app.acme.com']);
        EmailDomainFactory::new()->forOwner($tenant)->create(['domain' => 'acme.com']);

        $this->assertSame(0, Artisan::call('neev:tenant:show', ['identifier' => (string) $tenant->id, '--json' => true]));

        $json = json_decode(Artisan::output(), true);
        $this->assertSame('acme', $json['slug']);
        $this->assertSame(['app.acme.com'], array_column($json['hostnames'], 'host'));
        $this->assertSame(['acme.com'], array_column($json['email_domains'], 'domain'));
        $this->assertArrayHasKey('teams', $json);
    }

    // -----------------------------------------------------------------
    // Shared mode: a team
    // -----------------------------------------------------------------

    public function test_it_shows_a_team_with_its_hosts_and_email_domains(): void
    {
        config(['neev.tenant' => false]);
        $team = TeamFactory::new()->create(['name' => 'Engineering']);
        $primary = HostnameFactory::new()->forOwner($team)->verified()->create(['host' => 'app.acme.com']);
        $team->makePrimaryHostname($primary);
        EmailDomainFactory::new()->forOwner($team)->create(['domain' => 'acme.com', 'enforce' => false]);

        $this->artisan('neev:tenant:show', ['identifier' => $team->slug])
            ->expectsOutputToContain('Team:    Engineering')
            ->expectsOutputToContain('- app.acme.com [verified] (primary)')
            ->expectsOutputToContain('- acme.com [unverified]')
            ->doesntExpectOutputToContain('(enforced)')
            ->assertSuccessful();
    }

    public function test_it_finds_a_team_by_its_custom_host(): void
    {
        config(['neev.tenant' => false]);
        $team = TeamFactory::new()->create(['name' => 'Engineering']);
        HostnameFactory::new()->forOwner($team)->verified()->create(['host' => 'app.acme.com']);

        $this->artisan('neev:tenant:show', ['identifier' => 'app.acme.com'])
            ->expectsOutputToContain('Team:    Engineering')
            ->expectsOutputToContain("ID:      {$team->id}")
            ->assertSuccessful();
    }

    public function test_an_unknown_host_finds_no_team(): void
    {
        config(['neev.tenant' => false]);

        $this->artisan('neev:tenant:show', ['identifier' => 'app.acme.com'])
            ->expectsOutputToContain('Team not found: app.acme.com')
            ->assertFailed();
    }

    public function test_it_prints_a_team_as_json_with_its_hosts_and_email_domains(): void
    {
        config(['neev.tenant' => false]);
        $team = TeamFactory::new()->create();
        HostnameFactory::new()->forOwner($team)->create(['host' => 'app.acme.com']);
        EmailDomainFactory::new()->forOwner($team)->create(['domain' => 'acme.com']);

        $this->assertSame(0, Artisan::call('neev:tenant:show', ['identifier' => (string) $team->id, '--json' => true]));

        $json = json_decode(Artisan::output(), true);
        $this->assertSame($team->slug, $json['slug']);
        $this->assertSame($team->user_id, $json['owner']['id']);
        $this->assertSame(['app.acme.com'], array_column($json['hostnames'], 'host'));
        $this->assertSame(['acme.com'], array_column($json['email_domains'], 'domain'));
    }
}
