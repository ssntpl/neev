<?php

namespace Ssntpl\Neev\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Ssntpl\Neev\Events\MfaMethodAdded;

/**
 * @property int $id
 * @property int $user_id
 * @property string $method
 * @property string $status
 * @property string|null $secret
 * @property string|null $otp
 * @property int $attempts
 * @property bool $preferred
 * @property Carbon|null $expires_at
 * @property Carbon|null $last_used
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 */
class MultiFactorAuth extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';

    /**
     * Guesses allowed against one emailed code before it is spent.
     *
     * Matches OTP::MAX_ATTEMPTS — a six-digit code guards no less here than it
     * does in email verification, so it should not be cheaper to attack.
     */
    public const MAX_ATTEMPTS = 5;

    protected $fillable = [
        'user_id',
        'method',
        'status',
        'secret',
        'otp',
        'attempts',
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

    /**
     * Put a freshly generated code on this factor.
     *
     * One method so that issuing a code always resets the guess counter —
     * guesses spent against the previous code must not narrow this one's
     * budget, and forgetting that reset in one of the call sites is exactly
     * how a cap ends up doing nothing.
     */
    public function issueOtp(string|int $otp, int $expiryMinutes): void
    {
        $this->otp = (string) $otp;
        $this->attempts = 0;
        $this->expires_at = now()->addMinutes($expiryMinutes);
        $this->save();
    }

    /**
     * Spend this code, whether it was guessed right or run out of guesses.
     */
    public function clearOtp(): void
    {
        $this->otp = null;
        $this->attempts = 0;
        $this->expires_at = null;
        $this->save();
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
