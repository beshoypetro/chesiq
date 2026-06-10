<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CoachSession;
use App\Services\CoachSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The coach-led daily session (CHESSIQ_V3_PLAN §2). The script is composed
 * once per day and persisted so a mid-session refresh resumes the same plan;
 * completion is recorded so streaks are real.
 */
class CoachSessionController extends Controller
{
    public function today(Request $request, CoachSessionService $sessions): JsonResponse
    {
        $user = $request->user();

        // whereDate, not firstOrCreate: the `date` cast stores midnight
        // timestamps, so a bare equality lookup misses and double-inserts.
        $session = CoachSession::where('user_id', $user->id)
            ->whereDate('date', Carbon::today())
            ->first();
        if (! $session) {
            try {
                $session = CoachSession::create([
                    'user_id' => $user->id,
                    'date' => Carbon::today()->toDateString(),
                    'steps_json' => $sessions->buildToday($user)['steps'],
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                $session = CoachSession::where('user_id', $user->id)
                    ->whereDate('date', Carbon::today())
                    ->firstOrFail();
            }
        }

        return response()->json([
            'date' => $session->date->toDateString(),
            'steps' => $session->steps_json,
            'completed_at' => $session->completed_at?->toIso8601String(),
            'streak' => $this->streak($user->id),
        ]);
    }

    public function complete(Request $request): JsonResponse
    {
        $session = CoachSession::where('user_id', $request->user()->id)
            ->whereDate('date', Carbon::today())
            ->firstOrFail();

        if (! $session->completed_at) {
            $session->completed_at = now();
            $session->save();
        }

        return response()->json([
            'completed_at' => $session->completed_at->toIso8601String(),
            'streak' => $this->streak($request->user()->id),
        ]);
    }

    /** Consecutive completed-session days ending today (or yesterday, pre-completion). */
    private function streak(int $userId): int
    {
        $dates = CoachSession::where('user_id', $userId)
            ->whereNotNull('completed_at')
            ->orderByDesc('date')
            ->limit(60)
            ->pluck('date')
            ->map(fn ($d) => $d->toDateString())
            ->all();

        $streak = 0;
        $cursor = Carbon::today();
        // A streak still counts before today's session is finished.
        if (! in_array($cursor->toDateString(), $dates, true)) {
            $cursor = $cursor->subDay();
        }
        while (in_array($cursor->toDateString(), $dates, true)) {
            $streak++;
            $cursor = $cursor->subDay();
        }

        return $streak;
    }
}
