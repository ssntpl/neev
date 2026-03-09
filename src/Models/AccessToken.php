<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $tenant_id
 * @property int|null $attempt_id
 * @property string $name
 * @property string $token
 * @property string $token_type
 * @property array<int, string>|null $permissions
 * @property \Carbon\Carbon|null $last_used_at
 * @property \Carbon\Carbon|null $expires_at
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class AccessToken extends Model
{
    public const api_token = 'api_token';
    public const mfa_token = 'mfa_token';
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

    public function tenant()
    {
        return $this->belongsTo(Tenant::getClass(), 'tenant_id');
    }

    public function attempt()
    {
        return $this->belongsTo(LoginAttempt::class, 'attempt_id');
    }

    public function can(string $permission): bool
    {
        $permissions = $this->permissions ?? [];

        return in_array('*', $permissions) || in_array($permission, $permissions);
    }
}
