<?php

namespace Ssntpl\Neev\Traits;

use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Cache;
use Ssntpl\Neev\Models\TeamAuthSettings;

/**
 * Trait HasTenantAuth
 *
 * Provides tenant-driven authentication capabilities to Team models.
 * Allows each tenant to configure their own authentication method (password or SSO).
 *
 * @property-read TeamAuthSettings|null $authSettings
 */
trait HasTenantAuth
{
    /**
     * Get the authentication settings for this team.
     */
    public function authSettings(): HasOne
    {
        return $this->hasOne(TeamAuthSettings::class);
    }

    /**
     * Get cached auth settings (30-minute TTL).
     */
    public function getCachedAuthSettings(): ?TeamAuthSettings
    {
        /** @var TeamAuthSettings|null */
        return Cache::remember("neev:auth_settings:{$this->getContextType()}:{$this->getContextId()}", 1800, function (): ?TeamAuthSettings {
            return $this->authSettings;
        });
    }

    /**
     * Get the authentication method for this team.
     * Returns the configured method or the default from config.
     */
    public function getAuthMethod(): string
    {
        return $this->getCachedAuthSettings()?->auth_method
            ?? 'password';
    }

    /**
     * Check if this team requires SSO authentication.
     */
    public function requiresSSO(): bool
    {
        return $this->getAuthMethod() === 'sso';
    }

    /**
     * Check if this team allows password authentication.
     */
    public function allowsPassword(): bool
    {
        return $this->getAuthMethod() === 'password';
    }

    /**
     * Check if SSO is properly configured for this team.
     */
    public function hasSSOConfigured(): bool
    {
        return $this->getCachedAuthSettings()?->hasSSOConfigured() ?? false;
    }

    /**
     * Get the SSO provider for this team.
     */
    public function getSSOProvider(): ?string
    {
        return $this->getCachedAuthSettings()?->sso_provider;
    }

    /**
     * Check if this team allows auto-provisioning of users via SSO.
     */
    public function allowsAutoProvision(): bool
    {
        return $this->getCachedAuthSettings()?->auto_provision
            ?? false;
    }

    /**
     * Get the role to assign to auto-provisioned users.
     */
    public function getAutoProvisionRole(): ?string
    {
        return $this->getCachedAuthSettings()?->auto_provision_role
            ?? null;
    }

    /**
     * Get the Socialite configuration for this team's SSO provider.
     *
     * @return array<string, mixed>|null
     */
    public function getSocialiteConfig(): ?array
    {
        return $this->getCachedAuthSettings()?->getSocialiteConfig();
    }
}
