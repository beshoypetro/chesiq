<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pattern_review_schedule', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('failure_pattern_id')->constrained('user_failure_patterns')->cascadeOnDelete();
            $table->float('ease_factor')->default(2.5);
            $table->unsignedSmallInteger('interval_days')->default(1);
            $table->unsignedSmallInteger('repetition_count')->default(0);
            $table->date('due_at');
            $table->timestamp('last_reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'due_at']);
            $table->unique(['user_id', 'failure_pattern_id'], 'prs_user_pattern_unique');
        });

        Schema::create('module_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->string('serial', 32)->unique();
            $table->timestamp('issued_at');
            $table->timestamps();

            $table->unique(['user_id', 'module_id']);
        });

        Schema::create('llm_call_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('endpoint', 64);
            $table->string('model', 64);
            $table->unsignedInteger('tokens_in')->nullable();
            $table->unsignedInteger('tokens_out')->nullable();
            $table->decimal('cost_estimate', 10, 6)->nullable();
            $table->boolean('cache_hit')->default(false);
            $table->timestamp('created_at');

            $table->index(['endpoint', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_call_log');
        Schema::dropIfExists('module_certificates');
        Schema::dropIfExists('pattern_review_schedule');
    }
};
