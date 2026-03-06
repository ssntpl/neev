<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $team_id
 * @property string|null $role
 * @property string $email
 * @property \Carbon\Carbon|null $expires_at
 * @property-read Team|null $team
 */
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
