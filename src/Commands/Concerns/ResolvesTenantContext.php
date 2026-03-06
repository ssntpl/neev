<?php

namespace Ssntpl\Neev\Commands\Concerns;

use Ssntpl\Neev\Models\Email;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\Tenant;
use Ssntpl\Neev\Models\User;

trait ResolvesTenantContext
{
    protected function isIsolated(): bool
    {
        return config('neev.identity_strategy', 'shared') === 'isolated';
    }

    protected function resolveTeam(string $identifier): Team
    {
        $class = Team::getClass();

        $team = ctype_digit($identifier)
            ? $class::find((int) $identifier)
            : $class::where('slug', $identifier)->first();

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
        $emailRecord = Email::findByEmail($email);

        if (! $emailRecord) {
            $this->fail("No user found with email: {$email}");
        }

        return $emailRecord->user;
    }

    protected function getStrategyLabel(): string
    {
        return $this->isIsolated() ? 'tenant' : 'team';
    }
}
