<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('move_rationales', function (Blueprint $table) {
            $table->id();
            $table->string('line_id', 64);
            $table->unsignedInteger('move_index');
            $table->string('san', 20);
            $table->text('text');
            $table->timestamps();

            $table->unique(['line_id', 'move_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('move_rationales');
    }
};
