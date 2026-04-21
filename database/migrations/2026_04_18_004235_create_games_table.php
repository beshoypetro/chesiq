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
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('chess_com_game_id')->unique();
            $table->longText('pgn');
            $table->string('white_username');
            $table->string('black_username');
            $table->integer('white_rating')->nullable();
            $table->integer('black_rating')->nullable();
            $table->enum('user_color', ['white', 'black']);
            $table->enum('result', ['win', 'loss', 'draw']);
            $table->string('time_class')->nullable();
            $table->string('time_control')->nullable();
            $table->string('opening_name')->nullable();
            $table->string('eco_code')->nullable();
            $table->float('white_accuracy')->nullable();
            $table->float('black_accuracy')->nullable();
            $table->integer('move_count')->nullable();
            $table->timestamp('played_at')->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
