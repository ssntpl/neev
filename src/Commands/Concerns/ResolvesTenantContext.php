<?php

namespace Ssntpl\Neev\Commands\Concerns;

use Ssntpl\Neev\Contracts\ContextContainerInterface;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\Tenant;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\TenantResolver;

trait ResolvesTenantContext
{
    protected function isIsolated(): bool
    {
        return config('neev.tenant', false);
    }

    protected function teamsEnabled(): bool
    {
        return config('neev.team', false);
    }

    protected function resolveTeam(string $identifier): Team
    {
        $class = Team::getClass();

        // Console commands run outside a resolved tenant, so they look teams
        // up across every tenant.
        if (ctype_digit($identifier)) {
            $team = $class::withoutTenantScope()->find((int) $identifier);
        } else {
            // Under isolation a team slug is unique per tenant only, so the
            // same slug can name a team in each of several tenants.
            $matches = $class::withoutTenantScope()->where('slug', $identifier)->limit(2)->get();

            if ($matches->count() > 1) {
                $this->fail("Team slug {$identifier} is used in more than one tenant; pass the team ID instead.");
            }

            $team = $matches->first();
        }

        if (! $team) {
            $this->fail("Team not found: {$identifier}");
        }

        return $team;
    }

    protected function resolveTenant(string $identifier): Tenant
    {
        $class = Tenant::getClass();

        $tenant = ctype_digit($identifier)
            ? $class::find((int) $identifier)
            : $class::where('slug', $identifier)->first();

        if (! $tenant) {
            $this->fail("Tenant not found: {$identifier}");
        }

        return $tenant;
    }

    /**
     * Console commands run outside a resolved tenant, so TenantScope would
     * narrow the lookup to platform users and hide every tenant's user. Resolve
     * the user inside the context the command is acting on instead, so the
     * scope does the filtering it was written to do.
     */
    protected function resolveUserByEmail(string $email, ?ContextContainerInterface $context = null): User
    {
        $user = $context
            ? app(TenantResolver::class)->runInContext($context, fn () => User::findByEmail($email))
            : User::findByEmail($email);

        if (! $user) {
            $this->fail("No user found with email: {$email}");
        }

        return $user;
    }

    /**
     * The context a team's members live in: the owning tenant under isolation,
     * the team itself in shared mode. Null when there is nothing to scope to,
     * which leaves the lookup on platform users.
     */
    protected function contextForTeam(Team $team): ?ContextContainerInterface
    {
        // A belongsTo with a null key resolves to null on its own, which is
        // the platform-team case.
        return $this->isIsolated() ? $team->tenant : $team;
    }

    protected function getStrategyLabel(): string
    {
        return $this->isIsolated() ? 'tenant' : 'team';
    }
}
