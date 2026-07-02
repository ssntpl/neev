<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Model;
use Ssntpl\Neev\Events\MfaMethodAdded;

/**
 * @property int $id
 * @property int $user_id
 * @property string $method
 * @property string $status
 * @property string|null $secret
 * @property string|null $otp
 * @property bool $preferred
 * @property \Carbon\Carbon|null $expires_at
 * @property \Carbon\Carbon|null $last_used
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property-read User|null $user
 */
class MultiFactorAuth extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';

    protected $fillable = [
        'user_id',
        'method',
        'status',
        'secret',
        'otp',
        'expires_at',
        'last_used',
        'preferred',
    ];

    protected $hidden = [
        'secret',
        'otp',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_used' => 'datetime',
        'secret' => 'encrypted',
        'otp' => 'hashed',
        'preferred' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::getClass(), 'user_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Activate a pending method without OTP verification.
     *
     * This is the sanctioned escape hatch for programmatic activation
     * (admin provisioning, imports from another system, tests). It skips
     * the proof-of-setup check — that is the caller's responsibility —
     * but keeps the activation invariants: the preferred flag is assigned
     * when no other active method holds it, and MfaMethodAdded fires.
     * End-user flows should go through User::verifyMfaSetup() instead.
     *
     * @return bool False if the method is already active.
     */
    public function activate(): bool
    {
        if ($this->isActive()) {
            return false;
        }

        $user = $this->user;

        $this->status = self::STATUS_ACTIVE;
        $this->preferred = !$user->preferredMultiFactorAuth()->exists();
        $saved = $this->save();

        if ($saved) {
            $user->load('multiFactorAuths');
            event(new MfaMethodAdded($user, $this->method));
        }

        return $saved;
    }
}
