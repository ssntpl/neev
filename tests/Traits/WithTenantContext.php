<?php

namespace Ssntpl\Neev\Tests\Traits;

use Ssntpl\Neev\Models\Tenant;
use Ssntpl\Neev\Services\ContextManager;
use Ssntpl\Neev\Services\TenantResolver;

/**
 * Sets up a default tenant context so that models with BelongsToTenant
 * (User, Email, etc.) can be created and queried without hitting the
 * strict TenantScope "WHERE 1=0" guard.
 *
 * Use this in any test that creates Users or Emails but is NOT specifically
 * testing tenant isolation behaviour.
 */
trait WithTenantContext
{
    protected ?Tenant $testTenant = null;

    protected function setUpTenantContext(): void
    {
        $this->testTenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-' . uniqid(),
        ]);

        // Register the tenant with the TenantResolver so TenantScope sees a current ID
        $resolver = app(TenantResolver::class);

        // TenantResolver::setResolved is protected; use the ContextManager path instead
        $contextManager = app(ContextManager::class);
        $contextManager->setTenant($this->testTenant);

        // Also ensure TenantResolver reports the correct currentId
        // We need to use reflection or a public method. TenantResolver stores state
        // in resolvedContext. The simplest approach: set context via the resolver's
        // setResolved-compatible path. Check if there's a public setter.
        // TenantResolver has setCurrentTenant(Team) but that takes a Team.
        // Instead, we can directly set resolvedContext via reflection or just
        // ensure ContextManager is bound and has tenant, then TenantScope
        // queries TenantResolver::currentId().
        // Let's check: TenantScope calls $this->getTenantResolver()->currentId()
        // which calls $this->resolvedContext?->getContextId().
        // We need to populate resolvedContext on TenantResolver.

        // Use reflection to set the resolvedContext on TenantResolver
        $refClass = new \ReflectionClass($resolver);
        $refProp = $refClass->getProperty('resolvedContext');
        $refProp->setAccessible(true);
        $refProp->setValue($resolver, $this->testTenant);

        $refProp2 = $refClass->getProperty('resolvedTenantModel');
        $refProp2->setAccessible(true);
        $refProp2->setValue($resolver, $this->testTenant);
    }
}
