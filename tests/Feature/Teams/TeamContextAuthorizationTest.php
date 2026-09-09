<?php

namespace Ssntpl\Neev\Tests\Feature\Teams;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;
use Ssntpl\Neev\Traits\BelongsToTeam;

/** What a consuming application writes, per docs/teams.md. */
class ContextProbeDocument extends Model
{
    use BelongsToTeam;

    protected $table = 'context_probe_documents';

    protected $guarded = [];

    public $timestamps = false;
}

/**
 * The team context decides what `TeamScope` shows, so arriving at a team
 * context is an authorization step, not just a lookup. A team named by the
 * request — a route parameter or the `X-Team` header — is a claim the caller
 * makes about themselves, and no package model carries `BelongsToTeam`, so
 * the damage lands entirely in the consuming application's own tables.
 */
class TeamContextAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('neev.team', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('context_probe_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id');
            $table->string('secret');
        });

        Route::middleware(['web', 'neev:web'])->get('/context-probe-documents', function () {
            return response()->json(ContextProbeDocument::query()->pluck('secret'));
        });
    }

    /** @return array{0: \Ssntpl\Neev\Models\Team, 1: User} */
    protected function teamWithOwner(): array
    {
        $owner = User::factory()->create();
        $team = TeamFactory::new()->create(['user_id' => $owner->id]);
        $team->addMember($owner);

        return [$team, $owner];
    }

    public function test_a_member_reads_their_own_teams_records(): void
    {
        [$team, $owner] = $this->teamWithOwner();
        ContextProbeDocument::query()->withoutGlobalScopes()
            ->insert(['team_id' => $team->id, 'secret' => 'own-record']);

        $this->actingAs($owner)
            ->withHeader('X-Team', (string) $team->id)
            ->getJson('/context-probe-documents')
            ->assertOk()
            ->assertJsonFragment(['own-record']);
    }

    /**
     * The header is the whole attack: it is attacker-chosen, it is accepted on
     * every route in the neev groups, and `TeamScope` trusts whatever it names.
     */
    public function test_the_x_team_header_cannot_name_a_team_the_caller_is_not_in(): void
    {
        [$victimTeam, $victim] = $this->teamWithOwner();
        [$ownTeam, $outsider] = $this->teamWithOwner();

        ContextProbeDocument::query()->withoutGlobalScopes()->insert([
            ['team_id' => $victimTeam->id, 'secret' => 'victim-confidential'],
            ['team_id' => $ownTeam->id, 'secret' => 'outsider-own'],
        ]);

        $response = $this->actingAs($outsider)
            ->withHeader('X-Team', (string) $victimTeam->id)
            ->getJson('/context-probe-documents');

        $this->assertNotEquals(200, $response->getStatusCode(), 'The request must not succeed under a team the caller does not belong to.');
        $response->assertDontSee('victim-confidential');
    }

    /** Slugs are the other accepted form of the same claim. */
    public function test_the_x_team_header_is_refused_by_slug_too(): void
    {
        [$victimTeam, $victim] = $this->teamWithOwner();
        [$ownTeam, $outsider] = $this->teamWithOwner();

        ContextProbeDocument::query()->withoutGlobalScopes()
            ->insert(['team_id' => $victimTeam->id, 'secret' => 'victim-confidential']);

        $this->actingAs($outsider)
            ->withHeader('X-Team', (string) $victimTeam->slug)
            ->getJson('/context-probe-documents')
            ->assertDontSee('victim-confidential');
    }
}
