<?php

namespace App\Http\Controllers\Api;

use App\Models\LineProgress;
use App\Models\MoveRationale;
use App\Models\OpeningLine;
use App\Models\TrainingSession;
use App\Services\GeminiCoachService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class TrainerController extends Controller
{
    private const DAILY_COACH_CAP = 200;

    public function plan(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $now    = now();

        $progress = LineProgress::where('user_id', $userId)
            ->orderByRaw('CASE WHEN next_due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('next_due_at')
            ->get()
            ->map(fn ($p) => [
                'line_id'      => $p->line_id,
                'mastery'      => round($p->mastery, 2),
                'attempts'     => $p->attempts,
                'correct'      => $p->correct,
                'last_seen_at' => $p->last_seen_at?->toISOString(),
                'next_due_at'  => $p->next_due_at?->toISOString(),
                'is_due'       => $p->next_due_at === null || $p->next_due_at->lte($now),
            ]);

        $rationaledLineIds = MoveRationale::select('line_id')->distinct()->pluck('line_id');
        $lastSession = TrainingSession::where('user_id', $userId)
            ->whereNotNull('ended_at')
            ->latest('ended_at')
            ->first();

        return response()->json([
            'progress'              => $progress,
            'rationaled_line_ids'   => $rationaledLineIds,
            'last_session'          => $lastSession ? [
                'ended_at'       => $lastSession->ended_at?->toISOString(),
                'correct_moves'  => $lastSession->correct_moves,
                'total_moves'    => $lastSession->total_moves,
                'lines_covered'  => $lastSession->lines_covered,
                'lines_mastered' => $lastSession->lines_mastered,
                'summary_text'   => $lastSession->summary_text,
            ] : null,
        ]);
    }

    public function line(Request $request, string $lineId, GeminiCoachService $coach): JsonResponse
    {
        $catalog = OpeningLine::where('line_id', $lineId)->first();

        // Lazy-generate rationales for any student-side moves that don't have one yet.
        // Runs only when the line is in the catalog AND the Gemini key is configured.
        // Fails safely (no rationales returned) if the API call misses.
        if ($catalog && config('services.gemini.key')) {
            $moves = is_array($catalog->moves) ? $catalog->moves : [];
            $studentIndices = $this->studentMoveIndices($catalog->color, count($moves));
            $existingIndices = MoveRationale::where('line_id', $lineId)
                ->pluck('move_index')
                ->map(fn ($v) => (int) $v)
                ->all();
            $missing = array_values(array_diff($studentIndices, $existingIndices));

            if (!empty($missing)) {
                $generated = $coach->generateLineRationales($catalog, $missing);
                foreach ($generated as $g) {
                    MoveRationale::updateOrCreate(
                        ['line_id' => $lineId, 'move_index' => $g['move_index']],
                        ['san' => $g['san'], 'text' => $g['text'], 'source' => 'generated']
                    );
                }
            }
        }

        $rationales = MoveRationale::where('line_id', $lineId)
            ->orderBy('move_index')
            ->get(['move_index', 'san', 'text', 'source']);

        $userId = $request->user()->id;
        $progress = LineProgress::where('user_id', $userId)
            ->where('line_id', $lineId)
            ->first();

        return response()->json([
            'line_id'    => $lineId,
            'rationales' => $rationales,
            'progress'   => $progress ? [
                'mastery'      => round($progress->mastery, 2),
                'attempts'     => $progress->attempts,
                'correct'      => $progress->correct,
                'last_seen_at' => $progress->last_seen_at?->toISOString(),
                'next_due_at'  => $progress->next_due_at?->toISOString(),
            ] : null,
        ]);
    }

    /**
     * Indices in a line where the student is the one making the move.
     * White openings → even indices. Black openings → odd. 'both' → all.
     */
    private function studentMoveIndices(string $color, int $lineLength): array
    {
        $out = [];
        for ($i = 0; $i < $lineLength; $i++) {
            $isStudent = match ($color) {
                'white' => $i % 2 === 0,
                'black' => $i % 2 === 1,
                default => true,
            };
            if ($isStudent) $out[] = $i;
        }
        return $out;
    }

    public function attempt(Request $request): JsonResponse
    {
        $v = $request->validate([
            'line_id'    => 'required|string|max:64',
            'move_index' => 'required|integer|min:0|max:200',
            'correct'    => 'required|boolean',
        ]);

        // If we have a catalog entry for this line, enforce move_index is within bounds.
        // Lines without a catalog row are still accepted (backwards compat with frontend-only openings).
        $catalog = OpeningLine::where('line_id', $v['line_id'])->first();
        if ($catalog && $v['move_index'] >= count($catalog->moves)) {
            return response()->json([
                'message' => 'move_index out of range for this line.',
                'errors'  => ['move_index' => ['Move index exceeds line length.']],
            ], 422);
        }

        $userId = $request->user()->id;

        $progress = LineProgress::firstOrCreate(
            ['user_id' => $userId, 'line_id' => $v['line_id']],
            ['mastery' => 0]
        );

        $progress->attempts = $progress->attempts + 1;
        if ($v['correct']) {
            $progress->correct = $progress->correct + 1;
        }
        $progress->last_seen_at = now();
        $progress->save();

        return response()->json(['ok' => true]);
    }

    public function completeLine(Request $request, string $lineId): JsonResponse
    {
        $v = $request->validate([
            'correct'    => 'required|integer|min:0|max:100|lte:total',
            'total'      => 'required|integer|min:1|max:100',
            'session_id' => 'nullable|integer',
        ]);

        // If we have a catalog entry for this line, enforce total doesn't exceed the line's length.
        $catalog = OpeningLine::where('line_id', $lineId)->first();
        if ($catalog && $v['total'] > count($catalog->moves)) {
            return response()->json([
                'message' => 'total exceeds the line length on record.',
                'errors'  => ['total' => ['Total cannot exceed the number of moves in this line.']],
            ], 422);
        }

        $userId   = $request->user()->id;
        $accuracy = $v['total'] > 0 ? $v['correct'] / $v['total'] : 0;

        $progress = LineProgress::firstOrCreate(
            ['user_id' => $userId, 'line_id' => $lineId],
            ['mastery' => 0]
        );

        $delta    = ($accuracy - 0.5) * 0.4;
        $mastery  = max(0, min(1, $progress->mastery + $delta));
        $interval = (int) ceil($mastery * 14);

        $progress->mastery      = $mastery;
        $progress->last_seen_at = now();
        $progress->next_due_at  = now()->addDays(max(1, $interval));
        $progress->save();

        if (! empty($v['session_id'])) {
            $session = TrainingSession::where('user_id', $userId)->find($v['session_id']);
            if ($session) {
                $session->lines_covered = $session->lines_covered + 1;
                if ($mastery >= 0.85) {
                    $session->lines_mastered = $session->lines_mastered + 1;
                }
                $session->correct_moves = $session->correct_moves + $v['correct'];
                $session->total_moves   = $session->total_moves + $v['total'];
                $session->save();
            }
        }

        return response()->json([
            'mastery'     => round($mastery, 2),
            'next_due_at' => $progress->next_due_at?->toISOString(),
            'mastered'    => $mastery >= 0.85,
        ]);
    }

    public function coach(Request $request, GeminiCoachService $coach): JsonResponse
    {
        $v = $request->validate([
            'line_id'       => 'required|string|max:64',
            'line_name'     => 'nullable|string|max:80',
            'opening_name'  => 'nullable|string|max:80',
            'fen'           => 'required|string|max:100',
            'attempted_san' => 'required|string|max:20',
            'expected_san'  => 'nullable|string|max:20',
            'move_index'    => 'required|integer|min:0',
            'move_number'   => 'nullable|integer',
            'color'         => 'nullable|string|in:white,black',
            'correct'       => 'required|boolean',
        ]);

        $userId = $request->user()->id;
        $dayKey = "coach_daily:{$userId}:" . now()->format('Y-m-d');
        $count  = (int) Cache::get($dayKey, 0);
        if ($count >= self::DAILY_COACH_CAP) {
            return response()->json([
                'text'     => "We've hit today's coaching limit — but keep playing, I'll be back tomorrow.",
                'quota'    => ['used' => $count, 'cap' => self::DAILY_COACH_CAP],
                'rate_limit' => true,
            ], 429);
        }
        Cache::put($dayKey, $count + 1, now()->endOfDay());

        $text = $coach->coach($v);

        return response()->json(['text' => $text]);
    }

    public function startSession(Request $request): JsonResponse
    {
        $session = TrainingSession::create([
            'user_id'    => $request->user()->id,
            'started_at' => now(),
        ]);

        return response()->json(['session_id' => $session->id]);
    }

    public function endSession(Request $request, int $id): JsonResponse
    {
        $session = TrainingSession::where('user_id', $request->user()->id)->findOrFail($id);

        $session->ended_at = now();
        $accuracy = $session->total_moves > 0
            ? round($session->correct_moves / $session->total_moves * 100)
            : 0;

        $session->summary_text = sprintf(
            'Covered %d line%s, mastered %d. Accuracy %d%% (%d/%d moves).',
            $session->lines_covered,
            $session->lines_covered === 1 ? '' : 's',
            $session->lines_mastered,
            $accuracy,
            $session->correct_moves,
            $session->total_moves
        );
        $session->save();

        return response()->json([
            'session_id'     => $session->id,
            'ended_at'       => $session->ended_at?->toISOString(),
            'lines_covered'  => $session->lines_covered,
            'lines_mastered' => $session->lines_mastered,
            'correct_moves'  => $session->correct_moves,
            'total_moves'    => $session->total_moves,
            'accuracy'       => $accuracy,
            'summary_text'   => $session->summary_text,
        ]);
    }
}
