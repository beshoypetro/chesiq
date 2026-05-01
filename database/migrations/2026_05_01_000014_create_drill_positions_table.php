<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('drill_positions')) {
            Schema::create('drill_positions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->string('name')->nullable();
                $table->text('fen');
                $table->string('color', 10)->default('white');
                $table->integer('attempts')->default(0);
                $table->integer('wins')->default(0);
                $table->integer('draws')->default(0);
                $table->integer('losses')->default(0);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('drill_positions');
    }
};
