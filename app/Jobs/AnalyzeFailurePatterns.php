<?php

namespace App\Jobs;

use App\Models\Game;
use App\Services\FailurePatternDetector;
use App\Services\ImprovementPlanService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AnalyzeFailurePatterns implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $gameId) {}

    public function handle(FailurePatternDetector $detector, ImprovementPlanService $improvement): void
    {
        $game = Game::find($this->gameId);
        if (! $game) return;
        $detector->analyzeGame($game);

        // Game analyzed → recompute mastery + milestone progress (spec §7).
        // Defensive: a plan-sync failure must never break analysis.
        try {
            if ($game->user) {
                $improvement->sync($game->user);
            }
        } catch (\Throwable $e) {
            Log::warning('ImprovementPlan sync (game analyzed) failed', ['error' => $e->getMessage()]);
        }
    }
}
