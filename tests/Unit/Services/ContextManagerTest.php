<?php

namespace Ssntpl\Neev\Tests\Unit\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Database\Factories\TenantFactory;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\ContextManager;
use Ssntpl\Neev\Tests\TestCase;

class ContextManagerTest extends TestCase
{
    use RefreshDatabase;

    private ContextManager $contextManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contextManager = app(ContextManager::class);
    }

    // -----------------------------------------------------------------
    // Basic setters work before bind
    // -----------------------------------------------------------------

    public function test_set_team_before_bind(): void
    {
        $team = TeamFactory::new()->create();

        $this->contextManager->setTeam($team);

        $this->assertTrue($this->contextManager->hasTeam());
        $this->assertEquals($team->id, $this->contextManager->currentTeam()->id);
    }

    public function test_set_tenant_before_bind(): void
    {
        $tenant = TenantFactory::new()->create();

        $this->contextManager->setTenant($tenant);

        $this->assertTrue($this->contextManager->hasTenant());
        $this->assertEquals($tenant->id, $this->contextManager->currentTenant()->id);
    }

    public function test_set_user_before_bind(): void
    {
        $user = User::factory()->create();

        $this->contextManager->setUser($user);

        $this->assertTrue($this->contextManager->hasUser());
        $this->assertEquals($user->id, $this->contextManager->currentUser()->id);
    }

    public function test_set_context_before_bind(): void
    {
        $team = TeamFactory::new()->create();

        $this->contextManager->setContext($team);

        $this->assertTrue($this->contextManager->hasTeam());
        $this->assertEquals($team->id, $this->contextManager->currentTeam()->id);
    }

    // -----------------------------------------------------------------
    // Bind locks context
    // -----------------------------------------------------------------

    public function test_bind_marks_context_as_bound(): void
    {
        $this->assertFalse($this->contextManager->isBound());

        $this->contextManager->bind();

        $this->assertTrue($this->contextManager->isBound());
    }

    // -----------------------------------------------------------------
    // Setters throw after bind
    // -----------------------------------------------------------------

    public function test_set_team_throws_after_bind(): void
    {
        $team = TeamFactory::new()->create();

        $this->contextManager->bind();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot modify context after it has been bound.');

        $this->contextManager->setTeam($team);
    }

    public function test_set_tenant_throws_after_bind(): void
    {
        $tenant = TenantFactory::new()->create();

        $this->contextManager->bind();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot modify context after it has been bound.');

        $this->contextManager->setTenant($tenant);
    }

    public function test_set_user_throws_after_bind(): void
    {
        $user = User::factory()->create();

        $this->contextManager->bind();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot modify context after it has been bound.');

        $this->contextManager->setUser($user);
    }

    public function test_set_context_throws_after_bind(): void
    {
        $team = TeamFactory::new()->create();

        $this->contextManager->bind();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot modify context after it has been bound.');

        $this->contextManager->setContext($team);
    }

    // -----------------------------------------------------------------
    // clear() resets immutability
    // -----------------------------------------------------------------

    public function test_clear_resets_bound_flag(): void
    {
        $team = TeamFactory::new()->create();
        $this->contextManager->setTeam($team);
        $this->contextManager->bind();

        $this->assertTrue($this->contextManager->isBound());

        $this->contextManager->clear();

        $this->assertFalse($this->contextManager->isBound());
        $this->assertFalse($this->contextManager->hasTeam());
        $this->assertFalse($this->contextManager->hasTenant());
        $this->assertFalse($this->contextManager->hasUser());
    }

    public function test_setters_work_after_clear(): void
    {
        $this->contextManager->bind();
        $this->contextManager->clear();

        $team = TeamFactory::new()->create();
        $this->contextManager->setTeam($team);

        $this->assertTrue($this->contextManager->hasTeam());
    }

    // -----------------------------------------------------------------
    // currentContext returns tenant-first
    // -----------------------------------------------------------------

    public function test_current_context_returns_tenant_over_team(): void
    {
        $tenant = TenantFactory::new()->create();
        $team = TeamFactory::new()->create();

        $this->contextManager->setTenant($tenant);
        $this->contextManager->setTeam($team);

        $context = $this->contextManager->currentContext();

        $this->assertEquals('tenant', $context->getContextType());
        $this->assertEquals($tenant->id, $context->getContextId());
    }

    public function test_current_context_returns_team_when_no_tenant(): void
    {
        $team = TeamFactory::new()->create();

        $this->contextManager->setTeam($team);

        $context = $this->contextManager->currentContext();

        $this->assertEquals('team', $context->getContextType());
        $this->assertEquals($team->id, $context->getContextId());
    }
}
