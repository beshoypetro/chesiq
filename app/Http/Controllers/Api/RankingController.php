<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ranking advancement — surfaces the user's chess-rating progress timeline
 * derived entirely from the existing `games` table.
 *
 *   GET /api/ranking
 *       ?period=30d|90d|1y|all   (default: 90d)
 *       ?time_class=blitz|rapid|bullet|daily   (optional filter)
 *
 * Response shape:
 *   {
 *     series: [{played_at, rating, time_class}, ...],   // ASC, user's own rating
 *     by_time_class: {rapid: 1180, blitz: 1090, ...},   // latest per class
 *     current: int|null,
 *     start: int|null,
 *     delta: int|null,
 *     delta_since_plan: int|null,
 *     games_counted: int,
 *   }
 *
 * All queries are read-only; no new table required. Defensive: a user with no
 * rated games receives HTTP 200 with nulls so the frontend can show an honest
 * empty state.
 */
class RankingController extends Controller
{
    /** Supported period tokens → number of days (null = all-time). */
    private const PERIOD_DAYS = [
        '30d' => 30,
        '90d' => 90,
        '1y'  => 365,
        'all' => null,
    ];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // --- period ---
        $periodKey = in_array($request->query('period'), array_keys(self::PERIOD_DAYS), strict: true)
            ? $request->query('period')
            : '90d';
        $days = self::PERIOD_DAYS[$periodKey];

        // --- base query: only rows where the user's own rating is non-null ---
        $query = Game::where('user_id', $user->id)
            ->where(function ($q) {
                $q->where(function ($q2) {
                    $q2->where('user_color', 'white')->whereNotNull('white_rating');
                })->orWhere(function ($q2) {
                    $q2->where('user_color', 'black')->whereNotNull('black_rating');
                });
            });

        if ($days !== null) {
            $query->where('played_at', '>=', Carbon::now()->subDays($days));
        }

        // optional time_class filter
        $timeClass = $request->query('time_class');
        if ($timeClass && is_string($timeClass)) {
            $query->where('time_class', $timeClass);
        }

        /** @var \Illuminate\Support\Collection<int, \App\Models\Game> $games */
        $games = $query->orderBy('played_at')->get(['user_color', 'white_rating', 'black_rating', 'time_class', 'played_at']);

        // --- empty-state guard ---
        if ($games->isEmpty()) {
            return response()->json([
                'series' => [],
                'by_time_class' => (object) [],
                'current' => null,
                'start' => null,
                'delta' => null,
                'delta_since_plan' => null,
                'games_counted' => 0,
            ]);
        }

        // --- build series (user's own rating per game) ---
        $series = $games->map(function (Game $g) {
            $rating = $g->user_color === 'white' ? $g->white_rating : $g->black_rating;

            return [
                'played_at'  => $g->played_at?->toISOString(),
                'rating'     => $rating,
                'time_class' => $g->time_class,
            ];
        })->values()->all();

        // --- current & start ---
        $current = $series[count($series) - 1]['rating'];
        $start   = $series[0]['rating'];
        $delta   = ($current !== null && $start !== null) ? ($current - $start) : null;

        // --- by_time_class: latest rating per time_class across the queried window ---
        $byTimeClass = $games
            ->filter(fn (Game $g) => $g->time_class !== null)
            ->groupBy('time_class')
            ->map(function ($group) {
                // last game in ascending order is the most recent
                $last = $group->last();

                return $last->user_color === 'white' ? $last->white_rating : $last->black_rating;
            })
            ->filter(fn ($r) => $r !== null)
            ->toArray();

        // --- delta_since_plan ---
        $deltaSincePlan = $this->computeDeltaSincePlan($user, $current);

        return response()->json([
            'series'          => $series,
            'by_time_class'   => empty($byTimeClass) ? (object) [] : $byTimeClass,
            'current'         => $current,
            'start'           => $start,
            'delta'           => $delta,
            'delta_since_plan' => $deltaSincePlan,
            'games_counted'   => count($series),
        ]);
    }

    /**
     * Compute current_elo − plan_baseline_elo (null-safe).
     *
     * Baseline priority:
     *   1. Rating of the game nearest the plan's created_at (the "in-flight" game closest to plan start).
     *   2. Fall back to improvement_plans.placement_elo_at_creation.
     * Returns null when no plan exists or current is null.
     *
     * @param  \App\Models\User  $user
     * @param  int|null  $currentElo
     */
    private function computeDeltaSincePlan($user, ?int $currentElo): ?int
    {
        if ($currentElo === null) {
            return null;
        }

        $plan = \App\Models\ImprovementPlan::where('user_id', $user->id)->first();

        if ($plan === null) {
            return null;
        }

        // Try to find the game closest to plan.created_at for a more precise baseline.
        $planCreatedAt = $plan->created_at;
        $baselineElo   = null;

        if ($planCreatedAt !== null) {
            // Find the game played nearest to the plan creation date (any side of it).
            $nearestGame = Game::where('user_id', $user->id)
                ->whereNotNull('played_at')
                ->where(function ($q) {
                    $q->where(function ($q2) {
                        $q2->where('user_color', 'white')->whereNotNull('white_rating');
                    })->orWhere(function ($q2) {
                        $q2->where('user_color', 'black')->whereNotNull('black_rating');
                    });
                })
                ->orderByRaw('ABS(JULIANDAY(played_at) - JULIANDAY(?))', [$planCreatedAt->toDateTimeString()])
                ->first(['user_color', 'white_rating', 'black_rating']);

            if ($nearestGame !== null) {
                $baselineElo = $nearestGame->user_color === 'white'
                    ? $nearestGame->white_rating
                    : $nearestGame->black_rating;
            }
        }

        // Fall back to the stored placement snapshot.
        if ($baselineElo === null) {
            $baselineElo = $plan->placement_elo_at_creation;
        }

        if ($baselineElo === null) {
            return null;
        }

        return $currentElo - $baselineElo;
    }
}
