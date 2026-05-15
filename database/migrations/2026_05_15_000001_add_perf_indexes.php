<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->index(['user_id', 'played_at'], 'games_user_played_at_idx');
        });

        Schema::table('move_analyses', function (Blueprint $table) {
            $table->index(['game_id', 'classification'], 'move_analyses_game_class_idx');
        });

        Schema::table('puzzles', function (Blueprint $table) {
            $table->index('rating', 'puzzles_rating_idx');
        });

        Schema::table('user_puzzle_attempts', function (Blueprint $table) {
            $table->index(['user_id', 'created_at'], 'upa_user_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropIndex('games_user_played_at_idx');
        });

        Schema::table('move_analyses', function (Blueprint $table) {
            $table->dropIndex('move_analyses_game_class_idx');
        });

        Schema::table('puzzles', function (Blueprint $table) {
            $table->dropIndex('puzzles_rating_idx');
        });

        Schema::table('user_puzzle_attempts', function (Blueprint $table) {
            $table->dropIndex('upa_user_created_idx');
        });
    }
};
