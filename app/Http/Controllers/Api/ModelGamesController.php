<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ModelGamesController extends Controller
{
    /**
     * GET /api/learn/lines/{lineId}/model-games — list model games for an opening line.
     */
    public function index(Request $request, string $lineId): JsonResponse
    {
        $rows = DB::table('opening_line_model_games')
            ->where('opening_line_id', $lineId)
            ->orderBy('position')
            ->get(['game_pgn', 'key_move_ply', 'idea_text', 'position']);

        return response()->json(['model_games' => $rows]);
    }

    /**
     * POST /api/learn/lines/{lineId}/model-games — (admin) add a model game to a line.
     */
    public function store(Request $request, string $lineId): JsonResponse
    {
        $data = $request->validate([
            'game_pgn'     => 'required|string',
            'key_move_ply' => 'nullable|integer',
            'idea_text'    => 'nullable|string|max:500',
        ]);

        $position = DB::table('opening_line_model_games')
            ->where('opening_line_id', $lineId)
            ->max('position') ?? -1;
        $position++;

        if ($position >= 3) {
            return response()->json(['message' => 'Maximum 3 model games per line.'], 422);
        }

        DB::table('opening_line_model_games')->insert([
            'opening_line_id' => $lineId,
            'game_pgn'        => $data['game_pgn'],
            'key_move_ply'    => $data['key_move_ply'] ?? null,
            'idea_text'       => $data['idea_text'] ?? null,
            'position'        => $position,
        ]);

        return response()->json(['message' => 'Model game added.', 'position' => $position], 201);
    }
}
