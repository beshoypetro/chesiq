<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // F004 — add source column to games
        if (!Schema::hasColumn('games', 'source')) {
            Schema::table('games', function (Blueprint $table) {
                $table->string('source')->default('manual')->after('analyzed_at');
            });
        }

        // F006 — Spaced Repetition for Opening Trainer
        Schema::create('opening_repetitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('opening_key');
            $table->string('move_uci');
            $table->float('ease_factor')->default(2.5);
            $table->integer('interval_days')->default(1);
            $table->timestamp('due_at')->nullable();
            $table->integer('repetitions')->default(0);
        });

        // F013 — Board Coordinate Training
        Schema::create('coordinate_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->integer('score');
            $table->float('accuracy')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        // F035 — Vision Drills
        Schema::create('vision_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('drill_type');
            $table->integer('score');
            $table->float('accuracy')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        // F037 — Adaptive AI Opponent
        Schema::create('user_ai_level', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->onDelete('cascade');
            $table->integer('skill_level')->default(10);
            $table->integer('elo_estimate')->default(1200);
            $table->timestamp('updated_at')->nullable();
        });

        // F018 — Custom Repertoire Builder
        Schema::create('repertoires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('color');
            $table->longText('tree_json');
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repertoires');
        Schema::dropIfExists('user_ai_level');
        Schema::dropIfExists('vision_scores');
        Schema::dropIfExists('coordinate_scores');
        Schema::dropIfExists('opening_repetitions');
        if (Schema::hasColumn('games', 'source')) {
            Schema::table('games', function (Blueprint $table) {
                $table->dropColumn('source');
            });
        }
    }
};
