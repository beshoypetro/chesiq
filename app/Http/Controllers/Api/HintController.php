<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GeminiCoachService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class HintController extends Controller
{
    private const MAX_HINTS_PER_GAME = 5;

    public function hint(Request $request, GeminiCoachService $coach): JsonResponse
    {
        $validated = $request->validate([
            'fen'     => 'required|string|max:100',
            'history' => 'nullable|array|max:200',
            // GAME_REVIEW_REFACTOR §5 — voice a specific trainer per request.
            'trainer_id' => 'nullable|string|in:'.implode(',', array_keys(config('trainers', []))),
        ]);

        $user = $request->user();

        // Rate-limit: max 5 hints per game (keyed on starting position)
        $gameKey = md5($validated['fen'] . implode(',', $validated['history'] ?? []));
        $hintKey = "hints:{$user->id}:{$gameKey}";
        $count   = (int) Cache::get($hintKey, 0);

        if ($count >= self::MAX_HINTS_PER_GAME) {
            return response()->json([
                'tip'        => null,
                'rate_limit' => true,
                'message'    => 'Maximum hints reached for this game. Think it through!',
            ], 429);
        }

        Cache::put($hintKey, $count + 1, now()->addHours(6));

        // Build context for Gemini — ask for a strategic goal hint (not the best move)
        $history = implode(', ', array_slice($validated['history'] ?? [], -6));
        $prompt  = "Position FEN: {$validated['fen']}. Recent moves: {$history}. Give me ONE concrete strategic goal for this position in one sentence — do NOT reveal the best move, just the idea.";

        try {
            $tip = $coach->hint($prompt, $user->trainerPersona($validated['trainer_id'] ?? null));
        } catch (\Throwable) {
            $tip = 'Focus on piece activity and king safety.';
        }

        return response()->json([
            'tip'       => $tip,
            'hints_used' => $count + 1,
            'hints_max'  => self::MAX_HINTS_PER_GAME,
        ]);
    }
}
