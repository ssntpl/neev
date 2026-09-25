<?php

namespace Ssntpl\Neev\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Ssntpl\Neev\Enums\OtpPurpose;

/**
 * @property int $id
 * @property int $owner_id
 * @property string $owner_type
 * @property OtpPurpose $purpose
 * @property string $otp
 * @property int $attempts
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
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
        'purpose',
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
        'purpose' => OtpPurpose::class,
    ];

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Every code an owner holds, whatever it was issued for.
     *
     * @param Builder<OTP> $query
     * @return Builder<OTP>
     */
    public function scopeForOwner(Builder $query, Model $owner): Builder
    {
        return $query
            ->where('owner_id', $owner->getKey())
            ->where('owner_type', $owner->getMorphClass());
    }

    /**
     * The code an owner holds for one purpose.
     *
     * @param Builder<OTP> $query
     * @return Builder<OTP>
     */
    public function scopeForPurpose(Builder $query, Model $owner, OtpPurpose $purpose): Builder
    {
        return $this->scopeForOwner($query, $owner)->where('purpose', $purpose->value);
    }
}
