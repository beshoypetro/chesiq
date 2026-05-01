<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\GameAnnotation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnnotationController extends Controller
{
    public function index(Request $request, Game $game): JsonResponse
    {
        if ($game->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $annotations = GameAnnotation::where('game_id', $game->id)
            ->where('user_id', $request->user()->id)
            ->orderBy('move_ply')
            ->get();

        return response()->json([
            'annotations' => $annotations->map(fn ($a) => $this->format($a)),
        ]);
    }

    public function store(Request $request, Game $game): JsonResponse
    {
        if ($game->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'move_ply' => 'required|integer|min:0',
            'note' => 'nullable|string|max:2000',
            'arrows_json' => 'nullable|string',
            'highlights_json' => 'nullable|string',
        ]);

        $annotation = GameAnnotation::updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'game_id' => $game->id,
                'move_ply' => $data['move_ply'],
            ],
            [
                'note' => $data['note'] ?? null,
                'arrows_json' => $data['arrows_json'] ?? null,
                'highlights_json' => $data['highlights_json'] ?? null,
                'updated_at' => now(),
            ]
        );

        return response()->json(['annotation' => $this->format($annotation)]);
    }

    public function destroy(Request $request, Game $game, int $moveIndex): JsonResponse
    {
        if ($game->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        GameAnnotation::where('game_id', $game->id)
            ->where('user_id', $request->user()->id)
            ->where('move_ply', $moveIndex)
            ->delete();

        return response()->json(['message' => 'Annotation deleted.']);
    }

    private function format(GameAnnotation $a): array
    {
        return [
            'id' => $a->id,
            'move_ply' => $a->move_ply,
            'note' => $a->note,
            'arrows' => $a->arrows_json ? json_decode($a->arrows_json, true) : [],
            'highlights' => $a->highlights_json ? json_decode($a->highlights_json, true) : [],
            'updated_at' => $a->updated_at?->toISOString(),
        ];
    }
}
