<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property int $owner_id
 * @property string $owner_type
 * @property string $otp
 * @property int $attempts
 * @property \Carbon\Carbon|null $expires_at
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class OTP extends Model
{
    /**
     * Failed confirmations before the code is invalidated. A hard
     * invariant, not config: a 6-digit space demands an attempt cap.
     */
    public const MAX_ATTEMPTS = 5;

    protected $table = 'otp';
    protected $fillable = [
        'owner_id',
        'owner_type',
        'otp',
        'attempts',
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
