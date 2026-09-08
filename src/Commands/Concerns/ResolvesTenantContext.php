<?php

namespace Ssntpl\Neev\Commands\Concerns;

use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\Tenant;
use Ssntpl\Neev\Models\User;

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
        $team = ctype_digit($identifier)
            ? $class::withoutTenantScope()->find((int) $identifier)
            : $class::withoutTenantScope()->where('slug', $identifier)->first();

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

    protected function resolveUserByEmail(string $email): User
    {
        $user = User::findByEmail($email);

        if (! $user) {
            $this->fail("No user found with email: {$email}");
        }

        return $user;
    }

    protected function getStrategyLabel(): string
    {
        return $this->isIsolated() ? 'tenant' : 'team';
    }
}
