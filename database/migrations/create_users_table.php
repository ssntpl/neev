<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
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
            $table->string('name');
            $table->string('username')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('passwords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('password');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('email');
            $table->boolean('is_primary')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'email']);
        });

        Schema::create('login_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('method');
            $table->string('multi_factor_method')->nullable();
            $table->text('location')->nullable();
            $table->string('platform')->nullable();
            $table->string('browser')->nullable();
            $table->string('device')->nullable();
            $table->string('ip_address')->nullable();
            $table->boolean('is_success')->default(false);
            $table->boolean('is_suspicious')->default(false);
            $table->timestamps();
        });

        Schema::create('user_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('device_type');
            $table->string('device_token')->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('passwords');
        Schema::dropIfExists('emails');
        Schema::dropIfExists('login_attempts');
        Schema::dropIfExists('user_devices');
        Schema::dropIfExists('users');
    }
};
