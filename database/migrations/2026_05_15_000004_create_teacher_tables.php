<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('last_message_at')->nullable();
            $table->text('summary_text')->nullable();
            $table->json('summary_embedding')->nullable();
            $table->json('referenced_game_ids_json')->nullable();
            $table->json('referenced_pattern_ids_json')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'last_message_at']);
        });

        Schema::create('teacher_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('teacher_conversations')->cascadeOnDelete();
            $table->enum('role', ['student', 'teacher']);
            $table->enum('mode', ['tutor', 'socratic', 'debate'])->default('tutor');
            $table->text('content');
            $table->string('voice_audio_url', 500)->nullable();
            $table->timestamp('created_at');

            $table->index('conversation_id');
        });

        Schema::create('teacher_facts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('fact_text');
            $table->float('confidence')->default(0.7);
            $table->foreignId('source_conversation_id')->nullable()->constrained('teacher_conversations')->nullOnDelete();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'superseded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_facts');
        Schema::dropIfExists('teacher_messages');
        Schema::dropIfExists('teacher_conversations');
    }
};
