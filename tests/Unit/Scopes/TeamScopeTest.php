<?php

namespace Ssntpl\Neev\Tests\Unit\Scopes;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Scopes\TeamScope;
use Ssntpl\Neev\Services\ContextManager;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;
use Ssntpl\Neev\Traits\BelongsToTeam;

class TeamScopeTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('team_scope_test_models', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->string('name');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('team_scope_test_models');

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Does not apply scope when ContextManager not bound
    // -----------------------------------------------------------------

    public function test_does_not_apply_scope_when_context_manager_not_bound(): void
    {
        $this->app->forgetInstance(ContextManager::class);
        $this->app->offsetUnset(ContextManager::class);

        $scope = new TeamScope();
        $model = new TeamScopeTestModel();
        $builder = $model->newQuery();

        $scope->apply($builder, $model);

        $this->assertStringNotContainsString('team_id', $builder->toSql());
    }

    // -----------------------------------------------------------------
    // Does not apply scope when no current team
    // -----------------------------------------------------------------

    public function test_does_not_apply_scope_when_no_current_team(): void
    {
        $manager = app(ContextManager::class);
        $manager->clear();

        $scope = new TeamScope();
        $model = new TeamScopeTestModel();
        $builder = $model->newQuery();

        $scope->apply($builder, $model);

        $this->assertStringNotContainsString('team_id', $builder->toSql());
    }

    // -----------------------------------------------------------------
    // Applies where clause when team is set
    // -----------------------------------------------------------------

    public function test_applies_where_clause_when_team_is_set(): void
    {
        $team = TeamFactory::new()->create();

        $manager = app(ContextManager::class);
        $manager->setTeam($team);

        $scope = new TeamScope();
        $model = new TeamScopeTestModel();
        $builder = $model->newQuery();

        $scope->apply($builder, $model);

        $sql = $builder->toSql();
        $this->assertStringContainsString('team_id', $sql);
    }

    // -----------------------------------------------------------------
    // Applies correct team ID in the where clause
    // -----------------------------------------------------------------

    public function test_applies_correct_team_id_in_where_clause(): void
    {
        $team = TeamFactory::new()->create();

        $manager = app(ContextManager::class);
        $manager->setTeam($team);

        $scope = new TeamScope();
        $model = new TeamScopeTestModel();
        $builder = $model->newQuery();

        $scope->apply($builder, $model);

        $bindings = $builder->getBindings();
        $this->assertContains($team->id, $bindings);
    }

    // -----------------------------------------------------------------
    // extend() adds withoutTeamScope macro
    // -----------------------------------------------------------------

    public function test_extend_adds_without_team_scope_macro(): void
    {
        $scope = new TeamScope();
        $model = new TeamScopeTestModel();
        $eloquentBuilder = $model->newQuery();

        $scope->extend($eloquentBuilder);

        $this->assertTrue($eloquentBuilder->hasMacro('withoutTeamScope'));
    }

    // -----------------------------------------------------------------
    // BelongsToTeam trait applies scope on queries
    // -----------------------------------------------------------------

    public function test_belongs_to_team_trait_applies_scope_on_queries(): void
    {
        $team = TeamFactory::new()->create();

        $manager = app(ContextManager::class);
        $manager->setTeam($team);

        TeamScopeTestModel::withoutGlobalScope(TeamScope::class)->create([
            'name' => 'Team A Record',
            'team_id' => $team->id,
        ]);
        TeamScopeTestModel::withoutGlobalScope(TeamScope::class)->create([
            'name' => 'Team B Record',
            'team_id' => $team->id + 999,
        ]);

        $results = TeamScopeTestModel::all();

        $this->assertCount(1, $results);
        $this->assertSame('Team A Record', $results->first()->name);
    }
}

/**
 * A temporary model used only in TeamScope tests.
 */
class TeamScopeTestModel extends Model
{
    use BelongsToTeam;

    protected $table = 'team_scope_test_models';
    protected $fillable = ['name', 'team_id'];
}
