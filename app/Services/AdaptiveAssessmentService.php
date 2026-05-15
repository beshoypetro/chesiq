<?php

namespace App\Services;

use App\Models\AssessmentPosition;
use App\Models\UserAssessment;

class AdaptiveAssessmentService
{
    public const STARTING_ELO = 1400;
    public const STARTING_CI = 400;
    public const TERMINATION_CI = 50;
    public const MAX_QUESTIONS = 25;
    public const MIN_QUESTIONS = 12;
    public const ELO_STEP = 100;

    public const THEMES = ['tactics', 'calculation', 'strategy', 'endgame'];

    public function nextPosition(UserAssessment $assessment): ?AssessmentPosition
    {
        $answeredIds = $assessment->responses()->pluck('position_id')->all();
        $themeCounts = $assessment->responses()->selectRaw('theme, COUNT(*) c')
            ->groupBy('theme')->pluck('c', 'theme')->all();

        // Pick the under-represented theme to keep coverage balanced.
        $theme = collect(self::THEMES)
            ->sortBy(fn (string $t): int => (int) ($themeCounts[$t] ?? 0))
            ->first();

        $eloLow = $assessment->current_elo_estimate - 150;
        $eloHigh = $assessment->current_elo_estimate + 150;

        return AssessmentPosition::query()
            ->where('theme', $theme)
            ->whereBetween('elo_target', [$eloLow, $eloHigh])
            ->whereNotIn('id', $answeredIds)
            ->inRandomOrder()
            ->first()
            ?? AssessmentPosition::query()
                ->where('theme', $theme)
                ->whereNotIn('id', $answeredIds)
                ->inRandomOrder()
                ->first();
    }

    public function applyResponse(UserAssessment $assessment, AssessmentPosition $position, bool $correct): array
    {
        $eloBefore = $assessment->current_elo_estimate;
        $delta = $correct ? self::ELO_STEP : -self::ELO_STEP;

        // Pull the estimate toward the position's elo if you got it wrong above your level
        // or right below your level — caps drift when answers don't match difficulty.
        if ($correct && $position->elo_target < $eloBefore) {
            $delta = (int) round(self::ELO_STEP * 0.5);
        }
        if (! $correct && $position->elo_target > $eloBefore) {
            $delta = -(int) round(self::ELO_STEP * 0.5);
        }

        $eloAfter = max(400, min(2600, $eloBefore + $delta));

        // Confidence narrows monotonically with each response, but more slowly when answers oscillate.
        $newCi = max(self::TERMINATION_CI, $assessment->confidence_interval - 25);

        $assessment->update([
            'current_elo_estimate' => $eloAfter,
            'confidence_interval' => $newCi,
        ]);

        return ['elo_before' => $eloBefore, 'elo_after' => $eloAfter, 'ci' => $newCi];
    }

    public function shouldTerminate(UserAssessment $assessment): bool
    {
        $count = $assessment->responses()->count();
        if ($count >= self::MAX_QUESTIONS) return true;
        if ($count >= self::MIN_QUESTIONS && $assessment->confidence_interval <= self::TERMINATION_CI) return true;
        return false;
    }

    public function finalize(UserAssessment $assessment): UserAssessment
    {
        $weakness = $assessment->responses()
            ->selectRaw('theme, AVG(CASE WHEN is_correct THEN 1.0 ELSE 0.0 END) as accuracy, COUNT(*) c')
            ->groupBy('theme')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->theme => [
                'accuracy' => round((float) $row->accuracy, 2),
                'count' => (int) $row->c,
            ]])
            ->all();

        $elo = $assessment->current_elo_estimate;
        $track = match (true) {
            $elo < 1000 => 'foundations',
            $elo < 1500 => 'improver',
            $elo < 1900 => 'club',
            default     => 'tournament',
        };

        $assessment->update([
            'completed_at' => now(),
            'final_elo_estimate' => $elo,
            'weakness_profile_json' => $weakness,
            'recommended_track' => $track,
        ]);

        $assessment->user()->update([
            'placement_elo' => $elo,
            'placement_track' => $track,
            'placement_completed_at' => now(),
        ]);

        return $assessment->fresh();
    }
}
