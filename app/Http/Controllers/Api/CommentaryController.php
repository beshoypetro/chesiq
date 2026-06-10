<?php

namespace App\Http\Controllers\Api;

use App\Services\CoachContextService;
use App\Services\GeminiCoachService;
use App\Services\TacticalMotifService;
use App\Services\TablebaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;

class CommentaryController extends Controller
{
    private const DAILY_COMMENTARY_CAP = 400;

    public function generate(
        Request $request,
        GeminiCoachService $coach,
        CoachContextService $ctxSvc,
        TacticalMotifService $motifs,
        TablebaseService $tablebase,
    ): JsonResponse {
        $validated = $request->validate([
            'san' => 'required|string|max:20',
            'classification' => 'required|string|max:20',
            'cp_loss' => 'nullable|numeric',
            'eval_before' => 'nullable|numeric',
            'eval_after' => 'nullable|numeric',
            'best_move_san' => 'nullable|string|max:20',
            'move_number' => 'nullable|integer',
            'player_color' => 'nullable|string|max:10',
            'opening_name' => 'nullable|string|max:80',
            // Position + engine context — enables deeper reasoning
            'fen_before' => 'nullable|string|max:100',
            'fen_after' => 'nullable|string|max:100',
            'prev_san' => 'nullable|string|max:20',
            'pv' => 'nullable|array|max:12',
            'pv.*' => 'string|max:20',
            // Per-game context lets us detect repertoire deviation. Optional
            // for backwards-compat with older clients.
            'game_id' => 'nullable|integer',
            // T2.6: Top-N candidate moves with evals from the local engine.
            'candidate_moves' => 'nullable|array|max:5',
            'candidate_moves.*.san' => 'required_with:candidate_moves|string|max:20',
            'candidate_moves.*.eval' => 'required_with:candidate_moves|numeric',
            // GAME_REVIEW_REFACTOR §5: optional per-request trainer override so
            // callers (e.g. the game-review walkthrough) can voice a specific
            // character without changing the user's saved selection.
            'trainer_id' => 'nullable|string|in:'.implode(',', array_keys(config('trainers', []))),
        ]);

        $user = $request->user();
        $userId = $user->id;
        $dayKey = "commentary_daily:{$userId}:".now()->format('Y-m-d');
        $count = (int) Cache::get($dayKey, 0);
        if ($count >= self::DAILY_COMMENTARY_CAP) {
            return response()->json([
                'commentary' => '',
                'rate_limit' => true,
            ], 429);
        }
        Cache::put($dayKey, $count + 1, now()->endOfDay());

        // Personalization (cacheable buckets)
        $stable = $ctxSvc->stableUserContext($user);

        // Repertoire deviation: is THIS exact move where the user left book?
        // ply index is 0-based, half-moves. White's move N → ply 2(N-1); Black's → 2(N-1)+1.
        $deviationHere = false;
        if (! empty($validated['game_id']) && ! empty($validated['move_number']) && ! empty($validated['player_color'])) {
            $devPly = $ctxSvc->repertoireDeviationPly((int) $validated['game_id']);
            if ($devPly !== null) {
                $thisPly = (((int) $validated['move_number']) - 1) * 2 + ($validated['player_color'] === 'black' ? 1 : 0);
                $deviationHere = $thisPly === $devPly;
            }
        }

        // Masters popularity for book moves only — keeps the API call rate
        // low and only uses it where it'll affect the commentary anyway.
        $popularity = null;
        if (
            strtolower((string) $validated['classification']) === 'book'
            && ! empty($validated['fen_before'])
        ) {
            $popularity = $ctxSvc->mastersPopularity($validated['fen_before'], $validated['san']);
        }

        // Tactical motifs detected on the FEN — feeds Gemini structured facts
        // so it stops inventing pins/forks that aren't there.
        $motifList = ! empty($validated['fen_before'])
            ? $motifs->detect($validated['fen_before'], $validated['fen_after'] ?? null, $validated['san'])
            : [];

        // Tablebase note for ≤7 piece endgames.
        $tbNote = ! empty($validated['fen_before'])
            ? $tablebase->note($validated['fen_before'])
            : null;

        $payload = $validated + $stable + [
            'repertoire_deviation_here' => $deviationHere,
            'masters_popularity_pct' => $popularity,
            'tactical_motifs' => $motifList,
            'tablebase_note' => $tbNote,
            '_persona' => $user->trainerPersona($validated['trainer_id'] ?? null),
        ];

        return response()->json([
            'commentary' => $coach->commentary($payload),
        ]);
    }
}
