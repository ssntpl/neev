<?php

namespace Ssntpl\Neev\Tests\Unit\Traits;

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

class BelongsToTeamTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('team_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->nullable();
            $table->string('name');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('team_items');

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // getTeamIdColumn()
    // -----------------------------------------------------------------

    public function test_get_team_id_column_defaults_to_team_id(): void
    {
        $item = new TeamItem();

        $this->assertEquals('team_id', $item->getTeamIdColumn());
    }

    public function test_get_team_id_column_uses_constant_when_defined(): void
    {
        $item = new TeamItemWithCustomColumn();

        $this->assertEquals('group_id', $item->getTeamIdColumn());
    }

    // -----------------------------------------------------------------
    // getQualifiedTeamIdColumn()
    // -----------------------------------------------------------------

    public function test_get_qualified_team_id_column_returns_table_prefixed_column(): void
    {
        $item = new TeamItem();

        $this->assertEquals('team_items.team_id', $item->getQualifiedTeamIdColumn());
    }

    public function test_get_qualified_team_id_column_with_custom_column(): void
    {
        $item = new TeamItemWithCustomColumn();

        $this->assertEquals('team_items.group_id', $item->getQualifiedTeamIdColumn());
    }

    // -----------------------------------------------------------------
    // team() relationship
    // -----------------------------------------------------------------

    public function test_team_relationship_returns_belongs_to_team(): void
    {
        $team = TeamFactory::new()->create();

        $item = TeamItem::create([
            'name' => 'Test Item',
            'team_id' => $team->id,
        ]);

        $this->assertNotNull($item->team);
        $this->assertTrue($item->team->is($team));
    }

    // -----------------------------------------------------------------
    // Auto team_id assignment on creating
    // -----------------------------------------------------------------

    public function test_auto_assigns_team_id_when_context_manager_has_team(): void
    {
        $team = TeamFactory::new()->create();

        $manager = app(ContextManager::class);
        $manager->setTeam($team);

        $item = TeamItem::create([
            'name' => 'Auto Assigned Item',
        ]);

        $this->assertEquals($team->id, $item->team_id);
    }

    public function test_does_not_overwrite_team_id_when_already_set(): void
    {
        $teamA = TeamFactory::new()->create();
        $teamB = TeamFactory::new()->create();

        $manager = app(ContextManager::class);
        $manager->setTeam($teamA);

        $item = TeamItem::create([
            'name' => 'Pre-set Item',
            'team_id' => $teamB->id,
        ]);

        $this->assertEquals($teamB->id, $item->team_id);
    }

    public function test_does_not_assign_team_id_when_context_manager_has_no_team(): void
    {
        $item = TeamItem::create([
            'name' => 'No Team Item',
        ]);

        $this->assertNull($item->team_id);
    }

    // -----------------------------------------------------------------
    // TeamScope - query scoping
    // -----------------------------------------------------------------

    public function test_team_scope_filters_records_by_current_team(): void
    {
        $teamA = TeamFactory::new()->create();
        $teamB = TeamFactory::new()->create();

        TeamItem::withoutTeamScope()->create([
            'name' => 'Team A Item',
            'team_id' => $teamA->id,
        ]);
        TeamItem::withoutTeamScope()->create([
            'name' => 'Team B Item',
            'team_id' => $teamB->id,
        ]);

        $manager = app(ContextManager::class);
        $manager->setTeam($teamA);

        $items = TeamItem::all();

        $this->assertCount(1, $items);
        $this->assertEquals('Team A Item', $items->first()->name);
    }

    public function test_team_scope_not_applied_when_no_team_set(): void
    {
        $teamA = TeamFactory::new()->create();
        $teamB = TeamFactory::new()->create();

        TeamItem::withoutTeamScope()->create([
            'name' => 'Team A Item',
            'team_id' => $teamA->id,
        ]);
        TeamItem::withoutTeamScope()->create([
            'name' => 'Team B Item',
            'team_id' => $teamB->id,
        ]);

        $manager = app(ContextManager::class);
        $manager->clear();

        $items = TeamItem::all();

        $this->assertCount(2, $items);
    }

    // -----------------------------------------------------------------
    // withoutTeamScope()
    // -----------------------------------------------------------------

    public function test_without_team_scope_removes_global_scope(): void
    {
        $teamA = TeamFactory::new()->create();
        $teamB = TeamFactory::new()->create();

        $manager = app(ContextManager::class);
        $manager->setTeam($teamA);

        TeamItem::create([
            'name' => 'Team A Item',
        ]);

        $manager->clear();
        $manager->setTeam($teamB);
        TeamItem::create([
            'name' => 'Team B Item',
        ]);

        // With scope: only Team B items (current team)
        $scopedItems = TeamItem::all();
        $this->assertCount(1, $scopedItems);

        // Without scope: all items
        $allItems = TeamItem::withoutTeamScope()->get();
        $this->assertCount(2, $allItems);
    }

    public function test_without_team_scope_returns_query_builder(): void
    {
        $team = TeamFactory::new()->create();
        $manager = app(ContextManager::class);
        $manager->setTeam($team);

        $query = TeamItem::withoutTeamScope();

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Builder::class, $query);
    }
}

/**
 * Test model that uses the BelongsToTeam trait with default team_id column.
 */
class TeamItem extends Model
{
    use BelongsToTeam;

    protected $table = 'team_items';

    protected $fillable = ['name', 'team_id'];
}

/**
 * Test model with a custom team ID column constant.
 */
class TeamItemWithCustomColumn extends Model
{
    use BelongsToTeam;

    protected $table = 'team_items';

    protected $fillable = ['name', 'group_id'];

    public const TEAM_ID_COLUMN = 'group_id';
}
