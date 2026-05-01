<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('coordinate_scores')) {
            Schema::create('coordinate_scores', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->integer('score');
                $table->float('accuracy')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (!Schema::hasTable('vision_scores')) {
            Schema::create('vision_scores', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->string('drill_type');
                $table->integer('score');
                $table->float('accuracy')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (!Schema::hasTable('user_ai_level')) {
            Schema::create('user_ai_level', function (Blueprint $table) {
                $table->foreignId('user_id')->primary()->constrained()->onDelete('cascade');
                $table->integer('skill_level')->default(10);
                $table->integer('elo_estimate')->default(1200);
                $table->timestamp('updated_at')->nullable();
            });
        }

        if (!Schema::hasTable('repertoires')) {
            Schema::create('repertoires', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->string('name');
                $table->string('color', 10);
                $table->longText('tree_json');
                $table->timestamp('updated_at')->nullable();
            });
        }

        // Add source column to games if missing
        if (!Schema::hasColumn('games', 'source')) {
            Schema::table('games', function (Blueprint $table) {
                $table->string('source', 50)->nullable()->default('chess.com');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('repertoires');
        Schema::dropIfExists('user_ai_level');
        Schema::dropIfExists('vision_scores');
        Schema::dropIfExists('coordinate_scores');
    }
};
