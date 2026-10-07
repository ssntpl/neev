<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFC 006 §6 Q1: a slug an owner renames away from is never issued to anyone
 * else, because the platform subdomain is derived from it.
 */
return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('retired_slugs', function (Blueprint $table) {
            $table->id();
            $table->morphs('owner');
            $table->string('slug');
            $table->timestamps();
            $table->index(['owner_type', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('retired_slugs');
    }
};
