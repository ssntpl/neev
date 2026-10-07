<?php

namespace Ssntpl\Neev\Tests\Unit\Models;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Database\Factories\TenantFactory;
use Ssntpl\Neev\Models\EmailDomain;
use Ssntpl\Neev\Models\Hostname;
use Ssntpl\Neev\Tests\Support\Project;
use Ssntpl\Neev\Tests\TestCase;

class HostnameAndEmailDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_host_belongs_to_one_owner_however_it_is_spelled(): void
    {
        $one = TeamFactory::new()->create();
        $two = TeamFactory::new()->create();
        Hostname::create(['owner_type' => 'team', 'owner_id' => $one->id, 'host' => 'app.acme.com']);

        $this->expectException(QueryException::class);

        Hostname::create(['owner_type' => 'team', 'owner_id' => $two->id, 'host' => 'APP.acme.com.']);
    }

    public function test_a_team_and_a_tenant_cannot_share_a_host(): void
    {
        $team = TeamFactory::new()->create();
        $tenant = TenantFactory::new()->create();
        Hostname::create(['owner_type' => 'team', 'owner_id' => $team->id, 'host' => 'app.acme.com']);

        $this->expectException(QueryException::class);

        Hostname::create(['owner_type' => 'tenant', 'owner_id' => $tenant->id, 'host' => 'app.acme.com']);
    }

    public function test_several_owners_may_claim_one_email_domain(): void
    {
        $one = TeamFactory::new()->create();
        $two = TeamFactory::new()->create();
        $tenant = TenantFactory::new()->create();

        EmailDomain::create(['owner_type' => 'team', 'owner_id' => $one->id, 'domain' => 'acme.com']);
        EmailDomain::create(['owner_type' => 'team', 'owner_id' => $two->id, 'domain' => 'ACME.com']);
        EmailDomain::create(['owner_type' => 'tenant', 'owner_id' => $tenant->id, 'domain' => 'acme.com.']);

        $this->assertSame(3, EmailDomain::forHost('acme.com')->count());
    }

    public function test_one_owner_claims_an_email_domain_once(): void
    {
        $team = TeamFactory::new()->create();
        EmailDomain::create(['owner_type' => 'team', 'owner_id' => $team->id, 'domain' => 'acme.com']);

        $this->expectException(QueryException::class);

        EmailDomain::create(['owner_type' => 'team', 'owner_id' => $team->id, 'domain' => 'Acme.com.']);
    }

    public function test_an_application_model_can_own_a_hostname_and_an_email_domain(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('handle')->unique();
            $table->timestamps();
        });
        $project = Project::create(['handle' => 'acme']);

        $hostname = Hostname::create([
            'owner_type' => $project->getMorphClass(), 'owner_id' => $project->id, 'host' => 'App.Acme.com.',
        ]);
        $emailDomain = EmailDomain::create([
            'owner_type' => $project->getMorphClass(), 'owner_id' => $project->id, 'domain' => 'ACME.com',
        ]);

        $this->assertTrue($hostname->fresh()->owner->is($project));
        $this->assertTrue($emailDomain->fresh()->owner->is($project));
        $this->assertSame('app.acme.com', $hostname->host);
        $this->assertSame('acme.com', $emailDomain->domain);
    }
}
