<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\MoveAnalysis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InsightsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user   = $request->user();
        $period = $request->input('period', '90d');

        $since = match ($period) {
            '7d'  => now()->subDays(7),
            '30d' => now()->subDays(30),
            '1y'  => now()->subYear(),
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
        $accuracyTrend = $games->sortBy('played_at')->map(fn($g) => [
            'date'     => $g->played_at?->toDateString(),
            'accuracy' => $g->user_color === 'white' ? $g->white_accuracy : $g->black_accuracy,
            'result'   => $g->result,
        ])->values();

        $avgAccuracy = $accuracyTrend->avg('accuracy');

        // Mistake breakdown
        $mistakeBreakdown = MoveAnalysis::whereIn('game_id', $gameIds)
            ->whereHas('game', fn($q) => $q->where('user_id', $user->id))
            ->whereIn('classification', ['inaccuracy', 'mistake', 'blunder', 'miss'])
            ->selectRaw('classification, count(*) as count')
            ->groupBy('classification')
            ->pluck('count', 'classification');

        // Opening performance
        $openingPerf = $games->groupBy('opening_name')->map(function ($group, $name) {
            if (!$name) return null;
            $wins   = $group->where('result', 'win')->count();
            $losses = $group->where('result', 'loss')->count();
            $draws  = $group->where('result', 'draw')->count();
            $total  = $group->count();
            $acc    = $group->avg(fn($g) => $g->user_color === 'white' ? $g->white_accuracy : $g->black_accuracy);
            return [
                'name'      => $name,
                'games'     => $total,
                'wins'      => $wins,
                'losses'    => $losses,
                'draws'     => $draws,
                'win_rate'  => $total > 0 ? round($wins / $total * 100) : 0,
                'avg_acc'   => round($acc ?? 0, 1),
            ];
        })->filter()->sortByDesc('games')->values()->take(10);

        // Time control performance
        $timePerf = $games->groupBy('time_class')->map(function ($group, $tc) {
            if (!$tc) return null;
            $wins  = $group->where('result', 'win')->count();
            $total = $group->count();
            $acc   = $group->avg(fn($g) => $g->user_color === 'white' ? $g->white_accuracy : $g->black_accuracy);
            return [
                'time_class' => $tc,
                'games'      => $total,
                'win_rate'   => $total > 0 ? round($wins / $total * 100) : 0,
                'avg_acc'    => round($acc ?? 0, 1),
            ];
        })->filter()->values();

        // Overall stats
        $totalGames = $user->games()->count();
        $wins       = $user->games()->where('result', 'win')->count();

        return response()->json([
            'data' => [
                'avg_accuracy'      => round($avgAccuracy ?? 0, 1),
                'total_games'       => $totalGames,
                'win_rate'          => $totalGames > 0 ? round($wins / $totalGames * 100) : 0,
                'accuracy_trend'    => $accuracyTrend,
                'mistake_breakdown' => $mistakeBreakdown,
                'opening_perf'      => $openingPerf,
                'time_perf'         => $timePerf,
            ],
        ]);
    }
}
