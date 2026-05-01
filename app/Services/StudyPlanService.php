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
        // Gather stats
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

        // Phase accuracy
        $phaseRec = $this->weakestPhase($user);

        // Tactical theme weakness
        $weakTheme = $this->weakestTheme($user);

        // Build plan — simple heuristic rules
        $plan = [];
        $dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

        for ($i = 0; $i < 7; $i++) {
            $plan[$i] = [
                'day_index' => $i,
                'day_name' => $dayNames[$i],
                'activities' => $this->activitiesForDay($i, [
                    'puzzle_rating' => $puzzleRating,
                    'avg_accuracy' => $avgAccuracy,
                    'deviation_avg' => $deviationAvg,
                    'weak_phase' => $phaseRec,
                    'weak_theme' => $weakTheme,
                ]),
            ];
        }

        return $plan;
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

    private function weakestPhase(User $user): ?string
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

    private function weakestTheme(User $user): ?string
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
