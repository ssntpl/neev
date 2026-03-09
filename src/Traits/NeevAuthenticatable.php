<?php

namespace Ssntpl\Neev\Traits;

use Ssntpl\Neev\Models\Email;
use Ssntpl\Neev\Models\LoginAttempt;
use Ssntpl\Neev\Models\Passkey;
use Ssntpl\Neev\Models\Password;

trait NeevAuthenticatable
{
    use HasMultiAuth;
    use HasAccessToken;
    use VerifyEmail;

    public function emails()
    {
        return $this->hasMany(Email::class);
    }

    public function email()
    {
        return $this->hasOne(Email::class)->where('is_primary', true);
    }

    public function passwords()
    {
        return $this->hasMany(Password::class);
    }

    public function password()
    {
        return $this->hasOne(Password::class)->latestOfMany();
    }

    public function loginAttempts()
    {
        return $this->hasMany(LoginAttempt::class);
    }

    public function passkeys()
    {
        return $this->hasMany(Passkey::class);
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

        $password = $this->password;
        if (!$password) {
            return null;
        }

        return $password->created_at->addDays($days);
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
