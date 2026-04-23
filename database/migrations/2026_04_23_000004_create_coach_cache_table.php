<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coach_cache', function (Blueprint $table) {
            $table->id();
            $table->string('cache_key', 128)->unique();
            $table->text('response_text');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coach_cache');
    }
};
