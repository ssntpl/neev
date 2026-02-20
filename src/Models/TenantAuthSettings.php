<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantAuthSettings extends Model
{
    protected $fillable = [
        'tenant_id',
        'auth_method',
        'sso_provider',
        'sso_client_id',
        'sso_client_secret',
        'sso_tenant_id',
        'sso_extra_config',
        'auto_provision',
        'auto_provision_role',
    ];

    protected $casts = [
        'sso_client_secret' => 'encrypted',
        'sso_extra_config' => 'array',
        'auto_provision' => 'boolean',
    ];

    protected $hidden = [
        'sso_client_id',
        'sso_client_secret',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::getClass());
    }

    public function isSSO(): bool
    {
        return $this->auth_method === 'sso';
    }

    public function isPassword(): bool
    {
        return $this->auth_method === 'password';
    }

    public function hasSSOConfigured(): bool
    {
        return $this->isSSO()
            && !empty($this->sso_provider)
            && !empty($this->sso_client_id)
            && !empty($this->sso_client_secret);
    }

    /**
     * @return array<string, mixed>
     */
    public function getSocialiteConfig(): array
    {
        $config = [
            'client_id' => $this->sso_client_id,
            'client_secret' => $this->sso_client_secret,
            'redirect' => route('sso.callback'),
        ];

        if ($this->sso_provider === 'entra' && $this->sso_tenant_id) {
            $config['tenant'] = $this->sso_tenant_id;
        }

        if ($this->sso_extra_config) {
            $config = array_merge($config, $this->sso_extra_config);
        }

        return $config;
    }

    public function allowsAutoProvision(): bool
    {
        return $this->auto_provision;
    }

    public function getAutoProvisionRole(): ?string
    {
        return $this->auto_provision_role;
    }
}
