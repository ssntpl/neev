<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFC 006: a serving host and a verified email domain are two different
 * things with opposite uniqueness rules, so they get a table each.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // "This owner is served at this host." Unique across every owner, so
        // resolution is deterministic.
        Schema::create('hostnames', function (Blueprint $table) {
            $table->id();
            $table->morphs('owner');
            $table->string('host')->unique();
            $table->string('status')->default('pending');
            $table->string('verification_token')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('verification_failed_at')->nullable();
            $table->timestamps();
        });

        // "Users at this domain belong to this owner." Deliberately not unique
        // across owners: two subsidiaries may both verify one domain.
        Schema::create('email_domains', function (Blueprint $table) {
            $table->id();
            $table->morphs('owner');
            $table->string('domain');
            $table->string('status')->default('pending');
            $table->string('verification_strategy')->default('dns');
            $table->string('verification_token')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('verification_failed_at')->nullable();
            $table->boolean('enforce')->default(false);
            $table->timestamps();
            $table->unique(['owner_type', 'owner_id', 'domain']);
            $table->index(['domain', 'verified_at']);
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->foreignId('primary_hostname_id')->nullable()->constrained('hostnames')->nullOnDelete();
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->foreignId('primary_hostname_id')->nullable()->constrained('hostnames')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropForeign(['primary_hostname_id']);
            $table->dropColumn('primary_hostname_id');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->dropForeign(['primary_hostname_id']);
            $table->dropColumn('primary_hostname_id');
        });

        Schema::dropIfExists('email_domains');
        Schema::dropIfExists('hostnames');
    }
};
