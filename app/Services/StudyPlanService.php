<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class StudyPlanService
{
    /**
     * Generate a 7-day study plan (Mon-Sun) based on user stats.
     */
    public function generate(User $user): array
    {
        $signals = $this->signals($user);

        // Build plan — simple heuristic rules
        $plan = [];
        $dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

        for ($i = 0; $i < 7; $i++) {
            $plan[$i] = [
                'day_index' => $i,
                'day_name' => $dayNames[$i],
                'activities' => $this->activitiesForDay($i, $signals),
            ];
        }

        return $plan;
    }

    /**
     * Core training signals for a user, computed deterministically from the DB.
     * Single source so ImprovementPlanService reuses the exact same numbers the
     * weekly plan is built from (spec §3 — reuse, don't duplicate).
     *
     * @return array{puzzle_rating: float, avg_accuracy: float, deviation_avg: float, weak_phase: ?string, weak_theme: ?string}
     */
    public function signals(User $user): array
    {
        $puzzleRating = DB::table('user_puzzle_ratings')
            ->where('user_id', $user->id)
            ->value('rating') ?? 1500;

        $avgAccuracy = $user->games()
            ->whereNotNull('analyzed_at')
            ->get()
            ->avg(fn ($g) => $g->user_color === 'white' ? $g->white_accuracy : $g->black_accuracy) ?? 0;

        $deviationAvg = $user->games()
            ->whereNotNull('repertoire_deviation_ply')
            ->avg('repertoire_deviation_ply') ?? 0;

        return [
            'puzzle_rating' => (float) $puzzleRating,
            'avg_accuracy' => (float) $avgAccuracy,
            'deviation_avg' => (float) $deviationAvg,
            'weak_phase' => $this->weakestPhase($user),
            'weak_theme' => $this->weakestTheme($user),
        ];
    }

    private function activitiesForDay(int $day, array $stats): array
    {
        $weakPhase = $stats['weak_phase'];
        $weakTheme = $stats['weak_theme'];
        $accuracy = $stats['avg_accuracy'];
        $deviation = $stats['deviation_avg'];

        return match ($day) {
            0 => [  // Monday
                ['type' => 'puzzles', 'label' => '20 puzzles' . ($weakTheme ? " — focus on {$weakTheme}" : ''), 'route' => '/puzzles'],
            ],
            1 => [  // Tuesday
                ['type' => 'games', 'label' => 'Review your 2 most recent games', 'route' => '/games'],
            ],
            2 => [  // Wednesday
                ['type' => 'opening', 'label' => $deviation < 15 ? 'Opening drill — extend prep past move ' . round($deviation) : '1 opening trainer session', 'route' => '/learn'],
            ],
            3 => [  // Thursday
                ['type' => 'puzzles', 'label' => '15 puzzles — streak mode', 'route' => '/puzzles/streak'],
            ],
            4 => [  // Friday
                ['type' => 'endgame', 'label' => $weakPhase === 'endgame' ? 'Endgame trainer (your weakest phase!)' : 'Endgame trainer', 'route' => '/endgame'],
            ],
            5 => [  // Saturday
                ['type' => 'play', 'label' => 'Play a practice game vs AI', 'route' => '/play'],
                ['type' => 'analysis', 'label' => 'Analyze the game after', 'route' => '/games'],
            ],
            6 => [  // Sunday
                ['type' => 'review', 'label' => $accuracy < 70 ? 'Focus: Accuracy drill — puzzle rush' : 'Review week progress on Insights', 'route' => $accuracy < 70 ? '/puzzles/rush' : '/insights'],
            ],
            default => [],
        };
    }

    /**
     * Weakest game phase by average centipawn loss on the user's own moves.
     * Public so ImprovementPlanService can reuse the same signal (spec §3/§4 —
     * do not duplicate the computation). Returns 'opening'|'middlegame'|'endgame'|null.
     */
    public function weakestPhase(User $user): ?string
    {
        $gameIds = $user->games()->whereNotNull('analyzed_at')->pluck('id');
        if ($gameIds->isEmpty()) {
            return null;
        }

        $phases = ['opening' => [1, 15], 'middlegame' => [16, 40], 'endgame' => [41, 999]];
        $accs = [];

        foreach ($phases as $phase => [$min, $max]) {
            $avgCpLoss = DB::table('move_analyses as ma')
                ->join('games as g', 'ma.game_id', '=', 'g.id')
                ->whereIn('ma.game_id', $gameIds)
                ->where('g.user_id', $user->id)
                ->whereRaw('ma.color = g.user_color')
                ->whereBetween('ma.move_number', [$min, $max])
                ->avg('cp_loss');
            $accs[$phase] = $avgCpLoss;
        }

        $worst = null;
        $worstVal = -1;
        foreach ($accs as $phase => $val) {
            if ($val !== null && $val > $worstVal) {
                $worstVal = $val;
                $worst = $phase;
            }
        }

        return $worst;
    }

    /**
     * Weakest tactical puzzle theme (lowest solve rate over the last 90 days,
     * min 3 attempts). Public so ImprovementPlanService can reuse it for the
     * next-action puzzle motif (spec §5). Returns the theme string or null.
     */
    public function weakestTheme(User $user): ?string
    {
        $rows = DB::table('user_puzzle_attempts as a')
            ->join('puzzles as p', 'a.puzzle_id', '=', 'p.id')
            ->where('a.user_id', $user->id)
            ->where('a.created_at', '>=', now()->subDays(90))
            ->whereNotNull('p.themes')
            ->select('p.themes', 'a.solved')
            ->get();

        $stats = [];
        foreach ($rows as $row) {
            $themes = is_string($row->themes)
                ? (json_decode($row->themes, true) ?? explode(' ', $row->themes))
                : [];
            foreach ($themes as $theme) {
                $theme = trim($theme);
                if (! $theme) {
                    continue;
                }
                if (! isset($stats[$theme])) {
                    $stats[$theme] = ['solved' => 0, 'total' => 0];
                }
                $stats[$theme]['total']++;
                if ($row->solved) {
                    $stats[$theme]['solved']++;
                }
            }
        }

        $worst = null;
        $worstRate = 101;
        foreach ($stats as $theme => $s) {
            if ($s['total'] < 3) {
                continue;
            }
            $rate = $s['solved'] / $s['total'] * 100;
            if ($rate < $worstRate) {
                $worstRate = $rate;
                $worst = $theme;
            }
        }

        return $worst;
    }
}
