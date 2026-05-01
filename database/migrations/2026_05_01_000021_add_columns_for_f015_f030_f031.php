<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // F015: Chess960 variant column on games
        if (! Schema::hasColumn('games', 'variant')) {
            Schema::table('games', function (Blueprint $table) {
                $table->string('variant', 20)->nullable()->default('standard');
            });
        }

        // F030: Digest unsubscribe column on users
        if (! Schema::hasColumn('users', 'digest_unsubscribed_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('digest_unsubscribed_at')->nullable();
            });
        }

        // F031: Style profile JSON on users
        if (! Schema::hasColumn('users', 'style_profile_json')) {
            Schema::table('users', function (Blueprint $table) {
                $table->text('style_profile_json')->nullable();
            });
        }

        // F008: Streak columns on users
        if (! Schema::hasColumn('users', 'current_streak')) {
            Schema::table('users', function (Blueprint $table) {
                $table->integer('current_streak')->default(0);
                $table->date('last_active_date')->nullable();
            });
        }
    }

    public function down(): void
    {
        // No column drops to keep rollback safe
    }
};
