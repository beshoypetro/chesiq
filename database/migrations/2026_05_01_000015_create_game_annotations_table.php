<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('game_annotations')) {
            Schema::create('game_annotations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->foreignId('game_id')->constrained()->onDelete('cascade');
                $table->integer('move_ply');
                $table->text('note')->nullable();
                $table->text('arrows_json')->nullable();
                $table->text('highlights_json')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->unique(['user_id', 'game_id', 'move_ply']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('game_annotations');
    }
};
