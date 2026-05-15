<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OpeningRepetition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LearnController extends Controller
{
    public function due(Request $request): JsonResponse
    {
        $user = $request->user();

        $items = OpeningRepetition::where('user_id', $user->id)
            ->where(function ($q) {
                $q->whereNull('due_at')->orWhere('due_at', '<=', now());
            })
            ->orderBy('due_at')
            ->get();

        return response()->json([
            'items' => $items->map(fn ($i) => [
                'id' => $i->id,
                'opening_key' => $i->opening_key,
                'move_uci' => $i->move_uci,
                'ease_factor' => $i->ease_factor,
                'interval_days' => $i->interval_days,
                'due_at' => $i->due_at?->toISOString(),
                'repetitions' => $i->repetitions,
            ]),
            'count' => $items->count(),
        ]);
    }

    public function review(Request $request, int $moveId): JsonResponse
    {
        $data = $request->validate([
            'quality' => 'required|integer|min:0|max:5',
        ]);

        $user = $request->user();
        $item = OpeningRepetition::where('id', $moveId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $q = $data['quality'];

        // SM-2 algorithm
        $ef = $item->ease_factor + (0.1 - (5 - $q) * (0.08 + (5 - $q) * 0.02));
        $ef = max(1.3, $ef);

        if ($q < 3) {
            // Incorrect — reset to beginning
            $interval = 1;
            $reps = 0;
        } else {
            $reps = $item->repetitions + 1;
            // prevInterval is what the interval WILL be after the previous step completes.
            // On rep 1 the previous interval was 1; on rep 2 it becomes 6.
            // For rep 3+ we use the stored interval_days (already reflecting rep 2's 6-day value).
            $prevInterval = match (true) {
                $item->repetitions <= 1 => 1,
                $item->repetitions === 2 => 6,
                default => $item->interval_days,
            };
            $interval = match ($reps) {
                1 => 1,
                2 => 6,
                default => (int) round($prevInterval * $ef),
            };
        }

        $dueAt = now()->addDays($interval);

        $item->update([
            'ease_factor' => round($ef, 2),
            'interval_days' => $interval,
            'repetitions' => $reps,
            'due_at' => $dueAt,
        ]);

        return response()->json([
            'next_due' => $dueAt->toISOString(),
            'interval_days' => $interval,
        ]);
    }

    public function track(Request $request): JsonResponse
    {
        $data = $request->validate([
            'opening_key' => 'required|string|max:100',
            'move_uci' => 'required|string|max:10',
        ]);

        $user = $request->user();

        $item = OpeningRepetition::firstOrCreate(
            [
                'user_id' => $user->id,
                'opening_key' => $data['opening_key'],
                'move_uci' => $data['move_uci'],
            ],
            [
                'ease_factor' => 2.5,
                'interval_days' => 1,
                'due_at' => now(),
                'repetitions' => 0,
            ]
        );

        return response()->json(['id' => $item->id, 'created' => $item->wasRecentlyCreated]);
    }
}
