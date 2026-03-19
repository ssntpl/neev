<?php

namespace Ssntpl\Neev\Traits;

use Ssntpl\Neev\Models\LoginAttempt;
use Ssntpl\Neev\Models\Passkey;

trait NeevAuthenticatable
{
    use HasMultiAuth;
    use HasAccessToken;

    public function loginAttempts()
    {
        return $this->hasMany(LoginAttempt::class);
    }

    public function passkeys()
    {
        return $this->hasMany(Passkey::class);
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function markEmailAsVerified(): bool
    {
        $this->email_verified_at = now();
        return $this->save();
    }

    /**
     * Get when the current password expires.
     */
    public function passwordExpiresAt(): ?\Carbon\Carbon
    {
        $days = config('neev.password_expiry_days', 90);
        if ($days <= 0) {
            return null;
        }

        if (!$this->password_changed_at) {
            return null;
        }

        return $this->password_changed_at->addDays($days);
    }

    /**
     * Check if the current password has expired.
     */
    public function isPasswordExpired(): bool
    {
        $expiresAt = $this->passwordExpiresAt();

        return $expiresAt !== null && $expiresAt->isPast();
    }

    /**
     * Check if the password is expiring within the given number of days.
     */
    public function isPasswordExpiringSoon(int $withinDays = 7): bool
    {
        $expiresAt = $this->passwordExpiresAt();

        return $expiresAt !== null && !$expiresAt->isPast() && $expiresAt->diffInDays(now()) <= $withinDays;
    }
}
