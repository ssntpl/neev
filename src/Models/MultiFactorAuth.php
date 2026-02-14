<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Model;

class MultiFactorAuth extends Model
{
    protected $fillable = [
        'user_id',
        'method',
        'secret',
        'otp',
        'expires_at',
        'last_used',
        'preferred',
    ];

    protected $hidden = [
        'secret',
        'otp',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_used' => 'datetime',
        'secret' => 'encrypted',
        'otp' => 'hashed',
        'preferred' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::getClass(), 'user_id');
    }
}
