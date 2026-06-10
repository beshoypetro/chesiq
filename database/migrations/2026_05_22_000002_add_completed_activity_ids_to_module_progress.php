<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_progress', function (Blueprint $table) {
            // Track exactly which published activities a user has finished, so
            // re-completing one is idempotent (no double-count) and module
            // completion is computed from the distinct set, not a raw counter.
            $table->json('completed_activity_ids_json')->nullable()->after('activities_total');
        });
    }

    public function down(): void
    {
        Schema::table('module_progress', function (Blueprint $table) {
            $table->dropColumn('completed_activity_ids_json');
        });
    }
};
