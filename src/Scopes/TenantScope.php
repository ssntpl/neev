<?php

namespace Ssntpl\Neev\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Ssntpl\Neev\Services\TenantResolver;

class TenantScope implements Scope
{
    /**
     * This scope must only be applied to models using the BelongsToTenant trait,
     * which provides getQualifiedTenantIdColumn(). Misuse will crash at runtime.
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (! app()->bound(TenantResolver::class)) {
            return;
        }

        $resolver = app(TenantResolver::class);

        if (! $resolver->isEnabled()) {
            return;
        }

        if (! $resolver->hasTenant()) {
            // Tenant isolation enabled but no tenant resolved — scope to platform users only.
            // Only users with tenant_id = null (platform admins, global users) are visible.
            // Tenant-scoped users remain invisible, preventing cross-tenant data leakage.
            $builder->whereNull($model->getQualifiedTenantIdColumn()); // @phpstan-ignore method.notFound
            return;
        }

        // Always filter by tenant — no configurable "non-strict" mode.
        $builder->where(
            $model->getQualifiedTenantIdColumn(), // @phpstan-ignore method.notFound
            $resolver->currentId()
        );
    }

    public function extend(Builder $builder): void
    {
        $builder->macro('withoutTenantScope', function (Builder $builder) {
            return $builder->withoutGlobalScope(TenantScope::class);
        });
    }
}
