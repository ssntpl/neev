<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Model;

class AccessToken extends Model
{

    public const api_token = 'api_token';
    public const login = 'login';

    protected $fillable = [
        'user_id',
        'name',
        'token',
        'token_type',
        'permissions',
        'last_used_at',
        'expires_at',
    ];

    protected $hidden = [
        'token'
    ];

    protected $casts = [
        'permissions' => 'array',
        'token' => 'hashed',
        'last_used' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::getClass(),'user_id');
    }

    public function can(string $permission): bool
    {
        return empty($permission) || in_array('*', $this->permissions ?? []) || in_array($permission, $this->permissions ?? []);
    }
}
