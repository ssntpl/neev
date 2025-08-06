<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Model;
use Ssntpl\Neev\Models\User;

class MultiFactorAuth extends Model
{
    private static $methods = [
        'authenticator' => 'Authenticator App',
        'email' => 'Email OTP',
        'recovery' => 'Recovery Code',
    ];
    
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

    public static function authenticator()
    {
        return 'authenticator';
    }

    public static function email()
    {
        return 'email';
    }

    public static function UIName($name)
    {
        return self::$methods[$name] ?? null;
    }

    public function user()
    {
        return $this->belongsTo(User::class,'user_id');
    }
}
