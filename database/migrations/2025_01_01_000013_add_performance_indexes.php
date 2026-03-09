<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Add missing indexes for improved query performance.
     */
    public function up(): void
    {
        // users.current_team_id — foreign key added in teams migration, no index
        if (Schema::hasColumn('users', 'current_team_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->index('current_team_id');
            });
        }

        // login_attempts.user_id — foreign key constraint exists, but no explicit index
        Schema::table('login_attempts', function (Blueprint $table) {
            $table->index('user_id');
        });

        // access_tokens — missing indexes on user_id, attempt_id, expires_at
        Schema::table('access_tokens', function (Blueprint $table) {
            $table->index('user_id');
            $table->index('attempt_id');
            $table->index('expires_at');
        });

        // multi_factor_auths.user_id — covered by unique(['user_id', 'method']),
        // but a standalone index on user_id helps queries that filter only by user_id
        Schema::table('multi_factor_auths', function (Blueprint $table) {
            $table->index('user_id');
        });

        // team_user — has unique(['team_id', 'user_id']) but no standalone indexes
        Schema::table('team_user', function (Blueprint $table) {
            $table->index('team_id');
            $table->index('user_id');
        });

        // domains — composite index for domain resolution queries
        Schema::table('domains', function (Blueprint $table) {
            $table->index(['domain', 'verified_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('users', 'current_team_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex(['current_team_id']);
            });
        }

        Schema::table('login_attempts', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });

        Schema::table('access_tokens', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropIndex(['attempt_id']);
            $table->dropIndex(['expires_at']);
        });

        Schema::table('multi_factor_auths', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });

        Schema::table('team_user', function (Blueprint $table) {
            $table->dropIndex(['team_id']);
            $table->dropIndex(['user_id']);
        });

        Schema::table('domains', function (Blueprint $table) {
            $table->dropIndex(['domain', 'verified_at']);
        });
    }
};
