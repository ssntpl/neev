<?php

namespace Ssntpl\Neev\Traits;

use Ssntpl\Neev\Models\AccessToken;
use Ssntpl\Neev\NewAccessToken;
use Ssntpl\Neev\Services\TenantResolver;
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
            'tenant_id' => $this->currentTenantId(),
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
            'tenant_id' => $this->currentTenantId(),
            'expires_at' => $expiry ? now()->addMinutes($expiry) : null,
        ]);

        return new NewAccessToken($token, $token->getKey().'|'.$plainTextToken);
    }

    /**
     * Get the current tenant context ID for token scoping.
     * Returns null when no tenant context or resolver is unavailable (e.g. in tests).
     */
    protected function currentTenantId(): ?int
    {
        try {
            if (!app()->bound(TenantResolver::class)) {
                return null;
            }

            return app(TenantResolver::class)->currentId();
        } catch (\Throwable) {
            return null;
        }
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
