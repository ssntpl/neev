<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Model;
use Ssntpl\Neev\Models\User;

class MultiFactorAuth extends Model
{   
    protected $fillable = [
        'user_id',
        'method',
        'secret',
        'otp',
        'expires_at',
        'last_used',
        'prefered'
    ];

    protected $hidden = [
        'secret',
        'otp',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_used' => 'datetime',
        'secret' => 'encrypted',
        'prefered' => 'bool'
    ];

    public function user()
    {
        return $this->belongsTo(User::getClass(),'user_id');
    }
}
