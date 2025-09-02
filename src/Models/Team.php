<?php

namespace Ssntpl\Neev\Models;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Schema;

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
        'enforce_domain',
        'domain_federated',
        'domain_verified_at',
        'domain_verification_token',
    ];

    protected $casts = [
        'is_public' => 'bool',
        'enforce_domain' => 'bool',
        'domain_verified_at' => 'datetime',
        'domain_verification_token' => 'hashed',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function allUsers()
    {
        $relation = $this->belongsToMany(User::class, Membership::class)
            ->withPivot(['role_id', 'joined'])
            ->withTimestamps()
            ->as('membership');

        if (Schema::hasColumn('team_user', 'role')) {
            $relation->withPivot('role');
        }

        return $relation;
    }

    public function users()
    {
        $relation = $this->belongsToMany(User::class, Membership::class)
            ->withPivot(['role_id', 'joined'])
            ->withTimestamps()
            ->as('membership')->where('joined', true);
        
        if (Schema::hasColumn('team_user', 'role')) {
            $relation->withPivot('role');
        }

        return $relation;
    }
    
    public function joinRequests()
    {
        $relation = $this->belongsToMany(User::class, Membership::class)
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
    
    public function invitedUsers()
    {
        $relation = $this->belongsToMany(User::class, Membership::class)
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

    public function removeUser($user)
    {
        if ($this->owner()->id === $user->id) {
            throw new Exception('cannot remove owner.');
        }

        $this->users()->detach($user);
    }

    public function roles()
    {
        return $this->hasMany(Role::class);
    }

    public function rules()
    {
        return $this->hasMany(DomainRule::class);
    }

    public function rule($name)
    {
        return $this->hasMany(DomainRule::class)->where('name', $name)->first();
    }

    public function invitations()
    {
        return $this->hasMany(TeamInvitation::class);
    }

    public function hasUser($user): bool
    {
        return $this->users->contains($user);
    }
}
