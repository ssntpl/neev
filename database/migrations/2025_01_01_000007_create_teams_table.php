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
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('slug')->unique()->nullable();
            $table->boolean('is_public')->default(true);
            $table->timestamp('activated_at')->nullable();
            $table->string('inactive_reason')->nullable();
            $table->timestamps();
            $table->unique(['name', 'user_id']);
            $table->unique(['tenant_id', 'slug']);
            $table->index('slug');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('current_team_id')->nullable()->constrained('teams')->nullOnDelete();
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->foreignId('platform_team_id')->nullable()->constrained('teams')->nullOnDelete();
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
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_team_id');
        });
        if (Schema::hasColumn('tenants', 'platform_team_id')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->dropConstrainedForeignId('platform_team_id');
            });
        }
        Schema::dropIfExists('teams');
    }
};
