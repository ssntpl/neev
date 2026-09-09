<?php

namespace Ssntpl\Neev\Tests\Unit\Scopes;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\Tenant;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\TenantResolver;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class TeamTenantScopeTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    protected Tenant $acme;

    protected Tenant $globex;

    protected Team $acmeTeam;

    protected Team $globexTeam;

    protected Team $platformTeam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->globex = Tenant::create(['name' => 'Globex', 'slug' => 'globex']);

        $owner = User::factory()->create();

        $this->acmeTeam = $this->makeTeam('Acme Team', 'acme-team', $owner, $this->acme->id);
        $this->globexTeam = $this->makeTeam('Globex Team', 'globex-team', $owner, $this->globex->id);
        $this->platformTeam = $this->makeTeam('Platform Team', 'platform-team', $owner, null);
    }

    /**
     * tenant_id is not fillable, so it has to be set on the instance.
     */
    protected function makeTeam(string $name, string $slug, User $owner, ?int $tenantId): Team
    {
        $team = new Team();
        $team->name = $name;
        $team->slug = $slug;
        $team->user_id = $owner->id;
        $team->tenant_id = $tenantId;
        $team->save();

        return $team;
    }

    protected function resolveTenant(Tenant $tenant): void
    {
        app(TenantResolver::class)->runInContext($tenant, fn () => null);

        $resolver = app(TenantResolver::class);
        $property = (new \ReflectionClass($resolver))->getProperty('resolvedContext');
        $property->setAccessible(true);
        $property->setValue($resolver, $tenant);
    }

    // -----------------------------------------------------------------
    // Isolation disabled — every team is visible
    // -----------------------------------------------------------------

    public function test_no_scope_is_applied_when_tenant_isolation_is_disabled(): void
    {
        config(['neev.tenant' => false]);

        $this->assertCount(3, Team::all());
    }

    // -----------------------------------------------------------------
    // Isolation enabled, tenant resolved — only that tenant's teams
    // -----------------------------------------------------------------

    public function test_only_the_resolved_tenants_teams_are_visible(): void
    {
        $this->enableTenantIsolation();
        $this->resolveTenant($this->acme);

        $slugs = Team::all()->pluck('slug')->all();

        $this->assertSame(['acme-team'], $slugs);
        $this->assertNull(Team::find($this->globexTeam->id));
    }

    // -----------------------------------------------------------------
    // Isolation enabled, nothing resolved — platform teams only,
    // matching TenantScope's behaviour for users (RFC-001 D1)
    // -----------------------------------------------------------------

    public function test_only_platform_teams_are_visible_without_a_resolved_tenant(): void
    {
        $this->enableTenantIsolation();

        $slugs = Team::all()->pluck('slug')->all();

        $this->assertSame(['platform-team'], $slugs);
    }

    // -----------------------------------------------------------------
    // withoutTenantScope() reaches across tenants
    // -----------------------------------------------------------------

    public function test_without_tenant_scope_returns_every_team(): void
    {
        $this->enableTenantIsolation();
        $this->resolveTenant($this->acme);

        $this->assertCount(3, Team::withoutTenantScope()->get());
    }

    // -----------------------------------------------------------------
    // A tenant's own teams relation is not narrowed by the resolved tenant
    // -----------------------------------------------------------------

    public function test_tenant_teams_relation_ignores_the_resolved_tenant(): void
    {
        $this->enableTenantIsolation();
        $this->resolveTenant($this->acme);

        $this->assertCount(1, $this->globex->teams);
        $this->assertSame('globex-team', $this->globex->teams->first()->slug);
    }

    // -----------------------------------------------------------------
    // Console commands resolve teams across every tenant
    // -----------------------------------------------------------------

    public function test_console_team_lookup_is_not_tenant_scoped(): void
    {
        $this->enableTenantIsolation();

        $this->artisan('neev:auth:show', ['--team' => 'acme-team'])
            ->assertSuccessful();
    }

    // -----------------------------------------------------------------
    // A user's team listing is limited to the resolved tenant
    // -----------------------------------------------------------------

    public function test_user_teams_relation_is_limited_to_the_resolved_tenant(): void
    {
        $this->enableTenantIsolation();

        $user = User::factory()->create();
        $this->acmeTeam->allUsers()->attach($user, ['joined' => true]);
        $this->globexTeam->allUsers()->attach($user, ['joined' => true]);

        $this->resolveTenant($this->acme);

        $this->assertSame(['acme-team'], $user->teams()->pluck('slug')->all());
    }
}
