<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable, long-horizon Improvement Plan — one row per user. Distinct from
 * user_plans (weekly, 7-day-expiry, LLM-authored): this is the living
 * milestone path + skill mastery + next action + readiness + adaptation log.
 * Variability lives in the *_json columns so the object stays a single cheap
 * read, mirroring user_plans.plan_json. Spec IMPROVEMENT_PLAN_SPEC.md §2.1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('improvement_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('placement_elo_at_creation')->nullable();
            $table->string('current_milestone_slug', 32);
            $table->json('milestones_json');
            $table->json('skill_mastery_json');
            $table->json('next_action_json');
            $table->unsignedSmallInteger('readiness_score')->default(0);
            $table->string('trainer_id', 16)->nullable();
            $table->text('trainer_intro')->nullable();
            $table->json('adaptation_log_json')->nullable();
            $table->string('signals_hash', 40)->nullable();
            $table->timestamp('generated_at');
            $table->timestamp('last_synced_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('improvement_plans');
    }
};
