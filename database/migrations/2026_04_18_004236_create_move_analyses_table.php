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
        Schema::create('move_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->integer('move_number');
            $table->enum('color', ['white', 'black']);
            $table->string('move_san');
            $table->string('best_move_san')->nullable();
            $table->enum('classification', [
                'brilliant', 'best', 'excellent', 'good',
                'inaccuracy', 'mistake', 'blunder', 'miss'
            ])->nullable();
            $table->integer('cp_loss')->nullable();
            $table->integer('eval_before')->nullable();
            $table->integer('eval_after')->nullable();
            $table->integer('best_move_eval')->nullable();
            $table->text('explanation')->nullable();
            $table->json('best_move_line')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('move_analyses');
    }
};
