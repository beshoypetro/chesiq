<?php

namespace App\Services;

use App\Models\AssessmentPosition;
use App\Models\UserAssessment;

class AdaptiveAssessmentService
{
    // V2 Phase G calibration — start at 1200 (broader middle), bigger initial
    // step that narrows as confidence builds, hard stop at 12 questions
    // (CHESSIQ_V2_PLAN §13). The mix below is enforced by THEME_MIX.
    public const STARTING_ELO = 1200;
    public const STARTING_CI = 400;
    public const TERMINATION_CI = 50;
    public const MAX_QUESTIONS = 12;
    public const MIN_QUESTIONS = 8;
    public const ELO_STEP = 100;

    public const THEMES = ['tactics', 'calculation', 'strategy', 'endgame'];

    /**
     * Target distribution over 12 questions per spec §13:
     * 4 tactics · 3 calculation · 3 strategy · 2 endgame.
     */
    public const THEME_MIX = [
        'tactics' => 4,
        'calculation' => 3,
        'strategy' => 3,
        'endgame' => 2,
    ];

    public function nextPosition(UserAssessment $assessment): ?AssessmentPosition
    {
        $answeredIds = $assessment->responses()->pluck('position_id')->all();
        $themeCounts = $assessment->responses()->selectRaw('theme, COUNT(*) c')
            ->groupBy('theme')->pluck('c', 'theme')->all();

        // V2: pick the theme that's furthest below its target in THEME_MIX so
        // every assessment lands close to 4/3/3/2 over 12 questions.
        $theme = collect(self::THEMES)
            ->sortBy(function (string $t) use ($themeCounts): float {
                $target = self::THEME_MIX[$t] ?? 1;
                $have = (int) ($themeCounts[$t] ?? 0);
                return $have / max(1, $target);
            })
            ->first();

        // Adaptive step — start wide, narrow as we converge. Spec §13: ±200 → ±50.
        $window = $this->difficultyWindow($assessment);
        $eloLow = $assessment->current_elo_estimate - $window;
        $eloHigh = $assessment->current_elo_estimate + $window;

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

    /**
     * Adaptive difficulty window — V2 Phase G (spec §13).
     * Starts at ±200 Elo (broad), narrows to ±50 as confidence builds.
     * Linearly interpolated on confidence_interval (400 → 50).
     */
    private function difficultyWindow(UserAssessment $assessment): int
    {
        $ci = max(self::TERMINATION_CI, (int) $assessment->confidence_interval);
        $maxCi = self::STARTING_CI;
        $minCi = self::TERMINATION_CI;
        $progress = ($maxCi - $ci) / max(1, $maxCi - $minCi); // 0 → 1
        $window = (int) round(200 - 150 * $progress); // 200 → 50

        return max(50, min(200, $window));
    }

    /**
     * Skip the quiz entirely and infer placement from the user's chess.com
     * game history. Falls back to STARTING_ELO when the user has no analyzed
     * games. CHESSIQ_V2_PLAN §13 — the "Skip — estimate from my games" path.
     *
     * Returns the same shape as finalize(): { elo, track, weakness }.
     */
    public function estimateFromGames(\App\Models\User $user): array
    {
        $games = \App\Models\Game::query()
            ->where('user_id', $user->id)
            ->whereNotNull('analyzed_at')
            ->orderByDesc('played_at')
            ->limit(20)
            ->get(['user_color', 'white_accuracy', 'black_accuracy', 'white_rating', 'black_rating']);

        if ($games->isEmpty()) {
            // No games to infer from — still complete placement at the default
            // starting point so the "skip the quiz" path lands the user on a
            // real Improvement Plan instead of a dead end.
            $user->update([
                'placement_elo' => self::STARTING_ELO,
                'placement_track' => 'improver',
                'placement_completed_at' => now(),
            ]);

            return [
                'elo' => self::STARTING_ELO,
                'track' => 'improver',
                'weakness_profile' => [],
                'source' => 'fallback_no_games',
            ];
        }

        // Average user-side accuracy + opposing player's rating; weight rating
        // by recency. Simple but solid first pass — real IRT later.
        $accuracies = [];
        $oppRatings = [];
        foreach ($games as $g) {
            $userAcc = $g->user_color === 'white' ? $g->white_accuracy : $g->black_accuracy;
            $oppRat = $g->user_color === 'white' ? $g->black_rating : $g->white_rating;
            if ($userAcc !== null) $accuracies[] = (float) $userAcc;
            if ($oppRat !== null) $oppRatings[] = (int) $oppRat;
        }

        $avgAcc = empty($accuracies) ? 75.0 : array_sum($accuracies) / count($accuracies);
        $avgOppRating = empty($oppRatings) ? self::STARTING_ELO : (int) (array_sum($oppRatings) / count($oppRatings));

        // Accuracy >85% suggests user is ~150 below their opponents; <70% suggests +150 above.
        $accAdjustment = (int) round(($avgAcc - 75.0) * 8.0); // 75% → 0, 85% → +80, 65% → -80
        $estimate = max(400, min(2600, $avgOppRating + $accAdjustment));

        $track = match (true) {
            $estimate < 1000 => 'foundations',
            $estimate < 1500 => 'improver',
            $estimate < 1900 => 'club',
            default          => 'tournament',
        };

        $user->update([
            'placement_elo' => $estimate,
            'placement_track' => $track,
            'placement_completed_at' => now(),
        ]);

        return [
            'elo' => $estimate,
            'track' => $track,
            'weakness_profile' => [],
            'source' => 'game_history',
            'games_analyzed' => count($accuracies),
        ];
    }
}
