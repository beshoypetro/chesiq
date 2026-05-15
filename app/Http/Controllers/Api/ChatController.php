<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CoachUserMemory;
use App\Services\CoachContextService;
use App\Services\GeminiCoachService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * T3.9 — Conversational coach. Multi-turn dialogue grounded in the current
 * position and the user's profile. Ratelimited per-user-per-day so
 * unbounded chats don't burn Gemini quota.
 */
class ChatController extends Controller
{
    private const DAILY_TURN_CAP = 50;

    public function chat(
        Request $request,
        GeminiCoachService $coach,
        CoachContextService $ctxSvc,
    ): JsonResponse {
        $v = $request->validate([
            'messages' => 'required|array|min:1|max:50',
            'messages.*.role' => 'required|in:user,model',
            'messages.*.text' => 'required|string|max:4000',
            'fen' => 'nullable|string|max:100',
            'opening_name' => 'nullable|string|max:80',
            'eval_pawns' => 'nullable|numeric',
            'best_move_san' => 'nullable|string|max:20',
            'recent_moves' => 'nullable|array|max:20',
            'recent_moves.*' => 'string|max:20',
            'game_id' => 'nullable|integer',
        ]);

        $user = $request->user();
        $userId = $user->id;
        $dayKey = "chat_daily:{$userId}:".now()->format('Y-m-d');
        $count = (int) Cache::get($dayKey, 0);
        if ($count >= self::DAILY_TURN_CAP) {
            return response()->json([
                'reply' => "We've used up today's chat session. We'll pick up tomorrow with a fresh batch.",
                'turns_remaining' => 0,
                'rate_limit' => true,
            ], 429);
        }
        Cache::put($dayKey, $count + 1, now()->endOfDay());

        $stable = $ctxSvc->stableUserContext($user);
        $ephemeral = $ctxSvc->ephemeralUserContext($user);

        // T4.12 — pass long-term coach memory notes as a context hint.
        $memoryNotes = CoachUserMemory::where('user_id', $userId)
            ->orderByDesc('weight')
            ->limit(8)
            ->pluck('note')
            ->all();

        $reply = $coach->chat(
            $v
            + $stable
            + $ephemeral
            + ['memory_notes' => $memoryNotes]
        );

        return response()->json([
            'reply' => $reply,
            'turns_remaining' => max(0, self::DAILY_TURN_CAP - ($count + 1)),
        ]);
    }
}
