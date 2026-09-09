<?php

namespace Ssntpl\Neev\Tests\Unit;

use Illuminate\Support\Facades\Route;
use Ssntpl\Neev\Tests\TestCase;

/**
 * The API routes are declared in `prefix()` groups rather than as a flat list
 * of full paths. Grouping is purely cosmetic — every URI must stay exactly
 * where it was — but a stray or missing slash inside a group silently moves
 * an endpoint, and a consumer only finds out with a 404. This pins the map.
 */
class ApiRouteMapTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Team and domain routes are only registered when teams are on.
        $app['config']->set('neev.team', true);
    }

    /**
     * Every registered `{method} {uri}` pair under the API prefix.
     *
     * @return list<string>
     */
    private function registeredApiRoutes(): array
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            if (!str_starts_with($route->uri(), 'neev/')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $routes[] = $method . ' ' . $route->uri();
            }
        }

        sort($routes);

        return array_values(array_unique($routes));
    }

    /**
     * The endpoints whose declarations moved into a `prefix()` group. Each is
     * spelled out as the full path a consumer calls, which is the thing the
     * grouping must not change.
     *
     * @return list<string>
     */
    private function groupedRoutes(): array
    {
        return [
            // email
            'POST neev/email/send',
            'POST neev/email/verify-otp',
            'POST neev/email/change',

            // mfa
            'GET neev/mfa',
            'POST neev/mfa/add',
            'POST neev/mfa/setup/verify',
            'PUT neev/mfa/preferred',
            'DELETE neev/mfa/delete',

            // passkeys
            'GET neev/passkeys',
            'GET neev/passkeys/register/options',
            'POST neev/passkeys/register',
            'DELETE neev/passkeys',
            'PUT neev/passkeys',

            // apiTokens
            'GET neev/apiTokens',
            'POST neev/apiTokens',
            'PUT neev/apiTokens',
            'DELETE neev/apiTokens',
            'DELETE neev/apiTokens/deleteAll',

            // teams
            'GET neev/teams',
            'GET neev/teams/invitations',
            'PUT neev/teams/default',
            'GET neev/teams/slug/{slug}',
            'GET neev/teams/{id}',
            'POST neev/teams',
            'PUT neev/teams',
            'DELETE neev/teams',
            'POST neev/teams/inviteUser',
            'PUT neev/teams/inviteUser',
            'PUT neev/teams/leave',
            'POST neev/teams/request',
            'PUT neev/teams/request',

            // domains
            'GET neev/domains',
            'POST neev/domains',
            'PUT neev/domains',
            'DELETE neev/domains',
            'PUT neev/domains/rules',
            'GET neev/domains/rules',
            'PUT neev/domains/primary',

            // left outside the team group on purpose
            'POST neev/changeTeamOwner',
        ];
    }

    public function test_every_grouped_route_keeps_its_full_path(): void
    {
        $registered = $this->registeredApiRoutes();

        foreach ($this->groupedRoutes() as $route) {
            $this->assertContains(
                $route,
                $registered,
                "{$route} is missing — a prefix group moved or dropped it."
            );
        }
    }

    /**
     * A `prefix('/teams')` group whose inner route is declared as `''` rather
     * than `'/'` registers `neev/teams/` with a trailing slash, which Laravel
     * matches as a separate URI. Nothing should carry one.
     */
    public function test_no_api_route_has_a_trailing_slash(): void
    {
        foreach ($this->registeredApiRoutes() as $route) {
            $this->assertStringEndsNotWith('/', $route, "{$route} has a trailing slash.");
        }
    }

    /**
     * `teams/slug/{slug}` has to be declared before `teams/{id}`, or the id
     * route swallows it and every slug lookup is read as an id.
     */
    public function test_the_team_slug_route_is_matched_before_the_id_route(): void
    {
        $order = [];

        foreach (Route::getRoutes() as $route) {
            if (in_array($route->uri(), ['neev/teams/slug/{slug}', 'neev/teams/{id}'], true)
                && in_array('GET', $route->methods(), true)) {
                $order[] = $route->uri();
            }
        }

        $this->assertSame(
            ['neev/teams/slug/{slug}', 'neev/teams/{id}'],
            $order,
            'The slug route must be registered first or {id} will shadow it.'
        );
    }

    /**
     * `teams/invitations` and `teams/default` are likewise fixed segments
     * competing with `teams/{id}`.
     */
    public function test_the_fixed_team_segments_are_matched_before_the_id_route(): void
    {
        $seenId = false;

        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === 'neev/teams/{id}') {
                $seenId = true;
            }

            if ($route->uri() === 'neev/teams/invitations') {
                $this->assertFalse($seenId, 'teams/invitations must be registered before teams/{id}.');
            }
        }

        $this->assertTrue($seenId, 'teams/{id} should be registered.');
    }
}
