<?php

namespace Ssntpl\Neev\Models;

use Exception;
use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    public static function model() {
        $class = config('neev.team_model', Team::class);
        return new $class;
    }
    
    public static function getClass() {
        return config('neev.team_model', Team::class);
    }

    protected $fillable = [
        'user_id',
        'name',
        'is_public',
    ];

    protected $casts = [
        'is_public' => 'bool',
    ];

    public function owner()
    {
        return $this->belongsTo(User::getClass(), 'user_id');
    }

    public function allUsers()
    {
        $relation = $this->belongsToMany(User::getClass(), Membership::class)
            ->withPivot(['role', 'joined'])
            ->withTimestamps()
            ->as('membership');

        return $relation;
    }

    public function users()
    {
        $relation = $this->belongsToMany(User::getClass(), Membership::class)
            ->withPivot(['role', 'joined'])
            ->withTimestamps()
            ->as('membership')->where('joined', true);

        return $relation;
    }
    
    public function joinRequests()
    {
        $relation = $this->belongsToMany(User::getClass(), Membership::class)
            ->withPivot(['role', 'joined', 'action'])
            ->withTimestamps()
            ->as('membership')->where([
                'joined' => false,
                'action' => 'request_from_user'
            ]);

        return $relation;
    }
    
    public function invitedUsers()
    {
        $relation = $this->belongsToMany(User::getClass(), Membership::class)
            ->withPivot(['role', 'joined', 'action'])
            ->withTimestamps()
            ->as('membership')->where([
                'joined' => false,
                'action' => 'request_to_user'
            ]);

        return $relation;
    }

    public function removeUser($user)
    {
        if ($this->owner()?->id === $user?->id) {
            throw new Exception('cannot remove owner.');
        }

        $this->users()?->detach($user);
    }

    public function domains()
    {
        if (!config('neev.domain_federation', false)) {
            // if domains table does not exist, return empty relation
            return $this->belongsTo(Domain::class)->whereRaw('1 = 0');
        }
        
        return $this->hasMany(Domain::class);
    }

    public function domain()
    {
        if (!config('neev.domain_federation', false)) {
            // if domains table does not exist, return empty relation
            return $this->belongsTo(Domain::class)->whereRaw('1 = 0');
        }
        
        return $this->hasOne(Domain::class)->where('is_primary', true);
    }

    public function invitations()
    {
        return $this->hasMany(TeamInvitation::class);
    }

    public function hasUser($user): bool
    {
        return $this->users?->contains($user);
    }
}
