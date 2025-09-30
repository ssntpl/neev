<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Ssntpl\Neev\Traits\HasAccessToken;
use Ssntpl\Neev\Traits\HasMultiAuth;
use Ssntpl\Neev\Traits\HasRoles;
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
        'email',
        'password',
        'active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

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
