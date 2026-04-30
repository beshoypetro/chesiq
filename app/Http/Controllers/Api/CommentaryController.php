<?php

namespace App\Http\Controllers\Api;

use App\Services\GeminiCoachService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;

class CommentaryController extends Controller
{
    private const DAILY_COMMENTARY_CAP = 400;

    public function generate(Request $request, GeminiCoachService $coach): JsonResponse
    {
        $validated = $request->validate([
            'san'            => 'required|string|max:20',
            'classification' => 'required|string|max:20',
            'cp_loss'        => 'nullable|numeric',
            'eval_before'    => 'nullable|numeric',
            'eval_after'     => 'nullable|numeric',
            'best_move_san'  => 'nullable|string|max:20',
            'move_number'    => 'nullable|integer',
            'player_color'   => 'nullable|string|max:10',
            'opening_name'   => 'nullable|string|max:80',
            // Position + engine context — enables deeper reasoning
            'fen_before'     => 'nullable|string|max:100',
            'fen_after'      => 'nullable|string|max:100',
            'prev_san'       => 'nullable|string|max:20',
            'pv'             => 'nullable|array|max:12',
            'pv.*'           => 'string|max:20',
        ]);

        $userId = $request->user()->id;
        $dayKey = "commentary_daily:{$userId}:" . now()->format('Y-m-d');
        $count  = (int) Cache::get($dayKey, 0);
        if ($count >= self::DAILY_COMMENTARY_CAP) {
            return response()->json([
                'commentary' => '',
                'rate_limit' => true,
            ], 429);
        }
        Cache::put($dayKey, $count + 1, now()->endOfDay());

        return response()->json(['commentary' => $coach->commentary($validated)]);
    }
}
