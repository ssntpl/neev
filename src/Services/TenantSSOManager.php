<?php

namespace Ssntpl\Neev\Services;

use Exception;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Ssntpl\Neev\Contracts\HasMembersInterface;
use Ssntpl\Neev\Contracts\IdentityProviderOwnerInterface;
use Ssntpl\Neev\Models\Email;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\User;

/**
 * Service for managing tenant-specific SSO authentication.
 *
 * Handles dynamic Socialite configuration based on tenant settings,
 * user provisioning, and membership management.
 *
 * SSO-related methods accept IdentityProviderOwnerInterface so they work
 * for both Team (shared mode) and Tenant (isolated mode).
 * Membership uses HasMembersInterface so it works for both.
 */
class TenantSSOManager
{
    /**
     * Provider driver mappings.
     * Maps our provider names to Socialite driver names.
     */
    protected array $driverMap = [
        'entra' => 'azure',      // Microsoft Entra ID uses azure driver
        'google' => 'google',
        'okta' => 'okta',
    ];

    /**
     * Get the SSO provider name for an identity provider owner.
     */
    public function getProvider(IdentityProviderOwnerInterface $owner): ?string
    {
        return $owner->getSSOProvider();
    }

    /**
     * Build a Socialite driver configured for the owner's SSO provider.
     *
     * @throws Exception If SSO is not configured or provider is unsupported
     */
    public function buildSocialiteDriver(IdentityProviderOwnerInterface $owner): Provider
    {
        if (!$owner->hasSSOConfigured()) {
            throw new Exception('SSO is not configured for this tenant.');
        }

        $provider = $owner->getSSOProvider();
        $driverName = $this->driverMap[$provider] ?? $provider;

        // Get the owner's Socialite configuration
        $config = $owner->getSocialiteConfig();

        // Build the Socialite driver with tenant-specific config
        return Socialite::buildProvider(
            $this->getProviderClass($driverName),
            $config
        );
    }

    /**
     * Get the redirect URL for the owner's SSO provider.
     *
     * @throws Exception If SSO is not configured
     */
    public function getRedirectUrl(IdentityProviderOwnerInterface $owner): string
    {
        return $this->buildSocialiteDriver($owner)->redirect()->getTargetUrl();
    }

    /**
     * Handle the SSO callback and return the authenticated Socialite user.
     *
     * @throws Exception If SSO is not configured
     */
    public function handleCallback(IdentityProviderOwnerInterface $owner): SocialiteUser
    {
        return $this->buildSocialiteDriver($owner)->user();
    }

    /**
     * Find or create a user based on SSO authentication.
     *
     * Looks up the user by email (global identity). If auto-provisioning
     * is enabled and the user doesn't exist, creates a new user.
     *
     * @throws Exception If user doesn't exist and auto-provisioning is disabled
     */
    public function findOrCreateUser(IdentityProviderOwnerInterface $owner, SocialiteUser $ssoUser): User
    {
        $email = $ssoUser->getEmail();

        if (empty($email)) {
            throw new Exception('SSO provider did not return an email address.');
        }

        // Find existing user by email (automatically tenant-scoped via TenantScope)
        $emailRecord = Email::findByEmail($email);

        if ($emailRecord) {
            return $emailRecord->user;
        }

        // User doesn't exist - check if auto-provisioning is enabled
        if (!$owner->allowsAutoProvision()) {
            throw new Exception('You are not a member of this organization. Please contact your administrator.');
        }

        // Create a new user (global identity)
        $user = User::model()->create([
            'name' => $ssoUser->getName() ?? $this->extractNameFromEmail($email),
        ]);

        // Create the email record
        $user->emails()->create([
            'email' => $email,
            'is_primary' => true,
            'verified_at' => now(), // SSO emails are pre-verified
        ]);

        // Create a random password (user won't need it for SSO login)
        $user->passwords()->create([
            'password' => Hash::make(bin2hex(random_bytes(32))),
        ]);

        return $user;
    }

    /**
     * Ensure the user has membership in the tenant.
     *
     * If auto-provisioning is enabled and the user isn't a member,
     * creates the membership. Otherwise, throws an exception.
     *
     * @throws Exception If user is not a member and auto-provisioning is disabled
     */
    public function ensureMembership(User $user, HasMembersInterface&IdentityProviderOwnerInterface $owner): void
    {
        if ($owner->hasMember($user)) {
            return;
        }

        if (!$owner->allowsAutoProvision()) {
            throw new Exception('You are not a member of this organization. Please contact your administrator.');
        }

        $role = $owner->getAutoProvisionRole();

        // Team membership uses pivot table, Tenant membership uses tenant_id on user
        if ($owner instanceof Team) {
            $owner->addMember($user, $role);
        } else {
            // Tenant: tenant_id should already be set by BelongsToTenant trait.
            // Just assign the role scoped to the tenant.
            if ($role) {
                $user->assignRole($role, $owner);
            }
        }
    }

    /**
     * Get the provider class for a given driver name.
     */
    protected function getProviderClass(string $driverName): string
    {
        // Map driver names to their provider classes
        $providerClasses = [
            'azure' => \SocialiteProviders\Azure\Provider::class,
            'google' => \Laravel\Socialite\Two\GoogleProvider::class,
            'okta' => \SocialiteProviders\Okta\Provider::class,
        ];

        return $providerClasses[$driverName]
            ?? throw new Exception("Provider class not found for driver: {$driverName}");
    }

    /**
     * Extract a name from an email address.
     */
    protected function extractNameFromEmail(string $email): string
    {
        $localPart = explode('@', $email)[0];

        // Replace dots and underscores with spaces, capitalize words
        return ucwords(str_replace(['.', '_', '-'], ' ', $localPart));
    }
}
