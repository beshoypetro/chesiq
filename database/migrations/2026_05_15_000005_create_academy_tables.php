<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracks', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->unsignedSmallInteger('elo_min');
            $table->unsignedSmallInteger('elo_max');
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('published')->default(false);
            $table->timestamps();
        });

        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('track_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 64);
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();

            $table->unique(['track_id', 'slug']);
        });

        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 64);
            $table->string('name');
            $table->text('overview')->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('published')->default(false);
            $table->timestamps();

            $table->unique(['course_id', 'slug']);
        });

        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->enum('type', [
                'puzzle_set',
                'repertoire_lines',
                'video_lesson',
                'position_drill',
                'endgame_set',
                'guided_study',
                'quiz',
                'calculation_drill',
                'lesson_markdown',
            ]);
            $table->string('title');
            $table->json('config');
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('published')->default(false);
            $table->timestamps();

            $table->index('module_id');
        });

        Schema::create('course_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->timestamp('enrolled_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'course_id']);
        });

        Schema::create('module_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedSmallInteger('activities_completed')->default(0);
            $table->unsignedSmallInteger('activities_total')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'module_id']);
        });

        Schema::create('quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->json('answers');
            $table->unsignedSmallInteger('score');
            $table->unsignedSmallInteger('max_score');
            $table->timestamp('completed_at');
            $table->timestamps();

            $table->index(['user_id', 'activity_id']);
        });

        Schema::create('content_drafts', function (Blueprint $table) {
            $table->id();
            $table->enum('kind', ['lesson', 'quiz', 'puzzle_set', 'guided_study']);
            $table->string('topic');
            $table->string('elo_band', 16);
            $table->json('payload');
            $table->enum('status', ['pending_review', 'approved', 'rejected'])->default('pending_review');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_drafts');
        Schema::dropIfExists('quiz_attempts');
        Schema::dropIfExists('module_progress');
        Schema::dropIfExists('course_enrollments');
        Schema::dropIfExists('activities');
        Schema::dropIfExists('modules');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('tracks');
    }
};
