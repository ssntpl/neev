<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Model;

class Email extends Model
{
    protected $fillable = [
        'user_id',
        'email',
        'verified_at'
    ];

    protected $casts = [
        'verified_at' => 'datetime'
    ];

    public function user()
    {
        return $this->belongsTo(User::getClass(), 'user_id');
    }
}
