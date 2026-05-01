<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Table may already exist if create_feature_tables ran first — skip gracefully
        if (Schema::hasTable('opening_repetitions')) {
            // Add missing unique index if not present
            if (!Schema::hasIndex('opening_repetitions', 'opening_repetitions_user_id_opening_key_move_uci_unique')) {
                Schema::table('opening_repetitions', function (Blueprint $table) {
                    $table->unique(['user_id', 'opening_key', 'move_uci']);
                });
            }
            return;
        }

        Schema::create('opening_repetitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('opening_key');
            $table->string('move_uci');
            $table->float('ease_factor')->default(2.5);
            $table->integer('interval_days')->default(1);
            $table->timestamp('due_at')->nullable();
            $table->integer('repetitions')->default(0);
            $table->unique(['user_id', 'opening_key', 'move_uci']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_repetitions');
    }
};
