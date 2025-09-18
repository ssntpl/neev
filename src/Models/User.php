<?php

namespace Ssntpl\Neev\Models;

use App\Models\User as AppUser;
use Ssntpl\Neev\Traits\HasAccessToken;
use Ssntpl\Neev\Traits\HasMultiAuth;
use Ssntpl\Neev\Traits\HasTeams;
use Ssntpl\Neev\Traits\VerifyEmail;

class User extends AppUser
{
    use HasTeams;
    use HasMultiAuth;
    use HasAccessToken;
    use VerifyEmail;
    
    public function getProfilePhotoUrlAttribute()
    {
        if ($this->profile_photo_url ?? false) {
            return $this->profile_photo_url;
        }
        return collect(explode(' ', $this->name))->map(fn($word) => strtoupper(substr($word, 0, 1)))->join('');;
    }

    protected static function booted()
    {
        static::created(function ($user) {
            PasswordHistory::create([
                'user_id' => $user->id ?? null,
                'password' => $user->password,
            ]);
        });

        static::updated(function ($user) {
            if ($user->isDirty('password')) {
                PasswordHistory::create([
                'user_id' => $user->id,
                'password' => $user->password,
            ]);
            }
        });
    }

    public function loginHistory()
    {
        return $this->hasMany(LoginHistory::class);
    }

    public function emails()
    {
        return $this->hasMany(Email::class);
    }

    public function primaryEmail()
    {
        return $this->hasOne(Email::class)->where('email', $this->email);
    }

    public function OTP($method = null)
    {
        if ($method) {
            return $this->hasMany(OTP::class)->where('method', $method)->first();
        } else {
            return $this->hasMany(OTP::class);
        }
    }

    public function passkeys()
    {
        return $this->hasMany(Passkey::class);
    }

    public function activate()
    {
        $this->active = true;
        return $this->save();
    }

    public function deactivate()
    {
        $this->active = false;
        return $this->save();
    }
    
    public static function google()
    {
        return 'google';
    }
    
    public static function github()
    {
        return 'github';
    }
    
    public static function microsoft()
    {
        return 'microsoft';
    }
    
    public static function apple()
    {
        return 'apple';
    }
}
