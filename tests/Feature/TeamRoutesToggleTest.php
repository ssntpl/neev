<?php

namespace Ssntpl\Neev\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Ssntpl\Neev\Tests\TestCase;

/**
 * Teams are opt-in (`neev.team`). With them switched off, an application has no
 * team tables and no team UI, so the routes that serve them are not registered
 * at all rather than answering with a runtime failure.
 */
class TeamRoutesToggleTest extends TestCase
{
    /** Route names that only make sense once teams are enabled. */
    protected array $webRouteNames = [
        'account.teams',
        'teams.profile',
        'teams.switch',
        'teams.create',
        'teams.members',
        'teams.email-domains',
        'teams.settings',
        'teams.store',
        'teams.update',
        'teams.delete',
        'teams.invite',
        'teams.leave',
        'teams.roles.change',
        'teams.invite.action',
        'teams.request',
        'teams.request.action',
        'teams.owner.change',
        'teams.email-domains.store',
        'teams.email-domains.update',
        'teams.email-domains.destroy',
        'teams.hostnames',
        'teams.hostnames.store',
        'teams.hostnames.update',
        'teams.hostnames.destroy',
        'teams.rules',
    ];

    protected array $apiPaths = [
        ['get', '/neev/teams'],
        ['post', '/neev/teams'],
        ['get', '/neev/teams/invitations'],
        ['post', '/neev/changeTeamOwner'],
        ['get', '/neev/teams/1/email-domains'],
        ['get', '/neev/teams/1/hostnames'],
        ['get', '/neev/teams/1/rules'],
        ['get', '/neev/email-domains/1'],
        ['get', '/neev/hostnames/1'],
    ];

    public function test_team_routes_are_absent_when_teams_are_disabled(): void
    {
        // `neev.team` defaults to false.
        $this->assertFalse(config('neev.team'));

        foreach ($this->webRouteNames as $name) {
            $this->assertFalse(Route::has($name), "Route [{$name}] should not be registered.");
        }

        foreach ($this->apiPaths as [$method, $path]) {
            $this->json(strtoupper($method), $path)->assertNotFound();
        }
    }

    public function test_routes_that_do_not_belong_to_teams_stay_registered(): void
    {
        $this->assertTrue(Route::has('account.security'));
        $this->assertTrue(Route::has('account.profile'));
        $this->assertTrue(Route::has('login'));
    }
}
