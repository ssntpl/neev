<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Model;

class Passkey extends Model
{
    protected $fillable = [
        'user_id',
        'credential_id',
        'public_key',
        'name',
        'ip',
        'location',
        'last_used',
        'aaguid',
        'transports',
    ];

    protected $hidden = [
        'public_key',
    ];

    protected $casts = [
        'transports' => 'array',
        'location' => 'array',
        'last_used' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::getClass(), 'user_id');
    }
}
