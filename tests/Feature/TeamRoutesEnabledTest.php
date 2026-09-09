<?php

namespace Ssntpl\Neev\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Ssntpl\Neev\Tests\TestCase;

/**
 * The other half of TeamRoutesToggleTest: with `neev.team` on, every team route
 * is registered again.
 */
class TeamRoutesEnabledTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('neev.team', true);
    }

    public function test_team_web_routes_are_registered_when_teams_are_enabled(): void
    {
        foreach (['account.teams', 'teams.profile', 'teams.switch', 'teams.create', 'teams.store', 'teams.invite', 'domain.primary'] as $name) {
            $this->assertTrue(Route::has($name), "Route [{$name}] should be registered.");
        }
    }

    public function test_team_api_routes_are_registered_when_teams_are_enabled(): void
    {
        // Unauthenticated, so 401 rather than 404 — the route exists.
        $this->getJson('/neev/teams')->assertUnauthorized();
        $this->getJson('/neev/domains')->assertUnauthorized();
        $this->postJson('/neev/changeTeamOwner')->assertUnauthorized();
    }
}
