<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     *
     * Creates the team_auth_settings table for tenant-driven authentication.
     * Each team can configure their own authentication method (password or SSO).
     */
    public function up(): void
    {
        Schema::create('team_auth_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->unique()->constrained()->onDelete('cascade');

            // Authentication method: 'password' (default) or 'sso'
            $table->string('auth_method', 20)->default('password');

            // SSO provider configuration (only used when auth_method = 'sso')
            $table->string('sso_provider', 50)->nullable(); // entra, google, okta, etc.
            $table->text('sso_client_id')->nullable();
            $table->text('sso_client_secret')->nullable(); // Should be encrypted at model level
            $table->string('sso_tenant_id')->nullable(); // For Entra ID / Azure AD
            $table->json('sso_extra_config')->nullable(); // Provider-specific options

            // Auto-provisioning: create user membership on first SSO login
            $table->boolean('auto_provision')->default(false);
            $table->string('auto_provision_role')->nullable(); // Role to assign auto-provisioned users

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_auth_settings');
    }
};
