<?php

namespace App\Jobs;

use App\Models\Game;
use App\Services\FailurePatternDetector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AnalyzeFailurePatterns implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $gameId) {}

    public function handle(FailurePatternDetector $detector): void
    {
        $game = Game::find($this->gameId);
        if (! $game) return;
        $detector->analyzeGame($game);
    }
}
