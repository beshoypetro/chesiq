<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DrillQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DrillQueueController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'game_id' => 'required|integer|exists:games,id',
            'move_ply' => 'required|integer|min:1',
            'fen' => 'required|string',
            'best_move' => 'nullable|string|max:10',
        ]);

        $user = $request->user();

        // Prevent duplicate entries for the same game/ply
        $existing = DrillQueue::where('user_id', $user->id)
            ->where('game_id', $data['game_id'])
            ->where('move_ply', $data['move_ply'])
            ->first();

        if ($existing) {
            return response()->json(['id' => $existing->id, 'message' => 'Already in drill queue.']);
        }

        $drill = DrillQueue::create([
            'user_id' => $user->id,
            'game_id' => $data['game_id'],
            'move_ply' => $data['move_ply'],
            'fen' => $data['fen'],
            'best_move' => $data['best_move'] ?? null,
            'added_at' => now(),
        ]);

        return response()->json(['id' => $drill->id], 201);
    }

    public function next(Request $request): JsonResponse
    {
        $user = $request->user();

        $drill = DrillQueue::where('user_id', $user->id)
            ->whereNull('solved_at')
            ->orderBy('added_at')
            ->first();

        if (!$drill) {
            return response()->json(['drill' => null]);
        }

        return response()->json([
            'drill' => [
                'id' => $drill->id,
                'game_id' => $drill->game_id,
                'move_ply' => $drill->move_ply,
                'fen' => $drill->fen,
                'best_move' => $drill->best_move,
                'added_at' => $drill->added_at,
            ],
            'pending_count' => DrillQueue::where('user_id', $user->id)->whereNull('solved_at')->count(),
        ]);
    }

    public function solve(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'correct' => 'required|boolean',
        ]);

        $user = $request->user();
        $drill = DrillQueue::where('id', $id)->where('user_id', $user->id)->firstOrFail();

        if ($data['correct']) {
            $drill->update(['solved_at' => now()]);
        }

        $remaining = DrillQueue::where('user_id', $user->id)->whereNull('solved_at')->count();

        return response()->json([
            'message' => $data['correct'] ? 'Marked as solved.' : 'Try again.',
            'remaining' => $remaining,
        ]);
    }

    public function count(Request $request): JsonResponse
    {
        $count = DrillQueue::where('user_id', $request->user()->id)
            ->whereNull('solved_at')
            ->count();

        return response()->json(['count' => $count]);
    }
}
