<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Model;

class TeamInvitation extends Model
{
    protected $fillable = [
        'team_id',
        'role',
        'email',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function getProfilePhotoUrlAttribute()
    {
        return strtoupper(substr($this->email, 0, 1));
    }

    public function team()
    {
        return $this->belongsTo(Team::getClass(), 'team_id');
    }
}
