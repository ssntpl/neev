<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            if (config('neev.domain_federation')) {
                $table->boolean('enforce_domain')->default(false);
                $table->string('federated_domain')->nullable();
                $table->string('domain_verification_token')->nullable();
                $table->timestamp('domain_verified_at')->nullable();
            }
            $table->boolean('is_public')->default(true);
            $table->timestamps();
            $table->unique(['name', 'user_id']);
        });

        Schema::create('team_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('role')->nullable();
            $table->boolean('joined')->default(false);
            $table->enum('action', ['request_to_user', 'request_from_user'])->default('request_to_user');
            $table->timestamps();
            $table->unique(['team_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_user');
        Schema::dropIfExists('teams');
    }
};
