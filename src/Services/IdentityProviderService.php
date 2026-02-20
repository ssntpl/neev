<?php

namespace Ssntpl\Neev\Services;

use Ssntpl\Neev\Contracts\IdentityProviderOwnerInterface;

/**
 * Entity-agnostic identity provider service.
 *
 * Wraps SSO/auth-method queries using IdentityProviderOwnerInterface,
 * so the same logic works for both Tenant and Team.
 */
class IdentityProviderService
{
    /**
     * Check if the owner requires SSO authentication.
     */
    public function requiresSSO(IdentityProviderOwnerInterface $owner): bool
    {
        return $owner->requiresSSO();
    }

    /**
     * Check if the owner has SSO fully configured.
     */
    public function hasSSOConfigured(IdentityProviderOwnerInterface $owner): bool
    {
        return $owner->hasSSOConfigured();
    }

    /**
     * Get the authentication method for the owner.
     */
    public function getAuthMethod(IdentityProviderOwnerInterface $owner): string
    {
        return $owner->getAuthMethod();
    }

    /**
     * Check if the owner allows auto-provisioning of users.
     */
    public function allowsAutoProvision(IdentityProviderOwnerInterface $owner): bool
    {
        return $owner->allowsAutoProvision();
    }
}
