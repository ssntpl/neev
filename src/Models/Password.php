<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Model;

class Password extends Model
{
    protected $fillable = [
        'user_id', 
        'password',
    ];

    protected $casts = [
        'password' => 'hashed'
    ];

    const UPDATED_AT = null;
}
