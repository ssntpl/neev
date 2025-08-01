<?php

namespace Ssntpl\Neev\Models;

use Exception;
use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'is_public',
    ];

    protected $casts = [
        'is_public' => 'bool'
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function allUsers()
    {
        return $this->belongsToMany(User::class, Membership::class)
            ->withPivot(['role_id', 'joined'])
            ->withTimestamps()
            ->as('membership');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, Membership::class)
            ->withPivot(['role_id', 'joined'])
            ->withTimestamps()
            ->as('membership')->where('joined', true);
    }
    
    public function joinRequests()
    {
        return $this->belongsToMany(User::class, Membership::class)
            ->withPivot(['role_id', 'joined', 'action'])
            ->withTimestamps()
            ->as('membership')->where([
                'joined' => false,
                'action' => 'request_from_user'
            ]);
    }
    
    public function invitedUsers()
    {
        return $this->belongsToMany(User::class, Membership::class)
            ->withPivot(['role_id', 'joined', 'action'])
            ->withTimestamps()
            ->as('membership')->where([
                'joined' => false,
                'action' => 'request_to_user'
            ]);
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
}
