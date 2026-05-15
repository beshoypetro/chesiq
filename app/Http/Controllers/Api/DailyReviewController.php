<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

        return response()->json([
            'todays_focus' => $this->buildFocusCallout($topPatterns->all()),
            'yesterdays_games' => $games,
            'top_patterns' => $topPatterns,
            'weekly_accuracy' => [
                'current' => $current,
                'prior' => $prior,
                'delta' => $current !== null && $prior !== null ? round($current - $prior, 2) : null,
            ],
        ]);
    }

    private function avgAccuracy($user, Carbon $from, Carbon $to): ?float
    {
        $val = $user->games()
            ->whereBetween('played_at', [$from, $to])
            ->whereNotNull('analyzed_at')
            ->get()
            ->map(fn ($g) => $g->user_color === 'white' ? $g->white_accuracy : $g->black_accuracy)
            ->filter()
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
