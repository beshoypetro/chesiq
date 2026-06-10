<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DrillQueue;
use App\Models\OpeningRepetition;
use App\Models\PatternReviewSchedule;
use App\Models\PuzzleSet;
use App\Models\UserFailurePattern;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DailyReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $cutoff = Carbon::now()->subDay();
        $games = $user->games()
            ->whereNotNull('played_at')
            ->where('played_at', '>=', $cutoff)
            ->orderByDesc('played_at')
            ->limit(8)
            ->get()
            ->map(fn ($g) => [
                'id' => $g->id,
                'opponent' => $g->user_color === 'white' ? $g->black_username : $g->white_username,
                'result' => $g->result,
                'user_color' => $g->user_color,
                'opening_name' => $g->opening_name,
                'eco_code' => $g->eco_code,
                'user_accuracy' => $g->user_color === 'white' ? $g->white_accuracy : $g->black_accuracy,
                'analyzed' => (bool) $g->analyzed_at,
                'played_at' => $g->played_at?->toIso8601String(),
            ]);

        $topPatterns = UserFailurePattern::where('user_id', $user->id)
            ->orderByDesc('occurrence_count')
            ->limit(3)
            ->get()
            ->map(fn (UserFailurePattern $p) => [
                'id' => $p->id,
                'kind' => $p->pattern_kind,
                'phase' => $p->phase,
                'opening_eco' => $p->opening_eco,
                'occurrences' => $p->occurrence_count,
                'last_seen_at' => $p->last_seen_at?->toIso8601String(),
                'sample' => $p->sample_position_json,
            ]);

        // Weekly accuracy delta — current 7-day vs prior 7-day average
        $current = $this->avgAccuracy($user, Carbon::now()->subDays(7), Carbon::now());
        $prior = $this->avgAccuracy($user, Carbon::now()->subDays(14), Carbon::now()->subDays(7));

        $reviewQueue = $this->buildReviewQueue($user);

        return response()->json([
            'todays_focus' => $this->buildFocusCallout($topPatterns->all()),
            'yesterdays_games' => $games,
            'top_patterns' => $topPatterns,
            'weekly_accuracy' => [
                'current' => $current,
                'prior' => $prior,
                'delta' => $current !== null && $prior !== null ? round($current - $prior, 2) : null,
            ],
            'review_queue' => $reviewQueue,
            'review_due_total' => collect($reviewQueue)->where('due', true)->sum('count'),
        ]);
    }

    /**
     * The unified "due today" deck — every spaced-repetition / mistake-driven
     * training bucket in one prioritised list, so the user sees the single next
     * thing to do instead of hunting across /learn, /drill, /puzzles, /homework.
     * Each bucket deep-links to the existing solver for that kind. Buckets with
     * nothing due are dropped; daily tactics is always available.
     *
     * @return list<array{kind:string,label:string,description:string,count:int,route:string,due:bool}>
     */
    private function buildReviewQueue($user): array
    {
        $today = Carbon::today();

        $dueOpenings = OpeningRepetition::where('user_id', $user->id)
            ->where('due_at', '<=', Carbon::now())
            ->count();

        $duePatterns = PatternReviewSchedule::where('user_id', $user->id)
            ->whereDate('due_at', '<=', $today)
            ->count();

        $pendingDrills = DrillQueue::where('user_id', $user->id)
            ->whereNull('solved_at')
            ->count();

        $mistakeSet = PuzzleSet::where('user_id', $user->id)->where('is_auto', true)->first();
        $mistakeCount = $mistakeSet ? $mistakeSet->puzzles()->count() : 0;

        $buckets = [
            [
                'kind' => 'openings',
                'label' => 'Opening reviews',
                'description' => 'Repertoire moves due for spaced repetition',
                'count' => $dueOpenings,
                'route' => '/learn',
                'due' => true,
            ],
            [
                'kind' => 'critical',
                'label' => 'Critical moments',
                'description' => 'Positions where a game slipped away',
                'count' => $pendingDrills,
                'route' => '/drill',
                'due' => true,
            ],
            [
                'kind' => 'patterns',
                'label' => 'Weakness drills',
                'description' => 'Your recurring mistake patterns, on an SR schedule',
                'count' => $duePatterns,
                'route' => '/homework',
                'due' => true,
            ],
            [
                'kind' => 'mistakes',
                'label' => 'Mistake puzzles',
                'description' => 'Puzzles built from your own blunders',
                'count' => $mistakeCount,
                'route' => '/puzzles/sets',
                'due' => true,
            ],
        ];

        // Drop empty due-buckets, then always offer daily tactics as the floor.
        $queue = array_values(array_filter($buckets, fn ($b) => $b['count'] > 0));

        $queue[] = [
            'kind' => 'tactics',
            'label' => 'Daily tactics',
            'description' => 'A fresh set matched to your puzzle rating',
            'count' => 5,
            'route' => '/puzzles',
            'due' => false,
        ];

        return $queue;
    }

    private function avgAccuracy($user, Carbon $from, Carbon $to): ?float
    {
        // filter() without a callback drops 0.0 as well as null — a beginner
        // who legitimately scored 0% on a game would silently bias the avg
        // upward. Filter on null explicitly.
        $val = $user->games()
            ->whereBetween('played_at', [$from, $to])
            ->whereNotNull('analyzed_at')
            ->get()
            ->map(fn ($g) => $g->user_color === 'white' ? $g->white_accuracy : $g->black_accuracy)
            ->filter(fn ($v) => $v !== null)
            ->avg();
        return $val !== null ? round((float) $val, 2) : null;
    }

    private function buildFocusCallout(array $patterns): string
    {
        if (empty($patterns)) {
            return 'Sync some games and we\'ll find your top weakness to drill.';
        }
        $top = $patterns[0];
        $kind = str_replace('_', ' ', $top['kind']);
        $phase = $top['phase'] ?? 'middlegame';
        return "Today, focus on {$kind} in the {$phase} — it cost you {$top['occurrences']} games.";
    }
}
