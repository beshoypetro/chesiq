<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('move_rationales', function (Blueprint $table) {
            // 'authored' = hand-written by a human, 'generated' = produced by Gemini
            $table->string('source', 16)->default('authored')->after('text');
        });
    }

    public function down(): void
    {
        Schema::table('move_rationales', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
