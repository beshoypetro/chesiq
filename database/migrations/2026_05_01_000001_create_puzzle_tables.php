<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('puzzles', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->text('fen');
            $table->text('moves');
            $table->text('themes')->nullable();
            $table->integer('rating')->default(1500);
            $table->integer('rating_deviation')->default(350);
        });

        Schema::create('user_puzzle_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('puzzle_id');
            $table->boolean('solved');
            $table->integer('time_ms')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('user_puzzle_ratings', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->onDelete('cascade');
            $table->float('rating')->default(1500);
            $table->float('rd')->default(350);
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_puzzle_ratings');
        Schema::dropIfExists('user_puzzle_attempts');
        Schema::dropIfExists('puzzles');
    }
};
