<?php

namespace Ssntpl\Neev\Traits;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Ssntpl\Neev\Models\Membership;
use Ssntpl\Neev\Models\Team;

trait HasTeams
{
    /**
     * Get the user's current team.
     */
    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(Team::getClass(), 'current_team_id');
    }

    /**
     * Determine if the user belongs to the given team.
     */
    public function belongsToTeam($team): bool
    {
        if (!$team) {
            return false;
        }

        return $this->teams()->where('teams.id', $team->id)->exists();
    }

    public function switchTeam($team)
    {
        if (! $this->belongsToTeam($team)) {
            return false;
        }

        $this->forceFill([
            'current_team_id' => $team?->id,
        ])->save();

        $this->setRelation('currentTeam', $team);

        return true;
    }

    public function ownedTeams()
    {
        return $this->hasMany(Team::getClass());
    }

    public function allTeams()
    {
        $relation = $this->belongsToMany(Team::getClass(), Membership::class)
            ->withPivot(['role', 'joined'])
            ->withTimestamps()
            ->as('membership');

        return $relation;
    }

    public function teams(): BelongsToMany
    {
        $relation = $this->belongsToMany(Team::getClass(), Membership::class)
            ->withPivot(['role', 'joined'])
            ->withTimestamps()
            ->as('membership')
            ->where('joined', true);

        return $relation;
    }
    
    public function teamRequests()
    {
        $relation = $this->belongsToMany(Team::getClass(), Membership::class)
            ->withPivot(['role', 'joined', 'action'])
            ->withTimestamps()
            ->as('membership')->where([
                'joined' => false,
                'action' => 'request_to_user'
            ]);

        return $relation;
    }
    
    public function sendRequests()
    {
        $relation = $this->belongsToMany(Team::getClass(), Membership::class)
            ->withPivot(['role', 'joined', 'action'])
            ->withTimestamps()
            ->as('membership')->where([
                'joined' => false,
                'action' => 'request_from_user'
            ]);

        return $relation;
    }
}
