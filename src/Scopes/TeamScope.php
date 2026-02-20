<?php

namespace Ssntpl\Neev\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Ssntpl\Neev\Services\ContextManager;

class TeamScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (!$this->shouldApplyScope()) {
            return;
        }

        $teamId = $this->getContextManager()->currentTeam()?->getContextId();

        if ($teamId !== null) {
            $builder->where($model->getQualifiedTeamIdColumn(), $teamId);
        }
    }

    public function extend(Builder $builder): void
    {
        $builder->macro('withoutTeamScope', function (Builder $builder) {
            return $builder->withoutGlobalScope(TeamScope::class);
        });
    }

    protected function shouldApplyScope(): bool
    {
        if (!app()->bound(ContextManager::class)) {
            return false;
        }

        return $this->getContextManager()->hasTeam();
    }

    protected function getContextManager(): ContextManager
    {
        return app(ContextManager::class);
    }
}
