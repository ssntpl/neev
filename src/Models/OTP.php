<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property int $owner_id
 * @property string $owner_type
 * @property string $otp
 * @property \Carbon\Carbon|null $expires_at
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class OTP extends Model
{
    protected $table = 'otp';
    protected $fillable = [
        'owner_id',
        'owner_type',
        'otp',
        'expires_at',
    ];

    protected $hidden = [
        'otp',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'otp' => 'hashed',
    ];

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
