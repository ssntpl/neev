<?php

namespace Ssntpl\Neev\Tests\Unit\Traits;

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

class BelongsToTenantTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tenant_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable();
            $table->string('name');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('tenant_items');

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // getTenantIdColumn()
    // -----------------------------------------------------------------

    public function test_get_tenant_id_column_defaults_to_tenant_id(): void
    {
        $item = new TenantItem();

        $this->assertEquals('tenant_id', $item->getTenantIdColumn());
    }

    public function test_get_tenant_id_column_uses_constant_when_defined(): void
    {
        $item = new TenantItemWithCustomColumn();

        $this->assertEquals('team_id', $item->getTenantIdColumn());
    }

    // -----------------------------------------------------------------
    // getQualifiedTenantIdColumn()
    // -----------------------------------------------------------------

    public function test_get_qualified_tenant_id_column_returns_table_prefixed_column(): void
    {
        $item = new TenantItem();

        $this->assertEquals('tenant_items.tenant_id', $item->getQualifiedTenantIdColumn());
    }

    public function test_get_qualified_tenant_id_column_with_custom_column(): void
    {
        $item = new TenantItemWithCustomColumn();

        $this->assertEquals('tenant_items.team_id', $item->getQualifiedTenantIdColumn());
    }

    // -----------------------------------------------------------------
    // tenant() relationship
    // -----------------------------------------------------------------

    public function test_tenant_relationship_returns_belongs_to_team(): void
    {
        $team = TeamFactory::new()->create();

        $item = TenantItem::create([
            'name' => 'Test Item',
            'tenant_id' => $team->id,
        ]);

        $this->assertNotNull($item->tenant);
        $this->assertTrue($item->tenant->is($team));
    }

    // -----------------------------------------------------------------
    // Auto tenant_id assignment on creating
    // -----------------------------------------------------------------

    public function test_auto_assigns_tenant_id_when_tenant_isolation_enabled_and_resolver_has_tenant(): void
    {
        $this->enableTenantIsolation();

        $team = TeamFactory::new()->create();

        $resolver = app(TenantResolver::class);
        $resolver->setCurrentTenant($team);

        $item = TenantItem::create([
            'name' => 'Auto Assigned Item',
        ]);

        $this->assertEquals($team->id, $item->tenant_id);
    }

    public function test_does_not_overwrite_tenant_id_when_already_set(): void
    {
        $this->enableTenantIsolation();

        $teamA = TeamFactory::new()->create();
        $teamB = TeamFactory::new()->create();

        $resolver = app(TenantResolver::class);
        $resolver->setCurrentTenant($teamA);

        $item = TenantItem::create([
            'name' => 'Pre-set Item',
            'tenant_id' => $teamB->id,
        ]);

        $this->assertEquals($teamB->id, $item->tenant_id);
    }

    public function test_does_not_assign_tenant_id_when_tenant_isolation_disabled(): void
    {
        config(['neev.tenant_isolation' => false]);

        $team = TeamFactory::new()->create();

        $resolver = app(TenantResolver::class);
        $resolver->setCurrentTenant($team);

        $item = TenantItem::create([
            'name' => 'No Isolation Item',
        ]);

        $this->assertNull($item->tenant_id);
    }

    public function test_does_not_assign_tenant_id_when_resolver_has_no_tenant(): void
    {
        $this->enableTenantIsolation();

        // No tenant set on the resolver
        $item = TenantItem::create([
            'name' => 'No Tenant Item',
        ]);

        $this->assertNull($item->tenant_id);
    }

    // -----------------------------------------------------------------
    // TenantScope - query scoping
    // -----------------------------------------------------------------

    public function test_tenant_scope_filters_records_by_current_tenant(): void
    {
        $this->enableTenantIsolation();

        $teamA = TeamFactory::new()->create();
        $teamB = TeamFactory::new()->create();

        // Create items for both teams (bypass scope using direct DB insert)
        TenantItem::withoutTenantScope()->create([
            'name' => 'Team A Item',
            'tenant_id' => $teamA->id,
        ]);
        TenantItem::withoutTenantScope()->create([
            'name' => 'Team B Item',
            'tenant_id' => $teamB->id,
        ]);

        // Set current tenant to Team A
        $resolver = app(TenantResolver::class);
        $resolver->setCurrentTenant($teamA);

        $items = TenantItem::all();

        $this->assertCount(1, $items);
        $this->assertEquals('Team A Item', $items->first()->name);
    }

    public function test_tenant_scope_not_applied_when_isolation_disabled(): void
    {
        config(['neev.tenant_isolation' => false]);

        $teamA = TeamFactory::new()->create();
        $teamB = TeamFactory::new()->create();

        TenantItem::create([
            'name' => 'Team A Item',
            'tenant_id' => $teamA->id,
        ]);
        TenantItem::create([
            'name' => 'Team B Item',
            'tenant_id' => $teamB->id,
        ]);

        // Even with a resolver set, scope should not apply
        $resolver = app(TenantResolver::class);
        $resolver->setCurrentTenant($teamA);

        $items = TenantItem::all();

        $this->assertCount(2, $items);
    }

    // -----------------------------------------------------------------
    // withoutTenantScope()
    // -----------------------------------------------------------------

    public function test_without_tenant_scope_removes_global_scope(): void
    {
        $this->enableTenantIsolation();

        $teamA = TeamFactory::new()->create();
        $teamB = TeamFactory::new()->create();

        $resolver = app(TenantResolver::class);
        $resolver->setCurrentTenant($teamA);

        TenantItem::create([
            'name' => 'Team A Item',
        ]);

        // Switch tenant to create Team B item
        $resolver->setCurrentTenant($teamB);
        TenantItem::create([
            'name' => 'Team B Item',
        ]);

        // With scope: only Team B items (current tenant)
        $scopedItems = TenantItem::all();
        $this->assertCount(1, $scopedItems);

        // Without scope: all items
        $allItems = TenantItem::withoutTenantScope()->get();
        $this->assertCount(2, $allItems);
    }

    public function test_without_tenant_scope_returns_query_builder(): void
    {
        $this->enableTenantIsolation();

        $team = TeamFactory::new()->create();
        $resolver = app(TenantResolver::class);
        $resolver->setCurrentTenant($team);

        $query = TenantItem::withoutTenantScope();

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Builder::class, $query);
    }
}

/**
 * Test model that uses the BelongsToTenant trait with default tenant_id column.
 */
class TenantItem extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_items';

    protected $fillable = ['name', 'tenant_id'];
}

/**
 * Test model with a custom tenant ID column constant.
 */
class TenantItemWithCustomColumn extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_items';

    protected $fillable = ['name', 'team_id'];

    public const TENANT_ID_COLUMN = 'team_id';
}
