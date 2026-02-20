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
        if (!$this->shouldApplyScope()) {
            return;
        }

        $tenantId = $this->getTenantResolver()->currentId();

        if ($tenantId !== null) {
            $builder->where($model->getQualifiedTenantIdColumn(), $tenantId);
        }
    }

    public function extend(Builder $builder): void
    {
        $builder->macro('withoutTenantScope', function (Builder $builder) {
            return $builder->withoutGlobalScope(TenantScope::class);
        });
    }

    protected function shouldApplyScope(): bool
    {
        if (!app()->bound(TenantResolver::class)) {
            return false;
        }

        return $this->getTenantResolver()->hasTenant();
    }

    protected function getTenantResolver(): TenantResolver
    {
        return app(TenantResolver::class);
    }
}
