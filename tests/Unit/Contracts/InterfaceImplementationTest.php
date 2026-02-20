<?php

namespace Ssntpl\Neev\Tests\Unit\Contracts;

use Ssntpl\Neev\Contracts\ContextContainerInterface;
use Ssntpl\Neev\Contracts\HasMembersInterface;
use Ssntpl\Neev\Contracts\IdentityProviderOwnerInterface;
use Ssntpl\Neev\Contracts\ResolvableContextInterface;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\Tenant;
use Ssntpl\Neev\Tests\TestCase;

class InterfaceImplementationTest extends TestCase
{
    // -----------------------------------------------------------------
    // Team implements all four interfaces
    // -----------------------------------------------------------------

    public function test_team_implements_context_container_interface(): void
    {
        $this->assertInstanceOf(ContextContainerInterface::class, new Team());
    }

    public function test_team_implements_identity_provider_owner_interface(): void
    {
        $this->assertInstanceOf(IdentityProviderOwnerInterface::class, new Team());
    }

    public function test_team_implements_has_members_interface(): void
    {
        $this->assertInstanceOf(HasMembersInterface::class, new Team());
    }

    public function test_team_implements_resolvable_context_interface(): void
    {
        $this->assertInstanceOf(ResolvableContextInterface::class, new Team());
    }

    // -----------------------------------------------------------------
    // Tenant implements all four interfaces
    // -----------------------------------------------------------------

    public function test_tenant_implements_context_container_interface(): void
    {
        $this->assertInstanceOf(ContextContainerInterface::class, new Tenant());
    }

    public function test_tenant_implements_identity_provider_owner_interface(): void
    {
        $this->assertInstanceOf(IdentityProviderOwnerInterface::class, new Tenant());
    }

    public function test_tenant_implements_has_members_interface(): void
    {
        $this->assertInstanceOf(HasMembersInterface::class, new Tenant());
    }

    public function test_tenant_implements_resolvable_context_interface(): void
    {
        $this->assertInstanceOf(ResolvableContextInterface::class, new Tenant());
    }

    // -----------------------------------------------------------------
    // Team — ContextContainerInterface methods
    // -----------------------------------------------------------------

    public function test_team_get_context_id_returns_id(): void
    {
        $team = new Team();
        $team->id = 42;

        $this->assertSame(42, $team->getContextId());
    }

    public function test_team_get_context_slug_returns_slug(): void
    {
        $team = new Team();
        $team->slug = 'my-team';

        $this->assertSame('my-team', $team->getContextSlug());
    }

    // -----------------------------------------------------------------
    // Team — HasMembersInterface methods delegate to existing
    // -----------------------------------------------------------------

    public function test_team_members_is_same_as_all_users(): void
    {
        $team = new Team();

        // Both should return the same relationship class
        $this->assertSame(
            get_class($team->members()),
            get_class($team->allUsers())
        );
    }
}
