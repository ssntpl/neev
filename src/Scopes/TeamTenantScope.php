<?php

namespace Ssntpl\Neev\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Ssntpl\Neev\Services\TenantResolver;

/**
 * Tenant isolation for teams, mirroring TenantScope on users.
 *
 * Teams cannot use TenantScope itself: the resolved context is not always a
 * Tenant. In shared mode it is a Team, and setCurrentTenant() puts a Team there
 * even under isolation — comparing a team id against tenant_id would be
 * meaningless. So the tenant is taken from the context, whatever its type.
 */
class TeamTenantScope implements Scope
{
    /**
     * Only for models exposing getQualifiedTenantIdColumn() — i.e. Team.
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (! app()->bound(TenantResolver::class)) {
            return;
        }

        $resolver = app(TenantResolver::class);

        if (! $resolver->isEnabled()) {
            // Tenant isolation is off: every team lives on the platform.
            return;
        }

        $column = $model->getQualifiedTenantIdColumn(); // @phpstan-ignore method.notFound
        $context = $resolver->resolvedContext();

        $tenantId = match (true) {
            $context === null => null,
            $context->getContextType() === 'tenant' => $context->getContextId(),
            // A team context stands for the tenant that team belongs to.
            default => $context->tenant_id ?? null,
        };

        if ($tenantId === null) {
            // No tenant in play — only platform teams are visible, the same way
            // TenantScope limits users to platform users.
            $builder->whereNull($column);

            return;
        }

        $builder->where($column, $tenantId);
    }

    public function extend(Builder $builder): void
    {
        $builder->macro('withoutTenantScope', function (Builder $builder) {
            return $builder->withoutGlobalScope(TeamTenantScope::class);
        });
    }
}
