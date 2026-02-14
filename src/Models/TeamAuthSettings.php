<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamAuthSettings extends Model
{
    protected $fillable = [
        'team_id',
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

    /**
     * Get the team that owns these auth settings.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::getClass());
    }

    /**
     * Check if this tenant uses SSO authentication.
     */
    public function isSSO(): bool
    {
        return $this->auth_method === 'sso';
    }

    /**
     * Check if this tenant uses password authentication.
     */
    public function isPassword(): bool
    {
        return $this->auth_method === 'password';
    }

    /**
     * Check if the SSO configuration is complete and valid.
     */
    public function hasSSOConfigured(): bool
    {
        return $this->isSSO()
            && !empty($this->sso_provider)
            && !empty($this->sso_client_id)
            && !empty($this->sso_client_secret);
    }

    /**
     * Get the Socialite configuration array for this tenant's SSO provider.
     * Used to dynamically configure the Socialite driver.
     *
     * @return array<string, mixed>
     */
    public function getSocialiteConfig(): array
    {
        $config = [
            'client_id' => $this->sso_client_id,
            'client_secret' => $this->sso_client_secret,
            'redirect' => route('sso.callback'),
        ];

        // Add provider-specific configuration
        if ($this->sso_provider === 'entra' && $this->sso_tenant_id) {
            $config['tenant'] = $this->sso_tenant_id;
        }

        // Merge any extra configuration
        if ($this->sso_extra_config) {
            $config = array_merge($config, $this->sso_extra_config);
        }

        return $config;
    }

    /**
     * Check if auto-provisioning is enabled for this tenant.
     */
    public function allowsAutoProvision(): bool
    {
        return $this->auto_provision;
    }

    /**
     * Get the role to assign to auto-provisioned users.
     */
    public function getAutoProvisionRole(): ?string
    {
        return $this->auto_provision_role;
    }
}
