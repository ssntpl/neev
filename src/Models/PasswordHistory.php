<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordHistory extends Model
{
    protected $fillable = [
        'user_id', 
        'password',
        'wrong_attempts',
    ];

    protected $casts = [
        'password' => 'hashed'
    ];
}
