<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V2 onboarding + trainer-selection columns on users. Spec §21.
 *
 * coaching_mode lives as a plain string (not native enum) for SQLite parity —
 * the project's test suite uses :memory: SQLite and Laravel's enum column type
 * doesn't degrade cleanly there. App-level validation enforces the set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('lichess_username', 50)->nullable()->after('chess_com_username');
            $table->string('selected_trainer_id', 16)->nullable()->after('lichess_username');
            $table->string('coaching_mode', 8)->default('full')->after('selected_trainer_id');
            $table->unsignedTinyInteger('onboarding_step')->default(0)->after('coaching_mode');
            $table->timestamp('onboarding_completed_at')->nullable()->after('onboarding_step');
            $table->json('intents')->nullable()->after('onboarding_completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'lichess_username',
                'selected_trainer_id',
                'coaching_mode',
                'onboarding_step',
                'onboarding_completed_at',
                'intents',
            ]);
        });
    }
};
