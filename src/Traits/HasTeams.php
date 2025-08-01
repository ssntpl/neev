<?php

namespace Ssntpl\Neev\Traits;

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
        return $this->hasMany(Team::class);
    }

    public function allTeams()
    {
        return $this->belongsToMany(Team::class, Membership::class)
            ->withPivot(['role_id', 'joined'])
            ->withTimestamps()
            ->as('membership');
    }

    public function teams()
    {
        return $this->belongsToMany(Team::class, Membership::class)
            ->withPivot(['role_id', 'joined'])
            ->withTimestamps()
            ->as('membership')->where('joined', true);
    }
    
    public function teamRequests()
    {
        return $this->belongsToMany(Team::class, Membership::class)
            ->withPivot(['role_id', 'joined', 'action'])
            ->withTimestamps()
            ->as('membership')->where([
                'joined' => false,
                'action' => 'request_to_user'
            ]);
    }
    
    public function sendRequests()
    {
        return $this->belongsToMany(Team::class, Membership::class)
            ->withPivot(['role_id', 'joined', 'action'])
            ->withTimestamps()
            ->as('membership')->where([
                'joined' => false,
                'action' => 'request_from_user'
            ]);
    }
}
