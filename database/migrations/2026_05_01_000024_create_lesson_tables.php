<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lessons')) {
            Schema::create('lessons', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->text('video_url');
                $table->string('theme')->nullable();
                $table->string('level')->nullable(); // beginner, intermediate, advanced
                $table->foreignId('puzzle_set_id')->nullable()->constrained('puzzle_sets')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('user_lesson_progress')) {
            Schema::create('user_lesson_progress', function (Blueprint $table) {
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->foreignId('lesson_id')->constrained('lessons')->onDelete('cascade');
                $table->timestamp('completed_at')->nullable();
                $table->primary(['user_id', 'lesson_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_lesson_progress');
        Schema::dropIfExists('lessons');
    }
};
