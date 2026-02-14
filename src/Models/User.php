<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Ssntpl\LaravelAcl\Traits\HasRoles;
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

    protected $hidden = [
        'remember_token',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function getProfilePhotoUrlAttribute()
    {
        $photoUrl = $this->attributes['profile_photo_url'] ?? null;
        if ($photoUrl) {
            return $photoUrl;
        }
        return collect(explode(' ', $this->name))->map(fn($word) => strtoupper(substr($word, 0, 1)))->join('');
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
