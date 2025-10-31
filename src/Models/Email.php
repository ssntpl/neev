<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Email extends Model
{
    protected $fillable = [
        'user_id',
        'email',
        'is_primary',
        'verified_at',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
        'is_primary' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::getClass(), 'user_id');
    }

    public function otp(): MorphOne
    {
        return $this->morphOne(OTP::class, 'owner');
    }
}
