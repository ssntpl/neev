<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class OTP extends Model
{
    protected $table = 'otp';
    protected $fillable = [
        'owner_id',
        'owner_type',
        'otp',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
