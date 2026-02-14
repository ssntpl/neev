<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Model;

class AccessToken extends Model
{

    public const api_token = 'api_token';
    public const mfa_token = 'mfa_token';
    public const login = 'login';

    protected $fillable = [
        'user_id',
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

    public function can(string $permission): bool
    {
        $permissions = $this->permissions ?? [];

        return in_array('*', $permissions) || in_array($permission, $permissions);
    }
}
