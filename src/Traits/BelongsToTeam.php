<?php

namespace Ssntpl\Neev\Traits;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Scopes\TeamScope;
use Ssntpl\Neev\Services\ContextManager;

trait BelongsToTeam
{
    public static function bootBelongsToTeam(): void
    {
        static::addGlobalScope(new TeamScope());

        static::creating(function ($model) {
            if ($model->{$model->getTeamIdColumn()} !== null) {
                return;
            }

            if (!app()->bound(ContextManager::class)) {
                return;
            }

            $manager = app(ContextManager::class);

            if ($manager->hasTeam()) {
                $model->{$model->getTeamIdColumn()} = $manager->currentTeam()->getContextId();
            }
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::getClass(), $this->getTeamIdColumn());
    }

    public function getTeamIdColumn(): string
    {
        return defined('static::TEAM_ID_COLUMN')
            ? static::TEAM_ID_COLUMN
            : 'team_id';
    }

    public function getQualifiedTeamIdColumn(): string
    {
        return $this->qualifyColumn($this->getTeamIdColumn());
    }

    public static function withoutTeamScope()
    {
        return (new static())->newQueryWithoutScope(TeamScope::class);
    }
}
