<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_failure_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pattern_kind', 64);
            $table->string('opening_eco', 8)->nullable();
            $table->string('phase', 16)->nullable();   // opening | middlegame | endgame
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->json('last_game_ids_json')->nullable();
            $table->json('sample_position_json')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'pattern_kind']);
            $table->unique(['user_id', 'pattern_kind', 'opening_eco', 'phase'], 'ufp_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_failure_patterns');
    }
};
