<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coach_user_memory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Tag is a short slug like "weakness:f7-attacks" or
            // "strength:positional-endgames". The coach reads tags first
            // when picking which notes are relevant to the current turn.
            $table->string('tag', 80);
            // Free-text note Alex wrote during a session.
            $table->text('note');
            // Higher weight = more confidence / more recent reinforcement.
            // 1.0 default, decayed and refreshed by the chat surface.
            $table->float('weight')->default(1.0);
            $table->timestamps();

            $table->index(['user_id', 'tag']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coach_user_memory');
    }
};
