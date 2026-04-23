<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_lines', function (Blueprint $table) {
            $table->id();
            $table->string('line_id', 64)->unique();
            $table->string('opening_name', 80);
            $table->string('line_name', 80);
            $table->string('eco', 8)->nullable();
            $table->string('color', 8);
            $table->json('moves');
            $table->timestamps();

            $table->index('opening_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_lines');
    }
};
