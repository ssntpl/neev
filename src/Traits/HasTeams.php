<?php

namespace Ssntpl\Neev\Traits;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Ssntpl\Neev\Models\Membership;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Scopes\TeamTenantScope;

trait HasTeams
{
    /**
     * Deleting a user would let the database cascade their teams away
     * without Eloquent, so the teams' slugs would not retire (RetiresSlugs)
     * and TeamDeleted would not fire. Delete them through the model first.
     * A soft delete keeps the user's row, and so their teams.
     */
    public static function bootHasTeams(): void
    {
        static::deleting(function (self $user) {
            if (method_exists($user, 'isForceDeleting') && ! $user->isForceDeleting()) {
                return;
            }

            $user->ownedTeams()->withoutGlobalScope(TeamTenantScope::class)->get()
                ->each(fn (Team $team) => $team->delete());
        });
    }

    /**
     * Delete the user and the teams they own together.
     */
    public function delete()
    {
        return DB::transaction(fn () => parent::delete());
    }

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
            ->where(['joined' => false, 'action' => Membership::REQUEST_TO_USER]);
    }

    public function sendRequests()
    {
        return $this->belongsToMany(Team::getClass(), Membership::class)
            ->withPivot(['joined', 'action'])
            ->withTimestamps()
            ->as('membership')
            ->where(['joined' => false, 'action' => Membership::REQUEST_FROM_USER]);
    }
}
