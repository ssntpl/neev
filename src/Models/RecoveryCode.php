<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Model;
use Ssntpl\Neev\Models\User;

class RecoveryCode extends Model
{   
    protected $fillable = [
        'user_id',
        'code'
    ];

    protected $hidden = [
        'code',
    ];

    protected $casts = [
        'code' => 'encrypted',
    ];

    public function user()
    {
        return $this->belongsTo(User::class,'user_id');
    }
}
