<?php

namespace Ssntpl\Neev\Traits;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Schema;
use Ssntpl\Neev\Models\Membership;
use Ssntpl\Neev\Models\Team;

trait HasTeams
{
    public function switchTeam($team)
    {
        if (! $this->belongsToTeam($team)) {
            return false;
        }

        $this->forceFill([
            'current_team_id' => $team->id,
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
            ->withPivot(['role_id', 'joined'])
            ->withTimestamps()
            ->as('membership');
        
        if (Schema::hasColumn('team_user', 'role')) {
            $relation->withPivot('role');
        }

        return $relation;
    }

    public function teams(): BelongsToMany
    {
        $relation = $this->belongsToMany(Team::getClass(), Membership::class)
            ->withPivot(['role_id', 'joined'])
            ->withTimestamps()
            ->as('membership')
            ->where('joined', true);

        if (Schema::hasColumn('team_user', 'role')) {
            $relation->withPivot('role');
        }

        return $relation;
    }
    
    public function teamRequests()
    {
        $relation = $this->belongsToMany(Team::getClass(), Membership::class)
            ->withPivot(['role_id', 'joined', 'action'])
            ->withTimestamps()
            ->as('membership')->where([
                'joined' => false,
                'action' => 'request_to_user'
            ]);

        if (Schema::hasColumn('team_user', 'role')) {
            $relation->withPivot('role');
        }

        return $relation;
    }
    
    public function sendRequests()
    {
        $relation = $this->belongsToMany(Team::getClass(), Membership::class)
            ->withPivot(['role_id', 'joined', 'action'])
            ->withTimestamps()
            ->as('membership')->where([
                'joined' => false,
                'action' => 'request_from_user'
            ]);
        
        if (Schema::hasColumn('team_user', 'role')) {
            $relation->withPivot('role');
        }

        return $relation;
    }
}
