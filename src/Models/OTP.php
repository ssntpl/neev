<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Model;

class OTP extends Model
{

    public const Email = 'email';

    protected $table = 'otp';
    protected $fillable = [
        'user_id',
        'method',
        'otp',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::getClass(),'user_id');
    }
}
