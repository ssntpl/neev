<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Ssntpl\LaravelAcl\Traits\HasRoles;
use Ssntpl\Neev\Database\Factories\UserFactory;
use Ssntpl\Neev\Traits\BelongsToTenant;
use Ssntpl\Neev\Traits\HasTeams;
use Ssntpl\Neev\Traits\NeevAuthenticatable;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * @property int $id
 * @property int|null $tenant_id
 * @property string $name
 * @property string|null $username
 * @property bool $active
 * @property int|null $current_team_id
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property-read Email|null $email
 * @property-read Password|null $password
 * @property-read MultiFactorAuth|null $preferredMultiFactorAuth
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Email> $emails
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Password> $passwords
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Passkey> $passkeys
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MultiFactorAuth> $multiFactorAuths
 * @property-read \Illuminate\Database\Eloquent\Collection<int, RecoveryCode> $recoveryCodes
 * @property-read \Illuminate\Database\Eloquent\Collection<int, LoginAttempt> $loginAttempts
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AccessToken> $accessTokens
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Team> $teams
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Team> $allTeams
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Team> $ownedTeams
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Team> $teamRequests
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Team> $sendRequests
 * @property-read Team|null $currentTeam
 */
class User extends Authenticatable
{
    use HasFactory;
    use Notifiable;
    use HasTeams;
    use HasRoles;
    use NeevAuthenticatable;
    use BelongsToTenant;

    protected static function newFactory()
    {
        return UserFactory::new();
    }

    /** @return static */
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
        'tenant_id',
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
