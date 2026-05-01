<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('games', 'repertoire_deviation_ply')) {
            Schema::table('games', function (Blueprint $table) {
                $table->integer('repertoire_deviation_ply')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('games', 'repertoire_deviation_ply')) {
            Schema::table('games', function (Blueprint $table) {
                $table->dropColumn('repertoire_deviation_ply');
            });
        }
    }
};
