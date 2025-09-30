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
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('resource_type')->nullable();
            $table->timestamps();
            $table->unique(['name', 'resource_type']);
        });
        
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
        
        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->onDelete('cascade');
            $table->foreignId('permission_id')->constrained()->onDelete('cascade');
            $table->unique(['role_id', 'permission_id']);
        });
        
        Schema::create('model_resource_roles', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('model_id');
            $table->string('model_type');
            $table->unsignedBigInteger('resource_id');
            $table->string('resource_type');
            $table->unique(['model_id', 'model_type', 'resource_id', 'resource_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_resource_roles');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
