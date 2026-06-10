<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PatternReviewSchedule;
use App\Services\HomeworkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class HomeworkController extends Controller
{
    /**
     * SM-2 spaced repetition extended to failure patterns. Returns a daily
     * homework set: 10 themed puzzles, 1 lesson to review (matched to the
     * user's weakest pattern / level), 1 archive game to re-study.
     * Composition lives in HomeworkService so the coach session reuses it.
     */
    public function today(Request $request, HomeworkService $homework): JsonResponse
    {
        return response()->json($homework->today($request->user()));
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
