<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\MoveAnalysis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GameController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $request->user()->games()->orderByDesc('played_at');

        if ($request->filled('time_class')) {
            $query->where('time_class', $request->time_class);
        }
        if ($request->filled('result')) {
            $query->where('result', $request->result);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('white_username', 'like', "%{$s}%")
                  ->orWhere('black_username', 'like', "%{$s}%")
                  ->orWhere('opening_name', 'like', "%{$s}%");
            });
        }

        $games = $query->paginate(20);

        return response()->json([
            'data' => $games->map(fn($g) => $this->formatGame($g)),
            'meta' => [
                'total'        => $games->total(),
                'current_page' => $games->currentPage(),
                'last_page'    => $games->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, Game $game): JsonResponse
    {
        if ($game->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $game->load('moveAnalyses');

        return response()->json([
            'game'  => $this->formatGame($game),
            'moves' => $game->moveAnalyses->map(fn($m) => [
                'move_number'    => $m->move_number,
                'color'          => $m->color,
                'move_san'       => $m->move_san,
                'best_move_san'  => $m->best_move_san,
                'classification' => $m->classification,
                'cp_loss'        => $m->cp_loss,
                'eval_before'    => $m->eval_before,
                'eval_after'     => $m->eval_after,
                'best_move_eval' => $m->best_move_eval,
                'explanation'    => $m->explanation,
                'best_move_line' => $m->best_move_line,
            ]),
        ]);
    }

    public function saveAnalysis(Request $request, Game $game): JsonResponse
    {
        if ($game->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'moves'          => 'required|array',
            'moves.*.move_number'    => 'required|integer',
            'moves.*.color'          => 'required|in:white,black',
            'moves.*.move_san'       => 'required|string',
            'moves.*.best_move_san'  => 'nullable|string',
            'moves.*.classification' => 'nullable|string',
            'moves.*.cp_loss'        => 'nullable|integer',
            'moves.*.eval_before'    => 'nullable|integer',
            'moves.*.eval_after'     => 'nullable|integer',
            'moves.*.best_move_eval' => 'nullable|integer',
            'moves.*.explanation'    => 'nullable|string',
            'moves.*.best_move_line' => 'nullable|array',
            'white_accuracy'         => 'nullable|numeric',
            'black_accuracy'         => 'nullable|numeric',
        ]);

        // Delete old analysis if re-analyzing
        $game->moveAnalyses()->delete();

        foreach ($data['moves'] as $m) {
            MoveAnalysis::create(array_merge(['game_id' => $game->id], $m));
        }

        $game->update([
            'white_accuracy' => $data['white_accuracy'] ?? null,
            'black_accuracy' => $data['black_accuracy'] ?? null,
            'analyzed_at'    => now(),
        ]);

        return response()->json(['message' => 'Analysis saved.']);
    }

    private function formatGame(Game $game): array
    {
        $user = request()->user();
        return [
            'id'                 => $game->id,
            'chess_com_game_id'  => $game->chess_com_game_id,
            'white_username'     => $game->white_username,
            'black_username'     => $game->black_username,
            'white_rating'       => $game->white_rating,
            'black_rating'       => $game->black_rating,
            'user_color'         => $game->user_color,
            'opponent'           => $game->user_color === 'white' ? $game->black_username : $game->white_username,
            'opponent_rating'    => $game->user_color === 'white' ? $game->black_rating : $game->white_rating,
            'result'             => $game->result,
            'time_class'         => $game->time_class,
            'time_control'       => $game->time_control,
            'opening_name'       => $game->opening_name,
            'eco_code'           => $game->eco_code,
            'white_accuracy'     => $game->white_accuracy !== null ? (float) $game->white_accuracy : null,
            'black_accuracy'     => $game->black_accuracy !== null ? (float) $game->black_accuracy : null,
            'user_accuracy'      => $game->user_color === 'white'
                ? ($game->white_accuracy !== null ? (float) $game->white_accuracy : null)
                : ($game->black_accuracy !== null ? (float) $game->black_accuracy : null),
            'move_count'         => $game->move_count,
            'played_at'          => $game->played_at?->toISOString(),
            'analyzed_at'        => $game->analyzed_at?->toISOString(),
            'pgn'                => $game->pgn,
        ];
    }
}
