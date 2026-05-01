<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('puzzle_sets')) {
            Schema::create('puzzle_sets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->string('name');
                $table->boolean('is_auto')->default(false);
                $table->string('share_token')->nullable()->unique();
            });
        }

        if (!Schema::hasTable('puzzle_set_items')) {
            Schema::create('puzzle_set_items', function (Blueprint $table) {
                $table->unsignedBigInteger('set_id');
                $table->string('puzzle_id');
                $table->primary(['set_id', 'puzzle_id']);
                $table->foreign('set_id')->references('id')->on('puzzle_sets')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('puzzle_set_items');
        Schema::dropIfExists('puzzle_sets');
    }
};
