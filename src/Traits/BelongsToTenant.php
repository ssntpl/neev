<?php

namespace Ssntpl\Neev\Traits;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\Tenant;
use Ssntpl\Neev\Scopes\TenantScope;
use Ssntpl\Neev\Services\TenantResolver;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function ($model) {
            if ($model->{$model->getTenantIdColumn()} !== null) {
                return;
            }

            if (!app()->bound(TenantResolver::class)) {
                return;
            }

            $resolver = app(TenantResolver::class);

            if ($resolver->hasTenant()) {
                $model->{$model->getTenantIdColumn()} = $resolver->currentId();
            }
        });
    }

    public function tenant(): BelongsTo
    {
        $parentModel = config('neev.identity_strategy', 'shared') === 'isolated'
            ? Tenant::getClass()
            : Team::getClass();

        return $this->belongsTo($parentModel, $this->getTenantIdColumn());
    }

    public function getTenantIdColumn(): string
    {
        return defined('static::TENANT_ID_COLUMN')
            ? static::TENANT_ID_COLUMN
            : 'tenant_id';
    }

    public function getQualifiedTenantIdColumn(): string
    {
        return $this->qualifyColumn($this->getTenantIdColumn());
    }

    public static function withoutTenantScope()
    {
        return (new static())->newQueryWithoutScope(TenantScope::class);
    }
}
