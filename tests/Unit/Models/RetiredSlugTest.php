<?php

namespace Ssntpl\Neev\Tests\Unit\Models;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Database\Factories\TenantFactory;
use Ssntpl\Neev\Events\SlugChanged;
use Ssntpl\Neev\Exceptions\SlugUnavailableException;
use Ssntpl\Neev\Models\RetiredSlug;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\Tenant;
use Ssntpl\Neev\Services\TenantResolver;
use Ssntpl\Neev\Support\SlugHelper;
use Ssntpl\Neev\Tests\Support\Project;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

/**
 * RFC 006 §6 Q1 and Q5: slugs are mutable but never recycled, and team slugs
 * are unique per tenant in isolated mode, installation-wide in shared mode.
 */
class RetiredSlugTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    public function test_renaming_a_team_retires_its_old_slug(): void
    {
        Event::fake([SlugChanged::class]);
        $team = TeamFactory::new()->create(['slug' => 'acme']);

        $team->update(['slug' => 'acme-corp']);

        $retired = RetiredSlug::sole();
        $this->assertSame('team', $retired->owner_type);
        $this->assertSame($team->id, $retired->owner_id);
        $this->assertSame('acme', $retired->slug);

        Event::assertDispatched(SlugChanged::class, fn (SlugChanged $e) => $e->owner->is($team)
            && $e->oldSlug === 'acme'
            && $e->newSlug === 'acme-corp');
    }

    public function test_a_retired_slug_resolves_its_owner(): void
    {
        $team = TeamFactory::new()->create(['slug' => 'acme']);
        $team->update(['slug' => 'acme-corp']);

        $owner = RetiredSlug::sole()->owner;

        $this->assertInstanceOf(Team::class, $owner);
        $this->assertTrue($owner->is($team));
    }

    public function test_saving_without_a_slug_change_retires_nothing(): void
    {
        Event::fake([SlugChanged::class]);
        $team = TeamFactory::new()->create(['slug' => 'acme']);

        $team->update(['name' => 'Acme Renamed']);

        $this->assertDatabaseCount('retired_slugs', 0);
        Event::assertNotDispatched(SlugChanged::class);
    }

    public function test_another_team_cannot_take_a_retired_slug(): void
    {
        $team = TeamFactory::new()->create(['slug' => 'acme']);
        $team->update(['slug' => 'acme-corp']);

        $this->expectException(SlugUnavailableException::class);

        TeamFactory::new()->create(['slug' => 'acme']);
    }

    public function test_another_team_cannot_rename_to_a_retired_slug(): void
    {
        $team = TeamFactory::new()->create(['slug' => 'acme']);
        $team->update(['slug' => 'acme-corp']);
        $other = TeamFactory::new()->create(['slug' => 'other']);

        $this->expectException(SlugUnavailableException::class);

        $other->update(['slug' => 'acme']);
    }

    public function test_a_team_can_take_back_its_own_retired_slug(): void
    {
        $team = TeamFactory::new()->create(['slug' => 'acme']);
        $team->update(['slug' => 'acme-corp']);

        $team->update(['slug' => 'acme']);

        $this->assertSame('acme', $team->fresh()->slug);
        $this->assertSame(['acme-corp'], RetiredSlug::pluck('slug')->all());
    }

    public function test_a_generated_slug_skips_a_retired_one(): void
    {
        $team = TeamFactory::new()->create(['slug' => 'acme']);
        $team->update(['slug' => 'acme-corp']);

        $this->assertSame('acme-1', SlugHelper::generate('Acme'));
    }

    public function test_a_generated_slug_may_reuse_the_owners_own_retired_slug(): void
    {
        $team = TeamFactory::new()->create(['slug' => 'acme']);
        $team->update(['slug' => 'acme-corp']);

        $this->assertSame('acme', SlugHelper::generate('Acme', $team->id));
    }

    public function test_renaming_a_tenant_retires_its_old_slug_for_good(): void
    {
        $tenant = TenantFactory::new()->create(['slug' => 'acme']);
        $tenant->update(['slug' => 'acme-corp']);

        $this->assertSame('tenant', RetiredSlug::sole()->owner_type);
        $this->assertSame('acme-1', SlugHelper::generateForTenant('Acme'));

        $this->expectException(SlugUnavailableException::class);

        TenantFactory::new()->create(['slug' => 'acme']);
    }

    public function test_team_and_tenant_retirements_do_not_block_each_other(): void
    {
        $tenant = TenantFactory::new()->create(['slug' => 'acme']);
        $tenant->update(['slug' => 'acme-corp']);

        $team = TeamFactory::new()->create(['slug' => 'acme']);

        $this->assertSame('acme', $team->slug);
    }

    public function test_isolated_team_slugs_are_unique_per_tenant_only(): void
    {
        $this->enableTenantIsolation();
        $one = TenantFactory::new()->create();
        $two = TenantFactory::new()->create();

        TeamFactory::new()->create(['slug' => 'engineering', 'tenant_id' => $one->id]);
        $second = TeamFactory::new()->create(['slug' => 'engineering', 'tenant_id' => $two->id]);

        $this->assertSame('engineering', $second->slug);

        $this->expectException(SlugUnavailableException::class);

        TeamFactory::new()->create(['slug' => 'engineering', 'tenant_id' => $one->id]);
    }

    public function test_isolated_team_retirement_is_scoped_to_its_tenant(): void
    {
        $this->enableTenantIsolation();
        $one = TenantFactory::new()->create();
        $two = TenantFactory::new()->create();

        $team = TeamFactory::new()->create(['slug' => 'engineering', 'tenant_id' => $one->id]);
        $team->update(['slug' => 'platform']);

        $elsewhere = TeamFactory::new()->create(['slug' => 'engineering', 'tenant_id' => $two->id]);
        $this->assertSame('engineering', $elsewhere->slug);

        $this->expectException(SlugUnavailableException::class);

        TeamFactory::new()->create(['slug' => 'engineering', 'tenant_id' => $one->id]);
    }

    public function test_a_new_isolated_team_is_checked_against_its_resolved_tenant(): void
    {
        $this->enableTenantIsolation();
        $tenant = TenantFactory::new()->create();
        $inContext = fn (array $attributes) => app(TenantResolver::class)
            ->runInContext($tenant, fn () => TeamFactory::new()->create($attributes));

        // A platform team's slug does not block a team the context places in a tenant.
        TeamFactory::new()->create(['slug' => 'acme']);
        $this->assertSame($tenant->id, $inContext(['slug' => 'acme'])->tenant_id);

        // Nor does it let through a slug the tenant retired or holds now.
        $inContext(['slug' => 'engineering'])->update(['slug' => 'platform']);

        foreach (['engineering', 'platform'] as $slug) {
            try {
                $inContext(['slug' => $slug]);
                $this->fail("A new team in the tenant took {$slug}.");
            } catch (SlugUnavailableException) {
            }
        }
    }

    public function test_a_generated_slug_is_chosen_among_the_teams_tenant(): void
    {
        $this->enableTenantIsolation();
        $tenant = TenantFactory::new()->create();
        $create = function () use ($tenant) {
            // A seeder or admin script: a tenant named, none resolved, no slug.
            $team = TeamFactory::new()->make(['name' => 'Acme', 'slug' => null]);
            $team->tenant_id = $tenant->id;
            $team->save();

            return $team;
        };

        $first = $create();
        $first->update(['slug' => 'acme-corp']);

        $this->assertSame('acme-1', $create()->slug);
        $this->assertSame('acme-2', $create()->slug);
    }

    public function test_an_application_model_retires_its_own_slugs(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('handle')->unique();
            $table->timestamps();
        });
        Event::fake([SlugChanged::class]);

        $project = Project::create(['handle' => 'acme']);
        $project->update(['handle' => 'acme-corp']);

        $retired = RetiredSlug::sole();
        $this->assertSame($project->getMorphClass(), $retired->owner_type);
        $this->assertSame('acme', $retired->slug);
        Event::assertDispatched(SlugChanged::class, fn (SlugChanged $e) => $e->owner->is($project));

        // A team is another kind of owner, so the project's retirement does
        // not hold the slug against it.
        $this->assertSame('acme', TeamFactory::new()->create(['slug' => 'acme'])->slug);

        $this->expectException(SlugUnavailableException::class);

        Project::create(['handle' => 'acme']);
    }

    public function test_an_application_model_saved_without_a_slug_is_saved_without_one(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('handle')->nullable()->unique();
            $table->timestamps();
        });

        // Project does not override generateSlug(), so nothing is filled in.
        $project = Project::create([]);

        $this->assertTrue($project->exists);
        $this->assertNull($project->fresh()->handle);
        $this->assertDatabaseCount('retired_slugs', 0);
    }

    /**
     * Team A holds `acme` and has retired `acme-corp` and `acme-com`.
     */
    private function teamAHoldingAcme(?int $tenantId = null): Team
    {
        $team = TeamFactory::new()->create(['slug' => 'acme-corp', 'tenant_id' => $tenantId]);
        $team->update(['slug' => 'acme-com']);
        $team->update(['slug' => 'acme']);

        return $team;
    }

    public function test_shared_mode_no_other_team_can_use_team_as_three_slugs(): void
    {
        $teamA = $this->teamAHoldingAcme();
        $other = TeamFactory::new()->create(['slug' => 'other']);

        foreach (['acme', 'acme-corp', 'acme-com'] as $slug) {
            try {
                TeamFactory::new()->create(['slug' => $slug]);
                $this->fail("A new team took {$slug}.");
            } catch (SlugUnavailableException) {
            }

            try {
                $other->update(['slug' => $slug]);
                $this->fail("Another team renamed to {$slug}.");
            } catch (SlugUnavailableException) {
                $other->refresh();
            }
        }

        $this->assertSame('acme-1', SlugHelper::generate('Acme'));
        $this->assertSame('acme-corp-1', SlugHelper::generate('acme-corp'));
        $this->assertSame('acme-com-1', SlugHelper::generate('acme-com'));
        $this->assertSame('acme', $teamA->fresh()->slug);
    }

    public function test_shared_mode_team_a_can_use_any_of_its_three_slugs(): void
    {
        $teamA = $this->teamAHoldingAcme();

        foreach (['acme-corp', 'acme-com', 'acme'] as $slug) {
            $teamA->update(['slug' => $slug]);
            $this->assertSame($slug, $teamA->fresh()->slug);
        }

        $this->assertSame('acme-corp', SlugHelper::generate('acme-corp', $teamA->id));
    }

    public function test_isolated_mode_tenant_a_teams_cannot_use_team_as_three_slugs(): void
    {
        $this->enableTenantIsolation();
        $tenantA = TenantFactory::new()->create();
        $this->teamAHoldingAcme($tenantA->id);
        $other = TeamFactory::new()->create(['slug' => 'other', 'tenant_id' => $tenantA->id]);

        foreach (['acme', 'acme-corp', 'acme-com'] as $slug) {
            try {
                TeamFactory::new()->create(['slug' => $slug, 'tenant_id' => $tenantA->id]);
                $this->fail("A new team in tenant A took {$slug}.");
            } catch (SlugUnavailableException) {
            }

            try {
                $other->update(['slug' => $slug]);
                $this->fail("Another team in tenant A renamed to {$slug}.");
            } catch (SlugUnavailableException) {
                $other->refresh();
            }
        }

        $generated = app(TenantResolver::class)->runInContext($tenantA, fn () => [
            SlugHelper::generate('Acme'), SlugHelper::generate('acme-corp'), SlugHelper::generate('acme-com'),
        ]);
        $this->assertSame(['acme-1', 'acme-corp-1', 'acme-com-1'], $generated);
    }

    public function test_isolated_mode_team_a_can_use_any_of_its_three_slugs(): void
    {
        $this->enableTenantIsolation();
        $tenantA = TenantFactory::new()->create();
        $teamA = $this->teamAHoldingAcme($tenantA->id);

        foreach (['acme-corp', 'acme-com', 'acme'] as $slug) {
            $teamA->update(['slug' => $slug]);
            $this->assertSame($slug, $teamA->fresh()->slug);
        }
    }

    public function test_isolated_mode_tenant_b_teams_can_use_team_as_three_slugs(): void
    {
        $this->enableTenantIsolation();
        $tenantA = TenantFactory::new()->create();
        $tenantB = TenantFactory::new()->create();
        $this->teamAHoldingAcme($tenantA->id);

        foreach (['acme', 'acme-corp', 'acme-com'] as $slug) {
            $team = TeamFactory::new()->create(['slug' => $slug, 'tenant_id' => $tenantB->id]);
            $this->assertSame($slug, $team->slug);
            $team->delete();
        }

        $generated = app(TenantResolver::class)->runInContext($tenantB, fn () => [
            SlugHelper::generate('Acme'), SlugHelper::generate('acme-corp'), SlugHelper::generate('acme-com'),
        ]);
        $this->assertSame(['acme', 'acme-corp', 'acme-com'], $generated);
    }

    public function test_a_lock_on_one_slug_does_not_hold_up_saves_on_another(): void
    {
        $lock = Cache::lock('neev:slugs:team:acme', 10);
        $this->assertTrue($lock->get());

        try {
            $team = TeamFactory::new()->create(['slug' => 'beta']);
            $team->update(['slug' => 'gamma']);
        } finally {
            $lock->release();
        }

        $this->assertSame('gamma', $team->fresh()->slug);
    }

    public function test_a_team_saved_without_a_slug_gets_one_from_its_name(): void
    {
        $team = TeamFactory::new()->create(['slug' => 'acme']);
        $team->update(['slug' => 'acme-corp']);

        $this->assertSame('acme-1', TeamFactory::new()->create(['name' => 'Acme', 'slug' => null])->slug);
    }

    public function test_a_tenant_saved_without_a_slug_gets_one_from_its_name(): void
    {
        $tenant = TenantFactory::new()->create(['slug' => 'acme']);
        $tenant->update(['slug' => 'acme-corp']);

        $this->assertSame('acme-1', Tenant::create(['name' => 'Acme'])->slug);
    }

    public function test_a_tenant_cannot_rename_to_a_slug_another_tenant_holds(): void
    {
        TenantFactory::new()->create(['slug' => 'acme']);
        $other = TenantFactory::new()->create(['slug' => 'other']);

        $this->expectException(SlugUnavailableException::class);

        $other->update(['slug' => 'acme']);
    }

    public function test_create_command_refuses_a_retired_slug(): void
    {
        $this->enableTeams();
        $team = TeamFactory::new()->create(['slug' => 'acme']);
        $team->update(['slug' => 'acme-corp']);

        $this->artisan('neev:tenant:create', ['name' => 'Acme', '--slug' => 'acme'])
            ->expectsOutputToContain('Slug already in use: acme')
            ->assertFailed();
    }
}
