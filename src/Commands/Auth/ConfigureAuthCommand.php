<?php

namespace Ssntpl\Neev\Commands\Auth;

use Illuminate\Console\Command;
use Ssntpl\Neev\Commands\Concerns\ResolvesTenantContext;
use Ssntpl\Neev\Models\TeamAuthSettings;
use Ssntpl\Neev\Models\TenantAuthSettings;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class ConfigureAuthCommand extends Command
{
    use ResolvesTenantContext;

    protected $signature = 'neev:auth:configure
                            {--tenant= : Tenant ID or slug}
                            {--team= : Team ID or slug}
                            {--method= : Auth method (password or sso)}
                            {--sso-provider= : SSO provider (entra, google, okta)}
                            {--sso-client-id= : SSO client ID}
                            {--sso-client-secret= : SSO client secret}
                            {--sso-tenant-id= : SSO tenant ID (for Entra)}
                            {--auto-provision : Enable auto-provisioning}
                            {--auto-provision-role= : Role for auto-provisioned users}';

    protected $description = 'Configure authentication method for a tenant or team';

    public function handle(): int
    {
        if ($this->isIsolated() && $this->option('tenant')) {
            return $this->configureTenantAuth();
        }

        if ($this->option('team')) {
            return $this->configureTeamAuth();
        }

        $label = $this->getStrategyLabel();
        $this->error("You must specify --{$label}" . ($this->isIsolated() ? ' or --team' : '') . '.');

        return self::FAILURE;
    }

    protected function configureTenantAuth(): int
    {
        $tenant = $this->resolveTenant($this->option('tenant'));
        $method = $this->resolveMethod();

        $attributes = [
            'tenant_id' => $tenant->id,
            'auth_method' => $method,
        ];

        if ($method === 'sso') {
            $ssoAttributes = $this->resolveSSOConfig();
            if ($ssoAttributes === null) {
                return self::FAILURE;
            }
            $attributes = array_merge($attributes, $ssoAttributes);
        }

        if ($this->option('auto-provision')) {
            $attributes['auto_provision'] = true;
            $attributes['auto_provision_role'] = $this->option('auto-provision-role');
        }

        TenantAuthSettings::updateOrCreate(
            ['tenant_id' => $tenant->id],
            $attributes,
        );

        $this->info("Auth configured for tenant '{$tenant->name}': method={$method}");
        if ($method === 'sso') {
            $this->info("  SSO provider: {$attributes['sso_provider']}");
        }

        return self::SUCCESS;
    }

    protected function configureTeamAuth(): int
    {
        $team = $this->resolveTeam($this->option('team'));
        $method = $this->resolveMethod();

        $attributes = [
            'team_id' => $team->id,
            'auth_method' => $method,
        ];

        if ($method === 'sso') {
            $ssoAttributes = $this->resolveSSOConfig();
            if ($ssoAttributes === null) {
                return self::FAILURE;
            }
            $attributes = array_merge($attributes, $ssoAttributes);
        }

        if ($this->option('auto-provision')) {
            $attributes['auto_provision'] = true;
            $attributes['auto_provision_role'] = $this->option('auto-provision-role');
        }

        TeamAuthSettings::updateOrCreate(
            ['team_id' => $team->id],
            $attributes,
        );

        $this->info("Auth configured for team '{$team->name}': method={$method}");
        if ($method === 'sso') {
            $this->info("  SSO provider: {$attributes['sso_provider']}");
        }

        return self::SUCCESS;
    }

    protected function resolveMethod(): string
    {
        if ($method = $this->option('method')) {
            return $method;
        }

        return select(
            label: 'Which authentication method?',
            options: ['password' => 'Password', 'sso' => 'SSO (Single Sign-On)'],
            default: 'password',
        );
    }

    protected function resolveSSOConfig(): ?array
    {
        $allowedProviders = config('neev.tenant_auth_options.sso_providers', ['entra', 'google', 'okta']);

        $provider = $this->option('sso-provider') ?: select(
            label: 'Which SSO provider?',
            options: array_combine($allowedProviders, array_map('ucfirst', $allowedProviders)),
        );

        if (! in_array($provider, $allowedProviders, true)) {
            $this->error("Invalid SSO provider: {$provider}. Allowed: " . implode(', ', $allowedProviders));

            return null;
        }

        $clientId = $this->option('sso-client-id') ?: text(
            label: 'SSO Client ID:',
            required: true,
        );

        $clientSecret = $this->option('sso-client-secret') ?: password(
            label: 'SSO Client Secret:',
            required: true,
        );

        $attributes = [
            'sso_provider' => $provider,
            'sso_client_id' => $clientId,
            'sso_client_secret' => $clientSecret,
        ];

        if ($provider === 'entra') {
            $tenantId = $this->option('sso-tenant-id') ?: text(
                label: 'Entra Tenant ID (directory ID):',
                required: true,
            );
            $attributes['sso_tenant_id'] = $tenantId;
        }

        return $attributes;
    }
}
