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
        Schema::create('multi_factor_auths', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('method');
            $table->text('secret')->nullable();
            $table->integer('otp')->nullable();
            $table->boolean('preferred')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'method']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('multi_factor_auths');
    }
};
