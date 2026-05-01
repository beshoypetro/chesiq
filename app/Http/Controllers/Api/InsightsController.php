<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\MoveAnalysis;
use App\Models\UserPuzzleAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InsightsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $period = $request->input('period', '90d');

        $since = match ($period) {
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '1y' => now()->subYear(),
            default => now()->subDays(90),
        };

        $games = $user->games()
            ->where('played_at', '>=', $since)
            ->where('analyzed_at', '!=', null)
            ->get();

        if ($games->isEmpty()) {
            return response()->json(['message' => 'No analyzed games in this period.', 'data' => null]);
        }

        $gameIds = $games->pluck('id');

        // Accuracy trend (per game over time)
        $accuracyTrend = $games->sortBy('played_at')->map(fn ($g) => [
            'date' => $g->played_at?->toDateString(),
            'accuracy' => $g->user_color === 'white' ? $g->white_accuracy : $g->black_accuracy,
            'result' => $g->result,
        ])->values();

        $avgAccuracy = $accuracyTrend->avg('accuracy');

        // Mistake breakdown
        $mistakeBreakdown = MoveAnalysis::whereIn('game_id', $gameIds)
            ->whereHas('game', fn ($q) => $q->where('user_id', $user->id))
            ->whereIn('classification', ['inaccuracy', 'mistake', 'blunder', 'miss'])
            ->selectRaw('classification, count(*) as count')
            ->groupBy('classification')
            ->pluck('count', 'classification');

        // Opening performance
        $openingPerf = $games->groupBy('opening_name')->map(function ($group, $name) {
            if (! $name) {
                return null;
            }
            $wins = $group->where('result', 'win')->count();
            $losses = $group->where('result', 'loss')->count();
            $draws = $group->where('result', 'draw')->count();
            $total = $group->count();
            $acc = $group->avg(fn ($g) => $g->user_color === 'white' ? $g->white_accuracy : $g->black_accuracy);

            return [
                'name' => $name,
                'games' => $total,
                'wins' => $wins,
                'losses' => $losses,
                'draws' => $draws,
                'win_rate' => $total > 0 ? round($wins / $total * 100) : 0,
                'avg_acc' => round($acc ?? 0, 1),
            ];
        })->filter()->sortByDesc('games')->values()->take(10);

        // Time control performance
        $timePerf = $games->groupBy('time_class')->map(function ($group, $tc) {
            if (! $tc) {
                return null;
            }
            $wins = $group->where('result', 'win')->count();
            $total = $group->count();
            $acc = $group->avg(fn ($g) => $g->user_color === 'white' ? $g->white_accuracy : $g->black_accuracy);

            return [
                'time_class' => $tc,
                'games' => $total,
                'win_rate' => $total > 0 ? round($wins / $total * 100) : 0,
                'avg_acc' => round($acc ?? 0, 1),
            ];
        })->filter()->values();

        // Overall stats
        $totalGames = $user->games()->count();
        $wins = $user->games()->where('result', 'win')->count();

        return response()->json([
            'data' => [
                'avg_accuracy' => round($avgAccuracy ?? 0, 1),
                'total_games' => $totalGames,
                'win_rate' => $totalGames > 0 ? round($wins / $totalGames * 100) : 0,
                'accuracy_trend' => $accuracyTrend,
                'mistake_breakdown' => $mistakeBreakdown,
                'opening_perf' => $openingPerf,
                'time_perf' => $timePerf,
            ],
        ]);
    }

    public function phaseAccuracy(Request $request): JsonResponse
    {
        $user = $request->user();

        // Last 30 analyzed games
        $gameIds = $user->games()
            ->whereNotNull('analyzed_at')
            ->orderByDesc('played_at')
            ->limit(30)
            ->pluck('id');

        if ($gameIds->isEmpty()) {
            return response()->json(['data' => null, 'message' => 'No analyzed games yet.']);
        }

        $moves = MoveAnalysis::whereIn('game_id', $gameIds)
            ->whereHas('game', fn ($q) => $q->where('user_id', $user->id))
            ->get(['game_id', 'move_number', 'color', 'cp_loss']);

        // Filter to only user's own moves using game user_color
        $games = $user->games()->whereIn('id', $gameIds)->get(['id', 'user_color', 'played_at'])->keyBy('id');

        $phases = [
            'opening' => ['min' => 1, 'max' => 15, 'accuracies' => []],
            'middlegame' => ['min' => 16, 'max' => 40, 'accuracies' => []],
            'endgame' => ['min' => 41, 'max' => 9999, 'accuracies' => []],
        ];

        // Phase accuracy per game for trend
        $gamePhaseAccuracy = [];

        foreach ($moves as $move) {
            $game = $games[$move->game_id] ?? null;
            if (!$game) continue;
            if ($move->color !== $game->user_color) continue;

            $accuracy = $this->cpLossToAccuracy($move->cp_loss);
            $moveNum = $move->move_number;

            foreach ($phases as $phase => &$data) {
                if ($moveNum >= $data['min'] && $moveNum <= $data['max']) {
                    $data['accuracies'][] = $accuracy;

                    $gamePhaseAccuracy[$game->id][$phase][] = $accuracy;
                    break;
                }
            }
            unset($data);
        }

        $result = [];
        foreach ($phases as $phase => $data) {
            $accs = $data['accuracies'];
            $result[$phase] = count($accs) > 0 ? round(array_sum($accs) / count($accs), 1) : null;
        }

        // Trend: per-game phase accuracy for last 30 games
        $trend = [];
        foreach ($games->sortBy('played_at') as $game) {
            $entry = ['date' => $game->played_at?->toDateString()];
            foreach (['opening', 'middlegame', 'endgame'] as $phase) {
                $accs = $gamePhaseAccuracy[$game->id][$phase] ?? [];
                $entry[$phase] = count($accs) > 0 ? round(array_sum($accs) / count($accs), 1) : null;
            }
            $trend[] = $entry;
        }

        // Weakest phase recommendation
        $weakest = null;
        $weakestAcc = 101;
        foreach ($result as $phase => $acc) {
            if ($acc !== null && $acc < $weakestAcc) {
                $weakestAcc = $acc;
                $weakest = $phase;
            }
        }

        $recommendation = null;
        if ($weakest) {
            $label = $weakest === 'opening' ? 'opening lines' : ($weakest === 'endgame' ? 'endgame trainer' : 'puzzle practice');
            $recommendation = "Your {$weakest} accuracy ({$weakestAcc}%) is your weakest phase — try {$label}.";
        }

        return response()->json([
            'data' => [
                'phases' => $result,
                'trend' => array_values($trend),
                'recommendation' => $recommendation,
                'games_analyzed' => $gameIds->count(),
            ],
        ]);
    }

    /**
     * F011: Return solve rate per tactical theme over rolling 90 days.
     */
    public function puzzleThemes(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $since = now()->subDays(90);

        // Join user_puzzle_attempts with puzzles to get theme data
        $rows = DB::table('user_puzzle_attempts as a')
            ->join('puzzles as p', 'a.puzzle_id', '=', 'p.id')
            ->where('a.user_id', $userId)
            ->where('a.created_at', '>=', $since)
            ->whereNotNull('p.themes')
            ->select('p.themes', 'a.solved')
            ->get();

        // Aggregate per theme
        $themeStats = [];
        foreach ($rows as $row) {
            $themes = is_string($row->themes)
                ? (json_decode($row->themes, true) ?? explode(' ', $row->themes))
                : [];
            foreach ($themes as $theme) {
                $theme = trim($theme);
                if (!$theme) continue;
                if (!isset($themeStats[$theme])) {
                    $themeStats[$theme] = ['solved' => 0, 'total' => 0];
                }
                $themeStats[$theme]['total']++;
                if ($row->solved) $themeStats[$theme]['solved']++;
            }
        }

        $result = [];
        foreach ($themeStats as $theme => $stats) {
            $result[] = [
                'theme' => $theme,
                'total' => $stats['total'],
                'solved' => $stats['solved'],
                'solve_rate' => $stats['total'] > 0
                    ? round($stats['solved'] / $stats['total'] * 100, 1)
                    : 0,
            ];
        }

        // Sort by most attempted
        usort($result, fn ($a, $b) => $b['total'] - $a['total']);

        return response()->json(['data' => array_values($result)]);
    }

    private function cpLossToAccuracy(?int $cpLoss): float
    {
        if ($cpLoss === null || $cpLoss <= 0) return 100.0;
        // Chess.com-style formula approximation
        $acc = 103.1668 * exp(-0.04354 * $cpLoss) - 3.1669;
        return max(0.0, min(100.0, $acc));
    }
}
