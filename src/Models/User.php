<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Ssntpl\LaravelAcl\Traits\HasRoles;
use Ssntpl\Neev\Database\Factories\UserFactory;
use Ssntpl\Neev\Traits\BelongsToTenant;
use Ssntpl\Neev\Traits\HasAccessToken;
use Ssntpl\Neev\Traits\HasMultiAuth;
use Ssntpl\Neev\Traits\HasTeams;
use Ssntpl\Neev\Traits\VerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * @property int|null $tenant_id
 * @property string|null $username
 * @property-read Email|null $email
 */
class User extends Authenticatable
{
    use HasFactory;
    use Notifiable;
    use HasTeams;
    use HasRoles;
    use HasMultiAuth;
    use HasAccessToken;
    use VerifyEmail;
    use BelongsToTenant;

    protected static function newFactory()
    {
        return UserFactory::new();
    }

    public static function model()
    {
        $class = config('neev.user_model', User::class);
        return new $class();
    }

    public static function getClass()
    {
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
        return collect(explode(' ', $this->name))->map(fn ($word) => strtoupper(substr($word, 0, 1)))->join('');
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

    /**
     * Find a user by username, respecting tenant isolation.
     * The TenantScope global scope handles tenant filtering automatically.
     */
    public static function findByUsername(string $username): ?static
    {
        return static::model()
            ->where('username', $username)
            ->first();
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
