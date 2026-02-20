<?php

namespace Ssntpl\Neev\Services;

use LogicException;
use Ssntpl\Neev\Contracts\ContextContainerInterface;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\Tenant;
use Ssntpl\Neev\Models\User;

/**
 * Request-scoped context manager.
 *
 * Holds the resolved tenant, team, and user for the current request.
 * Context is resolved once per request via middleware and is read-only after binding.
 */
class ContextManager
{
    protected ?Tenant $tenant = null;

    protected ?Team $team = null;

    protected ?User $user = null;

    protected bool $bound = false;

    public function setContext(ContextContainerInterface $context): void
    {
        $this->guardImmutability();

        match ($context->getContextType()) { // @phpstan-ignore match.unhandled
            'tenant' => $this->tenant = $context, // @phpstan-ignore assign.propertyType
            'team' => $this->team = $context, // @phpstan-ignore assign.propertyType
        };
    }

    public function setTenant(?Tenant $tenant): void
    {
        $this->guardImmutability();
        $this->tenant = $tenant;
    }

    public function setTeam(?Team $team): void
    {
        $this->guardImmutability();
        $this->team = $team;
    }

    public function setUser(?User $user): void
    {
        $this->guardImmutability();
        $this->user = $user;
    }

    /**
     * Throw if the context has already been bound.
     */
    protected function guardImmutability(): void
    {
        if ($this->bound) {
            throw new LogicException('Cannot modify context after it has been bound.');
        }
    }

    /**
     * Mark the context as bound (immutable after this call).
     */
    public function bind(): void
    {
        $this->bound = true;
    }

    public function isBound(): bool
    {
        return $this->bound;
    }

    public function currentTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function currentTeam(): ?Team
    {
        return $this->team;
    }

    public function currentUser(): ?User
    {
        return $this->user;
    }

    /**
     * Get the current context container (tenant in isolated mode, team in shared mode).
     */
    public function currentContext(): ?ContextContainerInterface
    {
        return $this->tenant ?? $this->team;
    }

    public function hasTenant(): bool
    {
        return $this->tenant !== null;
    }

    public function hasTeam(): bool
    {
        return $this->team !== null;
    }

    public function hasUser(): bool
    {
        return $this->user !== null;
    }

    /**
     * @internal Testing only. Resets all context including the immutability flag.
     * Not intended for production request lifecycles.
     */
    public function clear(): void
    {
        $this->tenant = null;
        $this->team = null;
        $this->user = null;
        $this->bound = false;
    }
}
