<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('user_puzzle_attempts', 'time_limit_ms')) {
            Schema::table('user_puzzle_attempts', function (Blueprint $table) {
                $table->integer('time_limit_ms')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('user_puzzle_attempts', 'time_limit_ms')) {
            Schema::table('user_puzzle_attempts', function (Blueprint $table) {
                $table->dropColumn('time_limit_ms');
            });
        }
    }
};
