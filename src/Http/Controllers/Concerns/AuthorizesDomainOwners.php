<?php

namespace Ssntpl\Neev\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\Tenant;
use Ssntpl\Neev\Services\TenantResolver;

/**
 * Whether the caller belongs to the owner of a host or email domain: a team's
 * owner or members, or anyone in the tenant the request resolved to
 * (EnsureTenantMembership). That keeps one owner's rows out of another's
 * reach by id. Which of the owner's people may read or change them is left to
 * the application, through its own middleware on these routes.
 */
trait AuthorizesDomainOwners
{
    protected function belongsToOwner(Request $request, ?Model $owner): bool
    {
        $user = $request->user();

        if ($user === null) {
            return false;
        }

        if ($owner instanceof Team) {
            return $owner->user_id === $user->getKey() || $owner->hasMember($user);
        }

        if ($owner instanceof Tenant) {
            $current = app(TenantResolver::class)->currentTenant();

            return $current !== null && $current->is($owner);
        }

        return false;
    }
}
