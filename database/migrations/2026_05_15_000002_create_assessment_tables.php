<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_positions', function (Blueprint $table) {
            $table->id();
            $table->string('theme', 32);
            $table->unsignedSmallInteger('difficulty_band');
            $table->unsignedSmallInteger('elo_target');
            $table->string('fen', 100);
            $table->enum('question_kind', ['best_move', 'multiple_choice', 'plan_select']);
            $table->json('payload');
            $table->json('correct_answer');
            $table->string('explanation', 500)->nullable();
            $table->timestamps();

            $table->index(['theme', 'difficulty_band']);
            $table->index('elo_target');
        });

        Schema::create('user_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedSmallInteger('current_elo_estimate')->default(1400);
            $table->unsignedSmallInteger('confidence_interval')->default(400);
            $table->unsignedSmallInteger('final_elo_estimate')->nullable();
            $table->json('weakness_profile_json')->nullable();
            $table->string('recommended_track', 32)->nullable();
            $table->timestamps();

            $table->index('user_id');
        });

        Schema::create('assessment_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('user_assessments')->cascadeOnDelete();
            $table->foreignId('position_id')->constrained('assessment_positions');
            $table->string('theme', 32);
            $table->json('answer');
            $table->boolean('is_correct');
            $table->unsignedInteger('response_ms')->nullable();
            $table->unsignedSmallInteger('elo_before');
            $table->unsignedSmallInteger('elo_after');
            $table->timestamps();

            $table->index('assessment_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('placement_elo')->nullable()->after('style_profile_json');
            $table->string('placement_track', 32)->nullable()->after('placement_elo');
            $table->timestamp('placement_completed_at')->nullable()->after('placement_track');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['placement_elo', 'placement_track', 'placement_completed_at']);
        });
        Schema::dropIfExists('assessment_responses');
        Schema::dropIfExists('user_assessments');
        Schema::dropIfExists('assessment_positions');
    }
};
