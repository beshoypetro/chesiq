<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\PatternReviewSchedule;
use App\Models\Puzzle;
use App\Models\UserFailurePattern;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class HomeworkController extends Controller
{
    /**
     * SM-2 spaced repetition extended to failure patterns. Returns a daily
     * homework set: 10 themed puzzles, 1 lesson review (placeholder until
     * activities exist), 1 archive game to re-study.
     */
    public function today(Request $request): JsonResponse
    {
        $user = $request->user();

        $duePatterns = PatternReviewSchedule::where('user_id', $user->id)
            ->whereDate('due_at', '<=', Carbon::today())
            ->with('failurePattern')
            ->orderBy('due_at')
            ->limit(3)
            ->get();

        // If user has zero schedule rows yet, seed from existing failure patterns.
        if ($duePatterns->isEmpty()) {
            $patterns = UserFailurePattern::where('user_id', $user->id)
                ->orderByDesc('occurrence_count')
                ->limit(3)
                ->get();
            foreach ($patterns as $p) {
                PatternReviewSchedule::firstOrCreate(
                    ['user_id' => $user->id, 'failure_pattern_id' => $p->id],
                    ['due_at' => Carbon::today()],
                );
            }
            $duePatterns = PatternReviewSchedule::where('user_id', $user->id)
                ->whereDate('due_at', '<=', Carbon::today())
                ->with('failurePattern')
                ->limit(3)
                ->get();
        }

        $puzzles = Puzzle::query()
            ->inRandomOrder()
            ->limit(10)
            ->get(['id', 'fen', 'moves', 'rating', 'themes']);

        $archiveGame = Game::where('user_id', $user->id)
            ->whereNotNull('analyzed_at')
            ->orderByDesc('played_at')
            ->skip(7)
            ->first(['id', 'opening_name', 'eco_code', 'result', 'played_at']);

        return response()->json([
            'pattern_reviews' => $duePatterns,
            'puzzles' => $puzzles,
            'lesson_review' => null,
            'archive_game' => $archiveGame,
        ]);
    }

    public function reviewPattern(Request $request, int $scheduleId): JsonResponse
    {
        $data = $request->validate(['quality' => 'required|integer|min:0|max:5']);
        $row = PatternReviewSchedule::where('id', $scheduleId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        // SM-2 update
        $q = (int) $data['quality'];
        if ($q < 3) {
            $row->repetition_count = 0;
            $row->interval_days = 1;
        } else {
            $row->repetition_count++;
            $row->interval_days = match ($row->repetition_count) {
                1 => 1,
                2 => 6,
                default => (int) round($row->interval_days * $row->ease_factor),
            };
        }
        $row->ease_factor = max(1.3, $row->ease_factor + (0.1 - (5 - $q) * (0.08 + (5 - $q) * 0.02)));
        $row->due_at = Carbon::today()->addDays($row->interval_days);
        $row->last_reviewed_at = now();
        $row->save();

        return response()->json(['schedule' => $row]);
    }
}
