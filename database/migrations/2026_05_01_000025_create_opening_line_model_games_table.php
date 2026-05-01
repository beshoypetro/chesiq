<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('opening_line_model_games')) {
            Schema::create('opening_line_model_games', function (Blueprint $table) {
                $table->string('opening_line_id');
                $table->text('game_pgn');
                $table->integer('key_move_ply')->nullable();
                $table->text('idea_text')->nullable();
                $table->integer('position')->default(0);
                $table->primary(['opening_line_id', 'position']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_line_model_games');
    }
};
