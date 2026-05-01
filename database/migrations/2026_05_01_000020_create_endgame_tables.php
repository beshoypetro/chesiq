<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('endgame_positions')) {
            Schema::create('endgame_positions', function (Blueprint $table) {
                $table->id();
                $table->text('fen');
                $table->string('category'); // pawn, rook, queen, bishop, knight, king
                $table->text('description')->nullable();
                $table->integer('difficulty')->default(1200);
            });
        }

        if (! Schema::hasTable('user_endgame_ratings')) {
            Schema::create('user_endgame_ratings', function (Blueprint $table) {
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->string('category');
                $table->float('rating')->default(1200);
                $table->primary(['user_id', 'category']);
            });
        }

        if (! Schema::hasTable('user_endgame_attempts')) {
            Schema::create('user_endgame_attempts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->foreignId('endgame_position_id')->constrained('endgame_positions')->onDelete('cascade');
                $table->boolean('correct');
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_endgame_attempts');
        Schema::dropIfExists('user_endgame_ratings');
        Schema::dropIfExists('endgame_positions');
    }
};
