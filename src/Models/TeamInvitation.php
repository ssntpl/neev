<?php

namespace Ssntpl\Neev\Models;

use Exception;
use Illuminate\Database\Eloquent\Model;

class TeamInvitation extends Model
{
    protected $fillable = [
        'team_id',
        'role_id',
        'email',
        'expires_at'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function getProfilePhotoUrlAttribute()
    {
        return collect(explode(' ', $this->email))->map(fn($word) => strtoupper(substr($word, 0, 2)))->join('');;
    }

    public function team()
    {
        return $this->belongsTo(Team::getClass(),  'team_id');
    }

    public function role()
    {
        return $this->belongsTo(Role::class,  'role_id');
    }
}
