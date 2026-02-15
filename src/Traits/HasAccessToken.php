<?php

namespace Ssntpl\Neev\Traits;

use Ssntpl\Neev\Models\AccessToken;
use Ssntpl\Neev\NewAccessToken;
use Ssntpl\LaravelAcl\Models\Permission;
use Illuminate\Support\Str;

trait HasAccessToken
{
    public function createApiToken(?string $name = null, ?array $permissions = null, ?int $expiry = null)
    {
        if ($permissions !== null && count($permissions) === Permission::count()) {
            $permissions = ['*'];
        }
        $plainTextToken = Str::random(40);
        $token = $this->accessTokens()->create([
            'name' => $name ?? 'api token',
            'token' => $plainTextToken,
            'permissions' => $permissions ?? [],
            'token_type' => AccessToken::api_token,
            'expires_at' => $expiry ? now()->addMinutes($expiry) : null,
        ]);

        return new NewAccessToken($token, $token->getKey().'|'.$plainTextToken);
    }

    public function createLoginToken(?int $expiry)
    {
        $plainTextToken = Str::random(40);
        $token = $this->accessTokens()->create([
            'name' => AccessToken::login,
            'token' => $plainTextToken,
            'token_type' => AccessToken::login,
            'expires_at' => $expiry ? now()->addMinutes($expiry) : null,
        ]);

        return new NewAccessToken($token, $token->getKey().'|'.$plainTextToken);
    }

    public function accessTokens()
    {
        return $this->hasMany(AccessToken::class);
    }

    public function apiTokens()
    {
        return $this->hasMany(AccessToken::class)->where('token_type', AccessToken::api_token);
    }

    public function loginTokens()
    {
        return $this->hasMany(AccessToken::class)->where('token_type', AccessToken::login);
    }
}
