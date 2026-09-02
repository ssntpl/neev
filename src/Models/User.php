<?php

namespace Ssntpl\Neev\Models;

use Carbon\Carbon;
use Illuminate\Contracts\Validation\Rule as ValidationRule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Validation\Rule;
use Ssntpl\LaravelAcl\Traits\HasRoles;
use Ssntpl\Neev\Database\Factories\UserFactory;
use Ssntpl\Neev\Services\TenantResolver;
use Ssntpl\Neev\Traits\BelongsToTenant;
use Ssntpl\Neev\Traits\HasTeams;
use Ssntpl\Neev\Traits\NeevAuthenticatable;

/**
 * @property int $id
 * @property int|null $tenant_id
 * @property string $name
 * @property string|null $username
 * @property string|null $email
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property array|null $password_history
 * @property Carbon|null $password_changed_at
 * @property bool $active
 * @property int|null $default_team_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MultiFactorAuth|null $preferredMultiFactorAuth
 * @property-read Collection<int, Passkey> $passkeys
 * @property-read Collection<int, MultiFactorAuth> $multiFactorAuths
 * @property-read Collection<int, MultiFactorAuth> $activeMultiFactorAuths
 * @property-read Collection<int, RecoveryCode> $recoveryCodes
 * @property-read Collection<int, LoginAttempt> $loginAttempts
 * @property-read Collection<int, AccessToken> $accessTokens
 * @property-read Collection<int, Team> $teams
 * @property-read Collection<int, Team> $allTeams
 * @property-read Collection<int, Team> $ownedTeams
 * @property-read Collection<int, Team> $teamRequests
 * @property-read Collection<int, Team> $sendRequests
 * @property-read Team|null $defaultTeam
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
        'email',
        'active',
    ];

    protected $hidden = [
        'password',
        'password_history',
    ];

    /**
     * Declared as a method, not a $casts property: Eloquent merges casts()
     * into $casts, so a project subclass that declares its own $casts keeps
     * neev's instead of silently replacing them.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'password_history' => 'array',
            'password_changed_at' => 'datetime',
        ];
    }

    public function getProfilePhotoUrlAttribute()
    {
        $photoUrl = $this->attributes['profile_photo_url'] ?? null;
        if ($photoUrl) {
            return $photoUrl;
        }
        return collect(explode(' ', $this->name))->map(fn ($word) => strtoupper(substr($word, 0, 1)))->join('');
    }

    /**
     * Find a user by email, respecting tenant isolation.
     * The TenantScope global scope handles tenant filtering automatically.
     *
     * @return static|null
     */
    public static function findByEmail(string $email): ?static
    {
        return static::model()
            ->where('email', $email)
            ->first();
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

    /**
     * Get a unique validation rule for email that respects tenant isolation.
     * Laravel's unique rule bypasses Eloquent global scopes,
     * so we must add the tenant_id constraint explicitly.
     *
     * @param int|null $ignoreId  Row ID to ignore (for updates)
     */
    public static function uniqueEmailRule(?int $ignoreId = null): ValidationRule|string
    {
        $rule = Rule::unique('users', 'email');

        if ($ignoreId) {
            $rule->ignore($ignoreId);
        }

        if (app()->bound(TenantResolver::class)) {
            $resolver = app(TenantResolver::class);
            if ($resolver->hasTenant()) {
                $rule->where('tenant_id', $resolver->currentId());
            } else {
                $rule->whereNull('tenant_id');
            }
        }

        return $rule;
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
