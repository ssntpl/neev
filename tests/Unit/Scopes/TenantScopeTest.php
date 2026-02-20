<?php

namespace Ssntpl\Neev\Tests\Unit\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Scopes\TenantScope;
use Ssntpl\Neev\Services\TenantResolver;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;
use Ssntpl\Neev\Traits\BelongsToTenant;

class TenantScopeTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a temporary table for testing tenant scope
        Schema::create('tenant_scope_test_models', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('name');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('tenant_scope_test_models');

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Does not apply scope when TenantResolver not bound
    // -----------------------------------------------------------------

    public function test_does_not_apply_scope_when_tenant_resolver_not_bound(): void
    {
        // Forget the TenantResolver binding
        $this->app->forgetInstance(TenantResolver::class);
        $this->app->offsetUnset(TenantResolver::class);

        $scope = new TenantScope();
        $model = new TenantScopeTestModel();
        $builder = $model->newQuery();

        $scope->apply($builder, $model);

        $this->assertStringNotContainsString('tenant_id', $builder->toSql());
    }

    // -----------------------------------------------------------------
    // Does not apply scope when no current tenant
    // -----------------------------------------------------------------

    public function test_does_not_apply_scope_when_no_current_tenant(): void
    {
        // TenantResolver is bound (by the service provider) but has no tenant set
        $resolver = app(TenantResolver::class);
        $resolver->clear();

        $scope = new TenantScope();
        $model = new TenantScopeTestModel();
        $builder = $model->newQuery();

        $scope->apply($builder, $model);

        $this->assertStringNotContainsString('tenant_id', $builder->toSql());
    }

    // -----------------------------------------------------------------
    // Applies where clause when tenant is set
    // -----------------------------------------------------------------

    public function test_applies_where_clause_when_tenant_is_set(): void
    {
        $team = TeamFactory::new()->create();

        $resolver = app(TenantResolver::class);
        $resolver->setCurrentTenant($team);

        $scope = new TenantScope();
        $model = new TenantScopeTestModel();
        $builder = $model->newQuery();

        $scope->apply($builder, $model);

        $sql = $builder->toSql();
        $this->assertStringContainsString('tenant_id', $sql);
    }

    // -----------------------------------------------------------------
    // Applies correct tenant ID in the where clause
    // -----------------------------------------------------------------

    public function test_applies_correct_tenant_id_in_where_clause(): void
    {
        $team = TeamFactory::new()->create();

        $resolver = app(TenantResolver::class);
        $resolver->setCurrentTenant($team);

        $scope = new TenantScope();
        $model = new TenantScopeTestModel();
        $builder = $model->newQuery();

        $scope->apply($builder, $model);

        $bindings = $builder->getBindings();
        $this->assertContains($team->id, $bindings);
    }

    // -----------------------------------------------------------------
    // extend() adds withoutTenantScope macro
    // -----------------------------------------------------------------

    public function test_extend_adds_without_tenant_scope_macro(): void
    {
        $scope = new TenantScope();
        $model = new TenantScopeTestModel();
        $eloquentBuilder = $model->newQuery();

        $scope->extend($eloquentBuilder);

        // The macro should be callable on the builder
        // withoutTenantScope is added as a local macro via $builder->macro()
        $this->assertTrue($eloquentBuilder->hasMacro('withoutTenantScope'));
    }

    // -----------------------------------------------------------------
    // BelongsToTenant trait applies scope on queries
    // -----------------------------------------------------------------

    public function test_belongs_to_tenant_trait_applies_scope_on_queries(): void
    {
        $team = TeamFactory::new()->create();

        $resolver = app(TenantResolver::class);
        $resolver->setCurrentTenant($team);

        // Insert records directly bypassing the scope
        TenantScopeTestModel::withoutGlobalScope(TenantScope::class)->create([
            'name' => 'Team A Record',
            'tenant_id' => $team->id,
        ]);
        TenantScopeTestModel::withoutGlobalScope(TenantScope::class)->create([
            'name' => 'Team B Record',
            'tenant_id' => $team->id + 999,
        ]);

        // Query with scope should only return team A's record
        $results = TenantScopeTestModel::all();

        $this->assertCount(1, $results);
        $this->assertSame('Team A Record', $results->first()->name);
    }
}

/**
 * A temporary model used only in TenantScope tests.
 */
class TenantScopeTestModel extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_scope_test_models';
    protected $fillable = ['name', 'tenant_id'];
}
