<?php

namespace Ssntpl\Neev\Tests\Traits;

trait WithNeevConfig
{
    protected function enableTeams(): void
    {
        config(['neev.team' => true]);
    }

    protected function enableTenantIsolation(?string $subdomainSuffix = 'test.com'): void
    {
        config([
            'neev.tenant' => true,
        ]);
    }

    protected function enableTenantAuth(): void
    {
        // Tenant SSO is always available now; no config toggle needed.
        // SSO is self-configured per entity in DB (TeamAuthSettings).
        // This method is kept for test compatibility.
    }

    protected function enableMFA(array $methods = ['authenticator', 'email']): void
    {
        config(['neev.multi_factor_auth' => $methods]);
    }

    protected function enableEmailVerification(): void
    {
        // Email verification is always enabled now; no config toggle needed.
        // This method is kept for test compatibility.
    }

    protected function enableDomainFederation(): void
    {
        // Domain federation is always available now; no config toggle needed.
        // This method is kept for test compatibility.
    }

    protected function enableUsernameSupport(): void
    {
        config(['neev.support_username' => true]);
    }
}
