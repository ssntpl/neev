<?php

namespace Ssntpl\Neev\Traits;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Ssntpl\Neev\Models\Membership;
use Ssntpl\Neev\Models\Team;

trait HasTeams
{
    /**
     * Get the user's default team — the team to land on after login
     * when no team context is provided in the request.
     *
     * This is a user preference persisted in the database, NOT the
     * request-scoped team context (which comes from TenantResolver/ContextManager).
     * Updated automatically when the user switches teams via setDefaultTeam().
     */
    public function defaultTeam(): BelongsTo
    {
        return $this->belongsTo(Team::getClass(), 'default_team_id');
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

    /**
     * Set the user's default team preference.
     *
     * Persists the team ID to the database so the user returns to this
     * team on their next login. Returns false if the user is not a member.
     */
    public function setDefaultTeam($team)
    {
        if (! $this->belongsToTeam($team)) {
            return false;
        }

        $this->forceFill([
            'default_team_id' => $team?->id,
        ])->save();

        $this->setRelation('defaultTeam', $team);

        return true;
    }

    public function ownedTeams()
    {
        return $this->hasMany(Team::getClass());
    }

    public function allTeams()
    {
        return $this->belongsToMany(Team::getClass(), Membership::class)
            ->withPivot(['joined'])
            ->withTimestamps()
            ->as('membership');
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::getClass(), Membership::class)
            ->withPivot(['joined'])
            ->withTimestamps()
            ->as('membership')
            ->where('joined', true);
    }

    public function teamRequests()
    {
        return $this->belongsToMany(Team::getClass(), Membership::class)
            ->withPivot(['joined', 'action'])
            ->withTimestamps()
            ->as('membership')
            ->where(['joined' => false, 'action' => 'request_to_user']);
    }

    public function sendRequests()
    {
        return $this->belongsToMany(Team::getClass(), Membership::class)
            ->withPivot(['joined', 'action'])
            ->withTimestamps()
            ->as('membership')
            ->where(['joined' => false, 'action' => 'request_from_user']);
    }
}
