<?php

namespace Ssntpl\Neev\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Ssntpl\Neev\Services\TenantResolver;

class TenantScope implements Scope
{
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
            // Tenant isolation enabled but no tenant resolved yet.
            // Skip scope to allow authentication pipeline to function.
            // Once tenant is resolved, scope applies on subsequent queries.
            return;
        }

        // Always filter by tenant — no configurable "non-strict" mode.
        $builder->where(
            $model->getQualifiedTenantIdColumn(),
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
