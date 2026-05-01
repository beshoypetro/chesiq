<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('puzzle_streak_scores')) {
            Schema::create('puzzle_streak_scores', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->integer('length');
                $table->timestamp('created_at')->nullable();
            });
        }

        if (!Schema::hasTable('puzzle_rush_scores')) {
            Schema::create('puzzle_rush_scores', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->integer('score');
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('puzzle_streak_scores');
        Schema::dropIfExists('puzzle_rush_scores');
    }
};
