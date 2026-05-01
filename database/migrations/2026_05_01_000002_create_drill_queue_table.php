<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drill_queue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('game_id')->constrained()->onDelete('cascade');
            $table->integer('move_ply');
            $table->text('fen');
            $table->string('best_move')->nullable();
            $table->timestamp('added_at')->nullable();
            $table->timestamp('solved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drill_queue');
    }
};
