<?php

namespace Ssntpl\Neev\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Ssntpl\Neev\Models\TenantAuthSettings;

class TenantAuthSettingsFactory extends Factory
{
    protected $model = TenantAuthSettings::class;

    public function definition(): array
    {
        return [
            'tenant_id' => TenantFactory::new(),
            'auth_method' => 'password',
        ];
    }

    public function sso(string $provider = 'entra'): static
    {
        return $this->state([
            'auth_method' => 'sso',
            'sso_provider' => $provider,
            'sso_client_id' => 'test-client-id',
            'sso_client_secret' => 'test-client-secret',
            'sso_tenant_id' => 'test-tenant-id',
        ]);
    }

    public function autoProvision(string $role = 'member'): static
    {
        return $this->state([
            'auto_provision' => true,
            'auto_provision_role' => $role,
        ]);
    }
}
