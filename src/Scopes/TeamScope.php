<?php

namespace Ssntpl\Neev\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Ssntpl\Neev\Services\ContextManager;

class TeamScope implements Scope
{
    /**
     * This scope must only be applied to models using the BelongsToTeam trait,
     * which provides getQualifiedTeamIdColumn(). Misuse will crash at runtime.
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (!app()->bound(ContextManager::class)) {
            return;
        }

        if (!config('neev.team', false)) {
            // Teams feature is not enabled — don't apply scope
            return;
        }

        $manager = app(ContextManager::class);
        $teamId = $manager->currentTeam()?->getContextId();

        if ($teamId !== null) {
            $builder->where($model->getQualifiedTeamIdColumn(), $teamId); // @phpstan-ignore method.notFound
        } else {
            $builder->whereRaw('1 = 0');
        }
    }

    public function extend(Builder $builder): void
    {
        $builder->macro('withoutTeamScope', function (Builder $builder) {
            return $builder->withoutGlobalScope(TeamScope::class);
        });
    }
}
