<?php

namespace Ssntpl\Neev\Tests\Traits;

trait WithNeevConfig
{
    protected function enableTeams(): void
    {
        config(['neev.team' => true]);
    }

    protected function enableEmailVerification(): void
    {
        config(['neev.email_verified' => true]);
    }

    protected function enableTenantIsolation(?string $subdomainSuffix = 'test.com'): void
    {
        config([
            'neev.tenant_isolation' => true,
            'neev.tenant_isolation_options.subdomain_suffix' => $subdomainSuffix,
        ]);
    }

    protected function enableTenantAuth(): void
    {
        config([
            'neev.tenant_auth' => true,
            'neev.tenant_isolation' => true,
        ]);
    }

    protected function enableMFA(array $methods = ['authenticator', 'email']): void
    {
        config(['neev.multi_factor_auth' => $methods]);
    }

    protected function enableDomainFederation(): void
    {
        config(['neev.domain_federation' => true]);
    }

    protected function enableCompanyEmailRequirement(): void
    {
        config(['neev.require_company_email' => true]);
    }

    protected function enableMagicAuth(): void
    {
        config(['neev.magicauth' => true]);
    }

    protected function disableMagicAuth(): void
    {
        config(['neev.magicauth' => false]);
    }

    protected function enableUsernameSupport(): void
    {
        config(['neev.support_username' => true]);
    }
}
