<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('study_plan_overrides')) {
            Schema::create('study_plan_overrides', function (Blueprint $table) {
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->tinyInteger('day_index'); // 0=Mon...6=Sun
                $table->text('activity_json')->nullable();
                $table->primary(['user_id', 'day_index']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('study_plan_overrides');
    }
};
