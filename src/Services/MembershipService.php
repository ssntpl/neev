<?php

namespace Ssntpl\Neev\Services;

use Ssntpl\Neev\Contracts\HasMembersInterface;
use Ssntpl\Neev\Models\User;

/**
 * Entity-agnostic membership service.
 *
 * Operates on HasMembersInterface so the same membership logic works
 * for both Tenant and Team without type conditionals.
 */
class MembershipService
{
    /**
     * Check if a user is a member of the given container (Tenant or Team).
     */
    public function isMember(HasMembersInterface $container, $user): bool
    {
        return $container->hasMember($user);
    }
}
