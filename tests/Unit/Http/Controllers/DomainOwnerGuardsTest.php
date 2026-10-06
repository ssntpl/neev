<?php

namespace Ssntpl\Neev\Tests\Unit\Http\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Ssntpl\Neev\Http\Controllers\EmailDomainApiController;
use Ssntpl\Neev\Http\Controllers\HostnameApiController;
use Ssntpl\Neev\Models\EmailDomain;
use Ssntpl\Neev\Models\Hostname;
use Ssntpl\Neev\Services\TenantResolver;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;
use Ssntpl\Neev\Tests\Traits\WithTenantContext;

/**
 * The owner checks behind the host and email-domain endpoints fail closed.
 * Through Neev's routes `neev:api` turns away a request with no user first,
 * so these call the controllers directly: an application that mounts them
 * under other middleware still gets a 403, not another tenant's rows.
 */
class DomainOwnerGuardsTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;
    use WithTenantContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableTenantIsolation();
        $this->setUpTenantContext();
    }

    public function test_the_tenant_host_endpoints_refuse_a_request_with_no_user(): void
    {
        $controller = app(HostnameApiController::class);
        $resolver = app(TenantResolver::class);

        $this->assertSame(403, $controller->tenantIndex(Request::create('/'), $resolver)->getStatusCode());
        $this->assertSame(403, $controller->tenantStore(Request::create('/', 'POST', ['host' => 'app.acme.com']), $resolver)->getStatusCode());
        $this->assertSame(0, Hostname::count());
    }

    public function test_the_tenant_email_domain_endpoints_refuse_a_request_with_no_user(): void
    {
        $controller = app(EmailDomainApiController::class);
        $resolver = app(TenantResolver::class);

        $this->assertSame(403, $controller->tenantIndex(Request::create('/'), $resolver)->getStatusCode());
        $this->assertSame(403, $controller->tenantStore(Request::create('/', 'POST', ['domain' => 'acme.com']), $resolver)->getStatusCode());
        $this->assertSame(0, EmailDomain::count());
    }
}
