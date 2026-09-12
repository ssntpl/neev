<?php

namespace Ssntpl\Neev\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Ssntpl\Neev\Traits\BelongsToTenant;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $tenant_id
 * @property int|null $attempt_id
 * @property string $name
 * @property string $token
 * @property string $token_type
 * @property array<int, string>|null $permissions
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AccessToken extends Model
{
    use BelongsToTenant;

    public const api_token = 'api_token';
    public const login = 'login';

    protected $fillable = [
        'user_id',
        'tenant_id',
        'attempt_id',
        'name',
        'token',
        'token_type',
        'permissions',
        'last_used_at',
        'expires_at',
    ];

    protected $hidden = [
        'token',
    ];

    protected $casts = [
        'permissions' => 'array',
        'token' => 'hashed',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::getClass(), 'user_id');
    }

    public function attempt()
    {
        return $this->belongsTo(LoginAttempt::class, 'attempt_id');
    }

    /**
     * Whether this token carries an ability.
     *
     * A login token is the API's equivalent of a session — it is minted by
     * authenticating with full credentials, so it carries the user's whole
     * authority rather than a scope. Only an API token, which somebody created
     * deliberately and chose abilities for, is scoped. Without this distinction
     * an ability check would refuse every ordinary signed-in caller, since
     * `createLoginToken()` sets no permissions at all.
     */
    public function can(string $permission): bool
    {
        if ($this->token_type === self::login) {
            return true;
        }

        $permissions = $this->permissions ?? [];

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }
}
