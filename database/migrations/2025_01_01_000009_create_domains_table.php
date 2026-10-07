<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('owner');
            $table->boolean('enforce')->default(false);
            $table->string('domain')->index();
            $table->string('verification_token')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('verification_failed_at')->nullable();
            $table->timestamps();
            $table->index(['domain', 'verified_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Created here before RFC 006; an upgraded install may still hold it.
        Schema::dropIfExists('domain_rules');
        Schema::dropIfExists('domains');
    }
};
