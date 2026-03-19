<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Check if users table exists and has data
        if (Schema::hasTable('users')) {
            $userCount = DB::table('users')->count();
            if ($userCount > 0) {
                throw new \RuntimeException(
                    'Cannot drop users table: table contains ' . $userCount . ' user(s). ' .
                    'Neev can only be installed on a fresh installation with an empty users table.'
                );
            }
        }

        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('name');
            $table->string('username')->nullable();
            $table->string('email')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->json('password_history')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'email']);
            $table->index('email');
            $table->index('tenant_id');
        });

        Schema::create('login_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('method');
            $table->string('multi_factor_method')->nullable();
            $table->json('location')->nullable();
            $table->string('platform')->nullable();
            $table->string('browser')->nullable();
            $table->string('device')->nullable();
            $table->string('ip_address')->nullable();
            $table->boolean('is_success')->default(false);
            $table->boolean('is_suspicious')->default(false);
            $table->timestamps();
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('login_attempts');
    }
};
