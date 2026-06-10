<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CoachCache;
use App\Models\Game;
use App\Models\UserFailurePattern;
use App\Services\GeminiCoachService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ChessDnaController extends Controller
{
    public function dna(Request $request): JsonResponse
    {
        $user = $request->user();

        $openings = $user->games()
            ->whereNotNull('eco_code')
            ->selectRaw('eco_code, opening_name, COUNT(*) as games_count, ' .
                "SUM(CASE WHEN result = 'win' THEN 1 ELSE 0 END) as wins, " .
                "SUM(CASE WHEN result = 'loss' THEN 1 ELSE 0 END) as losses, " .
                "SUM(CASE WHEN result = 'draw' THEN 1 ELSE 0 END) as draws, " .
                'AVG(CASE WHEN user_color = ' . "'white'" . ' THEN white_accuracy ELSE black_accuracy END) as avg_accuracy')
            ->groupBy('eco_code', 'opening_name')
            ->orderByDesc('games_count')
            ->limit(20)
            ->get()
            ->map(fn ($r) => [
                'eco' => $r->eco_code,
                'name' => $r->opening_name,
                'games' => (int) $r->games_count,
                'wins' => (int) $r->wins,
                'losses' => (int) $r->losses,
                'draws' => (int) $r->draws,
                'win_rate' => $r->games_count > 0 ? round($r->wins / $r->games_count, 2) : 0,
                'avg_accuracy' => $r->avg_accuracy !== null ? round((float) $r->avg_accuracy, 1) : null,
            ]);

        $patterns = UserFailurePattern::where('user_id', $user->id)
            ->orderByDesc('occurrence_count')
            ->limit(10)
            ->get()
            ->map(fn ($p) => [
                'kind' => $p->pattern_kind,
                'phase' => $p->phase,
                'opening_eco' => $p->opening_eco,
                'occurrences' => $p->occurrence_count,
                'last_seen_at' => $p->last_seen_at?->toIso8601String(),
            ]);

        $rating = $user->games()
            ->whereNotNull('played_at')
            ->whereNotNull('analyzed_at')
            ->where('played_at', '>=', Carbon::now()->subMonths(3))
            ->orderBy('played_at')
            ->get(['played_at', 'user_color', 'white_accuracy', 'black_accuracy', 'white_rating', 'black_rating'])
            ->map(fn ($g) => [
                'played_at' => $g->played_at?->toIso8601String(),
                'accuracy' => $g->user_color === 'white' ? $g->white_accuracy : $g->black_accuracy,
                'opp_rating' => $g->user_color === 'white' ? $g->black_rating : $g->white_rating,
            ]);

        return response()->json([
            'openings' => $openings,
            'patterns' => $patterns,
            'rating_trajectory' => $rating,
            'style_profile' => $user->style_profile_json,
            'placement' => [
                'elo' => $user->placement_elo,
                'track' => $user->placement_track,
                'completed_at' => $user->placement_completed_at,
            ],
        ]);
    }

    public function opening(Request $request, string $eco, GeminiCoachService $coach): JsonResponse
    {
        $user = $request->user();

        $games = $user->games()->where('eco_code', $eco)->get();
        if ($games->isEmpty()) {
            return response()->json(['message' => 'No games in this opening.'], 404);
        }

        $wins = $games->where('result', 'win')->count();
        $losses = $games->where('result', 'loss')->count();
        $draws = $games->where('result', 'draw')->count();
        $accs = $games->map(fn ($g) => $g->user_color === 'white' ? $g->white_accuracy : $g->black_accuracy)->filter();
        $avgAccuracy = $accs->isNotEmpty() ? round((float) $accs->avg(), 1) : null;

        // Common mistakes — top 5 highest-cp_loss user moves in this opening
        $gameIds = $games->pluck('id');
        $mistakes = \App\Models\MoveAnalysis::whereIn('game_id', $gameIds)
            ->whereIn('classification', ['blunder', 'mistake'])
            ->orderByDesc('cp_loss')
            ->limit(5)
            ->get(['game_id', 'move_number', 'color', 'move_san', 'best_move_san', 'cp_loss'])
            ->map(fn ($m) => [
                'game_id' => $m->game_id,
                'move_number' => $m->move_number,
                'move_san' => $m->move_san,
                'best_san' => $m->best_move_san,
                'cp_loss' => $m->cp_loss,
            ]);

        // 24h cached AI advice
        $cacheKey = "dna_opening_advice|{$user->id}|{$eco}|{$games->count()}";
        $cached = CoachCache::where('cache_key', $cacheKey)
            ->where('updated_at', '>=', Carbon::now()->subDay())
            ->first();

        if ($cached) {
            $advice = $cached->response_text;
        } else {
            $prompt = "Opening ECO {$eco}, name " . ($games->first()->opening_name ?? 'unknown') .
                ". Player record: {$wins}W / {$losses}L / {$draws}D over " . $games->count() . " games. " .
                "Average accuracy " . ($avgAccuracy ?? 'unknown') . "%. " .
                "Give one concise paragraph of advice for this player in this opening — what to study, what to avoid.";
            try {
                $advice = $coach->hint($prompt, $user->trainerPersona());
                if ($advice) {
                    CoachCache::updateOrCreate(
                        ['cache_key' => $cacheKey],
                        ['response_text' => $advice]
                    );
                }
            } catch (\Throwable) {
                $advice = 'Study the main line and watch your tactical alertness in the middlegame.';
            }
        }

        return response()->json([
            'eco' => $eco,
            'name' => $games->first()->opening_name,
            'record' => ['w' => $wins, 'l' => $losses, 'd' => $draws],
            'avg_accuracy' => $avgAccuracy,
            'common_mistakes' => $mistakes,
            'advice' => $advice,
        ]);
    }
}
