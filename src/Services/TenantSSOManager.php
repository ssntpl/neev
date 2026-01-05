<?php

namespace Ssntpl\Neev\Services;

use Exception;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Ssntpl\Neev\Models\Email;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\User;

/**
 * Service for managing tenant-specific SSO authentication.
 *
 * Handles dynamic Socialite configuration based on tenant settings,
 * user provisioning, and membership management.
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
     * Get the SSO provider name for a tenant.
     */
    public function getProvider(Team $tenant): ?string
    {
        if (!$this->isTenantAuthEnabled()) {
            return null;
        }

        return $tenant->authSettings?->sso_provider;
    }

    /**
     * Build a Socialite driver configured for the tenant's SSO provider.
     *
     * @throws Exception If SSO is not configured or provider is unsupported
     */
    public function buildSocialiteDriver(Team $tenant): Provider
    {
        $authSettings = $tenant->authSettings;

        if (!$authSettings || !$authSettings->hasSSOConfigured()) {
            throw new Exception('SSO is not configured for this tenant.');
        }

        $provider = $authSettings->sso_provider;
        $driverName = $this->driverMap[$provider] ?? $provider;

        // Check if the provider is supported
        $supportedProviders = config('neev.tenant_auth_options.sso_providers', []);
        if (!in_array($provider, $supportedProviders)) {
            throw new Exception("SSO provider '{$provider}' is not supported.");
        }

        // Get the tenant's Socialite configuration
        $config = $authSettings->getSocialiteConfig();

        // Build the Socialite driver with tenant-specific config
        return Socialite::buildProvider(
            $this->getProviderClass($driverName),
            $config
        );
    }

    /**
     * Get the redirect URL for the tenant's SSO provider.
     *
     * @throws Exception If SSO is not configured
     */
    public function getRedirectUrl(Team $tenant): string
    {
        return $this->buildSocialiteDriver($tenant)->redirect()->getTargetUrl();
    }

    /**
     * Handle the SSO callback and return the authenticated Socialite user.
     *
     * @throws Exception If SSO is not configured
     */
    public function handleCallback(Team $tenant): SocialiteUser
    {
        return $this->buildSocialiteDriver($tenant)->user();
    }

    /**
     * Find or create a user based on SSO authentication.
     *
     * Looks up the user by email (global identity). If auto-provisioning
     * is enabled and the user doesn't exist, creates a new user.
     *
     * @throws Exception If user doesn't exist and auto-provisioning is disabled
     */
    public function findOrCreateUser(Team $tenant, SocialiteUser $ssoUser): User
    {
        $email = $ssoUser->getEmail();

        if (empty($email)) {
            throw new Exception('SSO provider did not return an email address.');
        }

        // Find existing user by email (global lookup)
        $emailRecord = Email::where('email', $email)->first();

        if ($emailRecord) {
            return $emailRecord->user;
        }

        // User doesn't exist - check if auto-provisioning is enabled
        if (!$tenant->allowsAutoProvision()) {
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
    public function ensureMembership(User $user, Team $tenant): void
    {
        // Check if user already has membership
        if ($user->belongsToTeam($tenant)) {
            return;
        }

        // User is not a member - check auto-provisioning
        if (!$tenant->allowsAutoProvision()) {
            throw new Exception('You are not a member of this organization. Please contact your administrator.');
        }

        // Add user to the tenant with the configured role
        $role = $tenant->getAutoProvisionRole() ?? '';

        $tenant->users()->attach($user, [
            'joined' => true,
            'role' => $role,
        ]);

        // Assign the role if specified
        if ($role) {
            $user->assignRole($role, $tenant);
        }
    }

    /**
     * Check if tenant authentication feature is enabled.
     */
    public function isTenantAuthEnabled(): bool
    {
        return config('neev.tenant_auth', false)
            && config('neev.tenant_isolation', false);
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
