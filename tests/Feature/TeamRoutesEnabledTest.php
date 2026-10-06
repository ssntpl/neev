<?php

namespace Ssntpl\Neev\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Tests\TestCase;

/**
 * The other half of TeamRoutesToggleTest: with `neev.team` on, every team route
 * is registered again.
 */
class TeamRoutesEnabledTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('neev.team', true);
    }

    public function test_team_web_routes_are_registered_when_teams_are_enabled(): void
    {
        foreach (['account.teams', 'teams.profile', 'teams.switch', 'teams.create', 'teams.store', 'teams.invite', 'teams.email-domains', 'teams.email-domains.store', 'teams.email-domains.update', 'teams.email-domains.destroy', 'teams.hostnames', 'teams.hostnames.store', 'teams.hostnames.update', 'teams.hostnames.destroy'] as $name) {
            $this->assertTrue(Route::has($name), "Route [{$name}] should be registered.");
        }
    }

    public function test_team_api_routes_are_registered_when_teams_are_enabled(): void
    {
        // A real team: an unknown {team} is answered 404 before auth runs.
        $team = TeamFactory::new()->create();

        // Unauthenticated, so 401 rather than 404 — the route exists.
        $this->getJson('/neev/teams')->assertUnauthorized();
        $this->getJson("/neev/teams/{$team->id}/hostnames")->assertUnauthorized();
        $this->getJson('/neev/hostnames/1')->assertUnauthorized();
        $this->getJson("/neev/teams/{$team->id}/email-domains")->assertUnauthorized();
        $this->getJson('/neev/email-domains/1')->assertUnauthorized();
        $this->postJson('/neev/changeTeamOwner')->assertUnauthorized();
    }
}
