<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Ssntpl\Permissions\Traits\HasRoles;
use Ssntpl\Neev\Traits\HasAccessToken;
use Ssntpl\Neev\Traits\HasMultiAuth;
use Ssntpl\Neev\Traits\HasTeams;
use Ssntpl\Neev\Traits\VerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;
    use HasTeams;
    use HasRoles;
    use HasMultiAuth;
    use HasAccessToken;
    use VerifyEmail;
    
    public static function model() {
        $class = config('neev.user_model', User::class);
        return new $class;
    }
    
    public static function getClass() {
        return config('neev.user_model', User::class);
    }

    protected $fillable = [
        'name',
        'username',
        'active',
    ];

    public function getProfilePhotoUrlAttribute()
    {
        if ($this->profile_photo_url ?? false) {
            return $this->profile_photo_url;
        }
        return collect(explode(' ', $this->name))->map(fn($word) => strtoupper(substr($word, 0, 1)))->join('');;
    }

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
        return $this->hasOne(Password::class)->orderByDesc('created_at')->limit(1);
    }

    public function loginAttempts()
    {
        return $this->hasMany(LoginAttempt::class);
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

    public function devices()
    {
        return $this->hasMany(UserDevice::class);
    }

    public function routeNotificationForFcm()
    {
        return $this->devices()->pluck('device_token')->toArray();
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
}
